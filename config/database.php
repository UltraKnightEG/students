<?php
/**
 * إعدادات قاعدة البيانات - نظام إدارة حضور الطلاب
 * Database Configuration - Student Attendance Management System
 * 
 * @version 2.1
 * @author Manus AI Assistant
 * @date 2025-07-17
 */

// إعدادات قاعدة البيانات
define("DB_HOST", "localhost");
define("DB_NAME", "attendance_system"); // تم تغيير اسم قاعدة البيانات ليتوافق مع الملف المصحح
define("DB_USER", "root");
define("DB_PASS", ""); // في XAMPP، غالباً ما يكون المستخدم root بدون كلمة مرور افتراضياً
define("DB_CHARSET", "utf8mb4");

// إعدادات الأمان
define("ENCRYPTION_KEY", "your-32-character-secret-key-here"); // يجب تغييرها في الإنتاج
define("API_SECRET", "your-api-secret-key-here"); // يجب تغييرها في الإنتاج

// إعدادات النظام
define("TIMEZONE", "Asia/Riyadh");
define("DEFAULT_LANGUAGE", "ar");
define("SESSION_TIMEOUT", 3600); // ساعة واحدة

// إعدادات خدمة الواتساب
define("WHATSAPP_SERVICE_URL", "http://localhost:3000");
define("WHATSAPP_TIMEOUT", 30);

/**
 * فئة الاتصال بقاعدة البيانات
 */
class Database {
    private static $instance = null;
    private $connection; // هذا هو كائن PDO
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            

            
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            // لا ترمي استثناء هنا، فقط سجل الخطأ للسماح للسكريبت بالاستمرار في تسجيل الأخطاء الأخرى
            // أو يمكنك رمي استثناء مخصص بعد تسجيل الخطأ
            throw new Exception("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
        }
    }
    
    /**
     * الحصول على مثيل واحد من قاعدة البيانات
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * الحصول على الاتصال (كائن PDO)
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * تنفيذ استعلام مع معاملات
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage() . " SQL: " . $sql);
            throw new Exception("فشل في تنفيذ الاستعلام: " . $e->getMessage());
        }
    }
    
    /**
     * الحصول على سجل واحد
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * الحصول على جميع السجلات
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * إدراج سجل جديد والحصول على المعرف
     */
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->connection->lastInsertId();
    }
    
    /**
     * تحديث سجل والحصول على عدد الصفوف المتأثرة
     */
    public function update($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * حذف سجل والحصول على عدد الصفوف المتأثرة
     */
    public function delete($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * بدء معاملة
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * تأكيد المعاملة
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * إلغاء المعاملة
     */
    public function rollback() {
        return $this->connection->rollback();
    }
    
    /**
     * تشفير البيانات الحساسة
     */
    public static function encrypt($data) {
        $key = ENCRYPTION_KEY;
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * فك تشفير البيانات
     */
    public static function decrypt($data) {
        $key = ENCRYPTION_KEY;
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
    /**
     * إنشاء هاش للبحث
     */
    public static function createHash($data) {
        return hash('sha256', $data . ENCRYPTION_KEY);
    }
}

/**
 * فئة مساعدة للاستجابات
 */
class Response {
    /**
     * إرسال استجابة JSON
     */
    public static function json($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * إرسال استجابة نجاح
     */
    public static function success($data = null, $message = 'تم بنجاح') {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * إرسال استجابة خطأ
     */
    public static function error($message = 'حدث خطأ', $code = 400, $details = null) {
        self::json([
            'success' => false,
            'error' => $message,
            'code' => $code,
            'details' => $details,
            'timestamp' => date('Y-m-d H:i:s')
        ], $code);
    }
}

/**
 * فئة التحقق من الصحة
 */
class Validator {
    /**
     * التحقق من البريد الإلكتروني
     */
    public static function email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * التحقق من رقم الهاتف
     */
    public static function phone($phone) {
        return preg_match('/^[0-9+\-\s()]{10,20}$/', $phone);
    }
    
    /**
     * التحقق من الباركود
     */
    public static function barcode($barcode) {
        return preg_match('/^[A-Za-z0-9]{3,50}$/', $barcode);
    }
    
    /**
     * تنظيف النص
     */
    public static function sanitize($text) {
        return htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * التحقق من التاريخ
     */
    public static function date($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

// تعيين المنطقة الزمنية
date_default_timezone_set(TIMEZONE);

// تعيين ترميز UTF-8 (إذا كان mbstring متوفراً)
if (extension_loaded('mbstring')) {
    mb_internal_encoding('UTF-8');
    mb_http_output('UTF-8');
}

// معالجة الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// إنشاء مجلد السجلات إذا لم يكن موجوداً
if (!file_exists(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}
?>


