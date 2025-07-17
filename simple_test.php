<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختبار بسيط للنظام</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
    </style>
</head>
<body>
    <h1>اختبار بسيط لنظام إدارة الحضور</h1>
    
    <h2>معلومات PHP</h2>
    <p class="info">إصدار PHP: <?php echo PHP_VERSION; ?></p>
    <p class="info">الوقت الحالي: <?php echo date('Y-m-d H:i:s'); ?></p>
    
    <h2>اختبار الامتدادات المطلوبة</h2>
    <?php
    $extensions = ['pdo', 'pdo_mysql', 'json', 'curl'];
    foreach ($extensions as $ext) {
        if (extension_loaded($ext)) {
            echo "<p class='success'>✓ امتداد $ext متوفر</p>";
        } else {
            echo "<p class='error'>✗ امتداد $ext غير متوفر</p>";
        }
    }
    ?>
    
    <h2>اختبار الملفات</h2>
    <?php
    $files = [
        'config/database.php' => 'ملف إعدادات قاعدة البيانات',
        'api/students.php' => 'API الطلاب',
        'api/classes.php' => 'API الفصول',
        'dashboard.html' => 'الواجهة الأمامية'
    ];
    
    foreach ($files as $file => $description) {
        if (file_exists($file)) {
            echo "<p class='success'>✓ $description موجود</p>";
        } else {
            echo "<p class='error'>✗ $description غير موجود</p>";
        }
    }
    ?>
    
    <h2>اختبار قاعدة البيانات</h2>
    <?php
    try {
        // محاولة الاتصال بقاعدة البيانات بدون استخدام فئة Database
        $host = 'localhost';
        $dbname = 'attendance_system';
        $username = 'root';
        $password = '';
        
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
        
        echo "<p class='success'>✓ تم الاتصال بقاعدة البيانات بنجاح</p>";
        
        // فحص الجداول
        $tables = ['classes', 'students', 'sessions', 'attendance'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "<p class='success'>✓ جدول $table موجود</p>";
            } else {
                echo "<p class='error'>✗ جدول $table غير موجود</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>✗ خطأ في قاعدة البيانات: " . $e->getMessage() . "</p>";
    }
    ?>
    
    <h2>الروابط</h2>
    <p><a href="dashboard.html">الواجهة الأمامية</a></p>
    <p><a href="api/students.php">API الطلاب</a></p>
    <p><a href="api/classes.php">API الفصول</a></p>
    
</body>
</html>

