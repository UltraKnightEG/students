<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'config/database_sqlite.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختبار النظام مع SQLite</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        .warning { color: orange; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>اختبار نظام إدارة الحضور مع SQLite</h1>
    
    <h2>معلومات النظام</h2>
    <p class="info">إصدار PHP: <?php echo PHP_VERSION; ?></p>
    <p class="info">الوقت الحالي: <?php echo date('Y-m-d H:i:s'); ?></p>
    <p class="info">المنطقة الزمنية: <?php echo date_default_timezone_get(); ?></p>
    
    <h2>اختبار قاعدة البيانات SQLite</h2>
    <?php
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        echo "<p class='success'>✓ تم الاتصال بقاعدة البيانات SQLite بنجاح</p>";
        
        // فحص الجداول
        $tables = ['classes', 'students', 'sessions', 'attendance'];
        foreach ($tables as $table) {
            $stmt = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
            if ($stmt->rowCount() > 0) {
                // عدد السجلات
                $countStmt = $conn->query("SELECT COUNT(*) as count FROM `$table`");
                $count = $countStmt->fetch()['count'];
                echo "<p class='success'>✓ جدول $table موجود ($count سجل)</p>";
            } else {
                echo "<p class='error'>✗ جدول $table غير موجود</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>✗ خطأ في قاعدة البيانات: " . $e->getMessage() . "</p>";
    }
    ?>
    
    <h2>اختبار البيانات</h2>
    <?php
    try {
        // عرض الفصول
        echo "<h3>الفصول المتاحة:</h3>";
        $stmt = $conn->query("SELECT * FROM classes");
        $classes = $stmt->fetchAll();
        
        if ($classes) {
            echo "<table>";
            echo "<tr><th>ID</th><th>الاسم</th><th>المستوى</th><th>المدرس</th><th>السعة</th></tr>";
            foreach ($classes as $class) {
                echo "<tr>";
                echo "<td>{$class['id']}</td>";
                echo "<td>{$class['name']}</td>";
                echo "<td>{$class['grade_level']}</td>";
                echo "<td>{$class['teacher_name']}</td>";
                echo "<td>{$class['capacity']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>لا توجد فصول</p>";
        }
        
        // عرض الطلاب
        echo "<h3>الطلاب المسجلون:</h3>";
        $stmt = $conn->query("
            SELECT s.*, c.name as class_name 
            FROM students s 
            LEFT JOIN classes c ON s.class_id = c.id
        ");
        $students = $stmt->fetchAll();
        
        if ($students) {
            echo "<table>";
            echo "<tr><th>ID</th><th>الاسم</th><th>الباركود</th><th>الفصل</th><th>رقم ولي الأمر</th></tr>";
            foreach ($students as $student) {
                echo "<tr>";
                echo "<td>{$student['id']}</td>";
                echo "<td>{$student['name']}</td>";
                echo "<td>{$student['barcode']}</td>";
                echo "<td>{$student['class_name']}</td>";
                echo "<td>{$student['parent_phone']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>لا يوجد طلاب</p>";
        }
        
        // عرض الجلسات
        echo "<h3>الجلسات المتاحة:</h3>";
        $stmt = $conn->query("
            SELECT s.*, c.name as class_name 
            FROM sessions s 
            LEFT JOIN classes c ON s.class_id = c.id
        ");
        $sessions = $stmt->fetchAll();
        
        if ($sessions) {
            echo "<table>";
            echo "<tr><th>ID</th><th>المادة</th><th>الفصل</th><th>التاريخ</th><th>الوقت</th><th>الحالة</th></tr>";
            foreach ($sessions as $session) {
                echo "<tr>";
                echo "<td>{$session['id']}</td>";
                echo "<td>{$session['subject']}</td>";
                echo "<td>{$session['class_name']}</td>";
                echo "<td>{$session['date']}</td>";
                echo "<td>{$session['start_time']}</td>";
                echo "<td>{$session['status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>لا توجد جلسات</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>خطأ في عرض البيانات: " . $e->getMessage() . "</p>";
    }
    ?>
    
    <h2>اختبار APIs</h2>
    <?php
    $apis = [
        'students.php' => 'API إدارة الطلاب',
        'classes.php' => 'API إدارة الفصول',
        'sessions.php' => 'API إدارة الجلسات',
        'attendance.php' => 'API إدارة الحضور',
        'reports.php' => 'API التقارير',
        'dashboard.php' => 'API لوحة التحكم'
    ];
    
    foreach ($apis as $file => $description) {
        $filePath = __DIR__ . '/api/' . $file;
        
        if (file_exists($filePath)) {
            echo "<p class='success'>✓ $description موجود</p>";
        } else {
            echo "<p class='error'>✗ $description غير موجود</p>";
        }
    }
    ?>
    
    <h2>الروابط للاختبار</h2>
    <p><a href="dashboard.html" target="_blank">الواجهة الأمامية</a></p>
    <p><a href="api/students.php" target="_blank">API الطلاب</a></p>
    <p><a href="api/classes.php" target="_blank">API الفصول</a></p>
    <p><a href="api/sessions.php" target="_blank">API الجلسات</a></p>
    <p><a href="api/dashboard.php?action=overview" target="_blank">API لوحة التحكم</a></p>
    
    <h2>اختبار سريع للـ API</h2>
    <div id="apiTest">
        <button onclick="testAPI()">اختبار API الطلاب</button>
        <div id="apiResult"></div>
    </div>
    
    <script>
    async function testAPI() {
        try {
            const response = await fetch('api/students.php?action=list&limit=5');
            const data = await response.json();
            
            document.getElementById('apiResult').innerHTML = 
                '<h4>نتيجة اختبار API:</h4>' +
                '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
        } catch (error) {
            document.getElementById('apiResult').innerHTML = 
                '<p class="error">خطأ في اختبار API: ' + error.message + '</p>';
        }
    }
    </script>
    
</body>
</html>

