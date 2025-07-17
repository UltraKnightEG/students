<?php
echo "<!DOCTYPE html>";
echo "<html lang='ar' dir='rtl'>";
echo "<head><meta charset='UTF-8'><title>اختبار أساسي</title></head>";
echo "<body>";
echo "<h1>اختبار أساسي للنظام</h1>";
echo "<p>PHP يعمل بشكل صحيح!</p>";
echo "<p>الوقت الحالي: " . date('Y-m-d H:i:s') . "</p>";

// اختبار SQLite
try {
    $db = new PDO('sqlite:test.db');
    echo "<p style='color: green;'>✓ SQLite يعمل بشكل صحيح</p>";
    
    // إنشاء جدول تجريبي
    $db->exec("CREATE TABLE IF NOT EXISTS test (id INTEGER PRIMARY KEY, name TEXT)");
    $db->exec("INSERT OR REPLACE INTO test (id, name) VALUES (1, 'اختبار')");
    
    $stmt = $db->query("SELECT * FROM test");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "<p style='color: green;'>✓ قاعدة البيانات تعمل: " . $result['name'] . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ خطأ في SQLite: " . $e->getMessage() . "</p>";
}

echo "<h2>الملفات الموجودة:</h2>";
$files = ['config/database_sqlite.php', 'api/students.php', 'dashboard.html'];
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "<p style='color: green;'>✓ $file موجود</p>";
    } else {
        echo "<p style='color: red;'>✗ $file غير موجود</p>";
    }
}

echo "</body></html>";
?>

