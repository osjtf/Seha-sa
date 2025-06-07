<?php
/**
 * سكريبت متكامل لإنشاء جميع الجداول المطلوبة من الصفر متوافقة مع الكود الخرافي.
 */

$dbConfigs = [
    [
        'host' => 'ocvwlym0zv3tcn68.cbetxkdyhwsb.us-east-1.rds.amazonaws.com',
        'user' => 'smrg7ak77778emkb',
        'pass' => 'fw69cuijof4ahuhb',
        'name' => 'ygscnjzq8ueid5yz',
        'port' => 3306
    ],
    [
        'host' => 'c9cujduvu830eexs.cbetxkdyhwsb.us-east-1.rds.amazonaws.com',
        'user' => 'q2xjpqcepsmd4v12',
        'pass' => 'v8lcs6awp4vj9u28',
        'name' => 'cdidptf4q81rafg8',
        'port' => 3306
    ]
];

$defaultAdminUsername = 'osama';
$defaultAdminPasswordPlain = 'osama2030';

function resetAndCreateTables(array $config, $adminUsername, $adminPasswordPlain)
{
    $dbName = $config['name'];
    echo "<b>► معالجة قاعدة البيانات: {$dbName}</b><br>";

    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $dbName,
        $config['port']
    );
    if ($conn->connect_error) {
        echo "<span style='color:red;'>خطأ اتصال بـ {$dbName}: " . htmlspecialchars($conn->connect_error) . "</span><br><hr>";
        return;
    }
    $conn->set_charset('utf8mb4');

    // حذف كل الجداول أولاً
    $conn->query("SET FOREIGN_KEY_CHECKS = 0;");
    foreach (['leave_queries', 'sick_leaves', 'patients', 'doctors', 'admins'] as $tbl) {
        $conn->query("DROP TABLE IF EXISTS `{$tbl}`;");
    }
    $conn->query("SET FOREIGN_KEY_CHECKS = 1;");

    // جدول المرضى
    $conn->query("
        CREATE TABLE `patients` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `identity_number` VARCHAR(20) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // جدول الأطباء
    $conn->query("
        CREATE TABLE `doctors` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `title` VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // جدول الإجازات (الحديث المتكامل)
    $conn->query("
        CREATE TABLE `sick_leaves` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `service_code` VARCHAR(30) NOT NULL UNIQUE,
            `patient_id` INT NOT NULL,
            `doctor_id` INT NOT NULL,
            `issue_date` DATE NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `days_count` INT NOT NULL,
            `is_companion` TINYINT(1) NOT NULL DEFAULT 0,
            `companion_name` VARCHAR(100) DEFAULT NULL,
            `companion_relation` VARCHAR(100) DEFAULT NULL,
            `payment_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `deleted_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME DEFAULT NULL,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // سجل الاستعلامات
    $conn->query("
        CREATE TABLE `leave_queries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `leave_id` INT NOT NULL,
            `queried_at` DATETIME NOT NULL,
            `source` VARCHAR(20) NOT NULL DEFAULT 'external',
            INDEX (`leave_id`),
            CONSTRAINT `fk_leave_queries_leave`
              FOREIGN KEY (`leave_id`)
              REFERENCES `sick_leaves`(`id`)
              ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // جدول المشرفين
    $conn->query("
        CREATE TABLE `admins` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // إضافة المشرف الافتراضي
    $hashedPassword = password_hash($adminPasswordPlain, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO `admins` (`username`, `password`) VALUES (?, ?)");
    $stmt->bind_param("ss", $adminUsername, $hashedPassword);
    $stmt->execute();
    $stmt->close();

    echo "<span style='color:green;'>تم إنشاء جميع الجداول بنجاح مع إضافة المشرف الافتراضي.</span><br><hr>";
    $conn->close();
}

foreach ($dbConfigs as $cfg) {
    resetAndCreateTables($cfg, $defaultAdminUsername, $defaultAdminPasswordPlain);
}
echo "<b>انتهى إنشاء الجداول من الصفر. النظام الآن جاهز 100% ومتوافق مع جميع الكود الحديث.</b>";
