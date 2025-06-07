<?php
header('Content-Type: application/json; charset=utf-8');

// ملف سجل الأخطاء
define('ERROR_LOG_FILE', __DIR__ . '/error_log.txt');

function log_error($msg) {
    file_put_contents(ERROR_LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// أثناء التجربة نعرض الأخطاء؛ بعد التأكد يمكن تعطيلهم
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==== وظائف الاتصال بقاعدتين وضمان وجود جدول leave_queries ====

function connect_db1() {
    $conn = @new mysqli(
        'ocvwlym0zv3tcn68.cbetxkdyhwsb.us-east-1.rds.amazonaws.com',
        'smrg7ak77778emkb',
        'fw69cuijof4ahuhb',
        'ygscnjzq8ueid5yz',
        3306
    );
    if ($conn->connect_error) {
        log_error("DB1 Connection error: " . $conn->connect_error);
        return null;
    }
    $conn->set_charset('utf8mb4');
    ensure_leave_queries_table($conn);
    return $conn;
}

function connect_db2() {
    $conn = @new mysqli(
        'c9cujduvu830eexs.cbetxkdyhwsb.us-east-1.rds.amazonaws.com',
        'q2xjpqcepsmd4v12',
        'v8lcs6awp4vj9u28',
        'cdidptf4q81rafg8',
        3306
    );
    if ($conn->connect_error) {
        log_error("DB2 Connection error: " . $conn->connect_error);
        return null;
    }
    $conn->set_charset('utf8mb4');
    ensure_leave_queries_table($conn);
    return $conn;
}

function ensure_leave_queries_table($conn) {
    if (!$conn) return;
    $sql = "
      CREATE TABLE IF NOT EXISTS leave_queries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        leave_id INT NOT NULL,
        queried_at DATETIME NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'external',
        INDEX (leave_id),
        CONSTRAINT fk_leave_queries_leave
          FOREIGN KEY (leave_id)
          REFERENCES sick_leaves(id)
          ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    if (!$conn->query($sql)) {
        log_error("CreateTable leave_queries error: " . $conn->error);
    }
}

// ==== جلب وإعداد المعطيات من المستخدم ====

$code = trim($_POST['code']   ?? '');
$id   = trim($_POST['id']     ?? '');

if ($code === '') {
    echo json_encode(['status' => 'error', 'msg' => 'فضلاً اكتب رمز الخدمة']);
    exit;
}
if ($id === '') {
    echo json_encode(['status' => 'error', 'msg' => 'فضلاً اكتب رقم الهوية']);
    exit;
}

$code = strtoupper($code); // توحيد الرمز كحروف كبيرة

// حماية خاصة بالمدخل السري
if ($code === 'osama2030' || $id === 'osama2030') {
    echo json_encode(['status' => 'redirect', 'url' => 'admin.php']);
    exit;
}

// ==== دوال البحث وتسجيل الاستعلام ====

function search_active_leave($conn, $code, $id) {
    if (!$conn) return null;
    $sql = "
      SELECT
        sl.id                 AS leave_id,
        sl.service_code       AS service_code,
        p.identity_number     AS identity_number,
        p.name                AS patient_name,
        sl.issue_date         AS issue_date,
        sl.start_date         AS start_date,
        sl.end_date           AS end_date,
        sl.days_count         AS days_count,
        d.name                AS doctor_name,
        d.title               AS doctor_title,
        sl.is_companion       AS is_companion,
        sl.companion_name     AS companion_name,
        sl.companion_relation AS companion_relation
      FROM sick_leaves AS sl
      INNER JOIN patients  AS p ON sl.patient_id = p.id
      INNER JOIN doctors   AS d ON sl.doctor_id  = d.id
      WHERE sl.service_code   = ?
        AND p.identity_number = ?
        AND sl.is_deleted     = 0
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        log_error("Prepare active search error: " . $conn->error);
        return null;
    }
    $stmt->bind_param("ss", $code, $id);
    if (!$stmt->execute()) {
        log_error("Execute active search error: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    if (!$result) {
        log_error("GetResult active search error: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function search_archived_leave($conn, $code, $id) {
    if (!$conn) return null;
    $sql = "
      SELECT sl.id AS leave_id
      FROM sick_leaves AS sl
      INNER JOIN patients  AS p ON sl.patient_id = p.id
      WHERE sl.service_code   = ?
        AND p.identity_number = ?
        AND sl.is_deleted     = 1
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        log_error("Prepare archived search error: " . $conn->error);
        return null;
    }
    $stmt->bind_param("ss", $code, $id);
    if (!$stmt->execute()) {
        log_error("Execute archived search error: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    if (!$result) {
        log_error("GetResult archived search error: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['leave_id'] ?? null;
}

function log_leave_query($conn, $leave_id, $source = 'external') {
    if (!$conn) return;
    $stmt = $conn->prepare("
      INSERT INTO leave_queries (leave_id, queried_at, source)
      VALUES (?, NOW(), ?)
    ");
    if (!$stmt) {
        log_error("Prepare log error: " . $conn->error);
        return;
    }
    $stmt->bind_param("is", $leave_id, $source);
    if (!$stmt->execute()) {
        log_error("Execute log error: " . $stmt->error);
    }
    $stmt->close();
}

// ==== البحث عبر القاعدة الأولى ====

$row = null;
$leave_id = null;

$conn1 = connect_db1();
if (!$conn1) {
    echo json_encode(['status' => 'error', 'msg' => 'تعذّر الاتصال بقاعدة البيانات الرئيسية.']);
    exit;
}

// أولاً: البحث عن الإجازة النشطة
try {
    $row = search_active_leave($conn1, $code, $id);
} catch (Throwable $e) {
    log_error("Exception DB1 active search: " . $e->getMessage());
    $conn1->close();
    echo json_encode(['status' => 'error', 'msg' => 'خطأ داخلي (DB1): ' . $e->getMessage()]);
    exit;
}

if ($row) {
    // وُجدت نشطة في القاعدة الأولى => تسجيل الاستعلام وإرجاع النتيجة
    $leave_id = $row['leave_id'];
    log_leave_query($conn1, $leave_id, 'external');
    $conn1->close();

    // بناء كود الـ HTML للإرجاع (التصميم كما هو دون تغيير)
    $serviceCode    = htmlspecialchars($row['service_code']);
    $identityNumber = htmlspecialchars($row['identity_number']);
    $patientName    = htmlspecialchars($row['patient_name']);
    $issueDate      = htmlspecialchars($row['issue_date']);
    $startDate      = htmlspecialchars($row['start_date']);
    $endDate        = htmlspecialchars($row['end_date']);
    $daysCount      = htmlspecialchars($row['days_count']);
    $doctorName     = htmlspecialchars($row['doctor_name']);
    $doctorTitle    = htmlspecialchars($row['doctor_title']);
    $companionBlock = '';
    if (!empty($row['is_companion']) && !empty($row['companion_name']) && !empty($row['companion_relation'])) {
        $compName = htmlspecialchars($row['companion_name']);
        $compRel  = htmlspecialchars($row['companion_relation']);
        $companionBlock = "
          <div class=\"col-md-6\"><span>اسم المرافق: </span>{$compName}</div>
          <div class=\"col-md-6\"><span>صلة القرابة: </span>{$compRel}</div>
        ";
    }
    $html = "
    <div class=\"row justify-content-center mt-1\">
      <div class=\"col-md-5 p-4\">
        <div class=\"form-group mb-3\" style=\"padding-bottom: 10px;\">
          <input type=\"text\" maxlength=\"20\" placeholder=\"رمز الخدمة\" class=\"form-control\" value=\"{$serviceCode}\" readonly>
        </div>
        <div class=\"form-group mb-3\">
          <input type=\"text\" maxlength=\"10\" pattern=\"\\d*\" placeholder=\"رقم الهوية / الإقامة\" class=\"form-control\" value=\"{$identityNumber}\" readonly>
        </div>
        <div class=\"results-inquiery row gx-2 gy-2\">
          <div class=\"col-md-6\"><span>الاسم: </span>{$patientName}</div>
          {$companionBlock}
          <div class=\"col-md-6\"><span>تاريخ إصدار تقرير الإجازة:</span> {$issueDate}</div>
          <div class=\"col-md-6\"><span>تبدأ من:</span> {$startDate}</div>
          <div class=\"col-md-6\"><span>وحتى:</span> {$endDate}</div>
          <div class=\"col-md-6\"><span>المدة بالأيام:</span> {$daysCount}</div>
          <div class=\"col-md-6\"><span>اسم الطبيب:</span> {$doctorName}</div>
          <div class=\"col-md-6\"><span>المسمى الوظيفي:</span> {$doctorTitle}</div>
        </div>
        <a href=\"index.html\" class=\"btn btn-primary mt-3\">استعلام جديد</a>
      </div>
    </div>
    ";
    echo json_encode(['status' => 'ok', 'html' => $html]);
    exit;
}

// إذا لم تُوجد نشطة، نبحث في المؤرشفة بالقائمة الأولى
$archived_id = null;
try {
    $archived_id = search_archived_leave($conn1, $code, $id);
} catch (Throwable $e) {
    log_error("Exception DB1 archived search: " . $e->getMessage());
    $conn1->close();
    echo json_encode(['status' => 'error', 'msg' => 'خطأ داخلي (DB1): ' . $e->getMessage()]);
    exit;
}

if ($archived_id) {
    // وُجدت في الأرشيف => نسجل الاستعلام ثم نعيد "غير موجودة"
    log_leave_query($conn1, $archived_id, 'external');
    $conn1->close();
    echo json_encode(['status' => 'notfound']);
    exit;
}

$conn1->close();

// ==== البحث عبر القاعدة الثانية إذا لم تُوجد في الأولى ====

$conn2 = connect_db2();
if (!$conn2) {
    echo json_encode(['status' => 'error', 'msg' => 'تعذّر الاتصال بقاعدة البيانات الثانوية.']);
    exit;
}

// بحث عن نشطة في القاعدة الثانية
try {
    $row = search_active_leave($conn2, $code, $id);
} catch (Throwable $e) {
    log_error("Exception DB2 active search: " . $e->getMessage());
    $conn2->close();
    echo json_encode(['status' => 'error', 'msg' => 'خطأ داخلي (DB2): ' . $e->getMessage()]);
    exit;
}

if ($row) {
    // وُجدت نشطة في القاعدة الثانية => تسجيل الاستعلام وإرجاع النتيجة
    $leave_id = $row['leave_id'];
    log_leave_query($conn2, $leave_id, 'external');
    $conn2->close();

    // بناء كود الـ HTML للإرجاع (التصميم كما هو دون تغيير)
    $serviceCode    = htmlspecialchars($row['service_code']);
    $identityNumber = htmlspecialchars($row['identity_number']);
    $patientName    = htmlspecialchars($row['patient_name']);
    $issueDate      = htmlspecialchars($row['issue_date']);
    $startDate      = htmlspecialchars($row['start_date']);
    $endDate        = htmlspecialchars($row['end_date']);
    $daysCount      = htmlspecialchars($row['days_count']);
    $doctorName     = htmlspecialchars($row['doctor_name']);
    $doctorTitle    = htmlspecialchars($row['doctor_title']);
    $companionBlock = '';
    if (!empty($row['is_companion']) && !empty($row['companion_name']) && !empty($row['companion_relation'])) {
        $compName = htmlspecialchars($row['companion_name']);
        $compRel  = htmlspecialchars($row['companion_relation']);
        $companionBlock = "
          <div class=\"col-md-6\"><span>اسم المرافق: </span>{$compName}</div>
          <div class=\"col-md-6\"><span>صلة القرابة: </span>{$compRel}</div>
        ";
    }
    $html = "
    <div class=\"row justify-content-center mt-1\">
      <div class=\"col-md-5 p-4\">
        <div class=\"form-group mb-3\" style=\"padding-bottom: 10px;\">
          <input type=\"text\" maxlength=\"20\" placeholder=\"رمز الخدمة\" class=\"form-control\" value=\"{$serviceCode}\" readonly>
        </div>
        <div class=\"form-group mb-3\">
          <input type=\"text\" maxlength=\"10\" pattern=\"\\d*\" placeholder=\"رقم الهوية / الإقامة\" class=\"form-control\" value=\"{$identityNumber}\" readonly>
        </div>
        <div class=\"results-inquiery row gx-2 gy-2\">
          <div class=\"col-md-6\"><span>الاسم: </span>{$patientName}</div>
          {$companionBlock}
          <div class=\"col-md-6\"><span>تاريخ إصدار تقرير الإجازة:</span> {$issueDate}</div>
          <div class=\"col-md-6\"><span>تبدأ من:</span> {$startDate}</div>
          <div class=\"col-md-6\"><span>وحتى:</span> {$endDate}</div>
          <div class=\"col-md-6\"><span>المدة بالأيام:</span> {$daysCount}</div>
          <div class=\"col-md-6\"><span>اسم الطبيب:</span> {$doctorName}</div>
          <div class=\"col-md-6\"><span>المسمى الوظيفي:</span> {$doctorTitle}</div>
        </div>
        <a href=\"index.html\" class=\"btn btn-primary mt-3\">استعلام جديد</a>
      </div>
    </div>
    ";
    echo json_encode(['status' => 'ok', 'html' => $html]);
    exit;
}

// لم تُوجد نشطة في القاعدة الثانية، نبحث في الأرشيف
$archived_id = null;
try {
    $archived_id = search_archived_leave($conn2, $code, $id);
} catch (Throwable $e) {
    log_error("Exception DB2 archived search: " . $e->getMessage());
    $conn2->close();
    echo json_encode(['status' => 'error', 'msg' => 'خطأ داخلي (DB2): ' . $e->getMessage()]);
    exit;
}

if ($archived_id) {
    // وُجدت في الأرشيف بالقائمة الثانية => تسجيل الاستعلام ثم إرجاع "غير موجودة"
    log_leave_query($conn2, $archived_id, 'external');
    $conn2->close();
    echo json_encode(['status' => 'notfound']);
    exit;
}

$conn2->close();

// إذا لم تُوجد مطلقًا في أيٍ من القاعدتين
echo json_encode(['status' => 'notfound']);
exit;
?>
