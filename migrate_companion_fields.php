<?php
$db_host = 'ocvwlym0zv3tcn68.cbetxkdyhwsb.us-east-1.rds.amazonaws.com';
$db_user = 'smrg7ak77778emkb';
$db_pass = 'fw69cuijof4ahuhb';
$db_name = 'ygscnjzq8ueid5yz';
$db_port = 3306;

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
if ($conn->connect_error) die("خطأ في الاتصال: " . $conn->connect_error);

$alter = [];
$alter[] = "ADD COLUMN is_companion TINYINT(1) DEFAULT 0";
$alter[] = "ADD COLUMN companion_name VARCHAR(100) DEFAULT NULL";
$alter[] = "ADD COLUMN companion_relation VARCHAR(100) DEFAULT NULL";

foreach ($alter as $stmt) {
    $sql = "ALTER TABLE sick_leaves $stmt";
    try {
        $conn->query($sql);
        echo "تم تنفيذ: $stmt<br>";
    } catch (mysqli_sql_exception $e) {
        if (strpos($e->getMessage(), "Duplicate column name") !== false) {
            echo "العمود موجود مسبقًا: $stmt<br>";
        } else {
            echo "خطأ: " . $e->getMessage() . "<br>";
        }
    }
}
$conn->close();
echo "انتهى التنفيذ. احذف هذا الملف.";
?>
