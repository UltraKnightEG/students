<?php
/**
 * إعدادات قاعدة البيانات SQLite
 * SQLite Database Configuration
 */

// إعدادات قاعدة البيانات
define('DB_TYPE', 'sqlite');
define('DB_PATH', __DIR__ . '/../database/attendance_system.db');

// إعدادات النظام
define('TIMEZONE', 'Asia/Riyadh');
define('ENCRYPTION_KEY', 'attendance_system_2024_secure_key_12345');

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

/**
 * فئة قاعدة البيانات SQLite
 */
class Database {
    private static $instance = null;
    private $connection = null;
    
    private function __construct() {
        try {
            // إنشاء مجلد قاعدة البيانات إذا لم يكن موجوداً
            $dbDir = dirname(DB_PATH);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            
            // الاتصال بقاعدة البيانات
            $this->connection = new PDO('sqlite:' . DB_PATH);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // تفعيل المفاتيح الخارجية
            $this->connection->exec('PRAGMA foreign_keys = ON');
            
            // إنشاء الجداول إذا لم تكن موجودة
            $this->createTables();
            
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw new Exception("خطأ في الاتصال بقاعدة البيانات");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    private function createTables() {
        $sql = "
        -- جدول الفصول
        CREATE TABLE IF NOT EXISTS classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            grade_level VARCHAR(50),
            capacity INTEGER DEFAULT 30,
            teacher_name VARCHAR(100),
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- جدول الطلاب
        CREATE TABLE IF NOT EXISTS students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) NOT NULL,
            barcode VARCHAR(50) UNIQUE NOT NULL,
            class_id INTEGER,
            parent_phone VARCHAR(20),
            parent_email VARCHAR(100),
            grade_level VARCHAR(50),
            date_of_birth DATE,
            address TEXT,
            emergency_contact VARCHAR(20),
            medical_notes TEXT,
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
        );
        
        -- جدول الجلسات
        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            subject VARCHAR(100) NOT NULL,
            description TEXT,
            date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME,
            status VARCHAR(20) DEFAULT 'scheduled',
            quiz_total_score INTEGER DEFAULT 10,
            teacher_id INTEGER,
            location VARCHAR(100),
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
        );
        
        -- جدول الحضور
        CREATE TABLE IF NOT EXISTS attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            attendance_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'present',
            teacher_rating INTEGER CHECK (teacher_rating >= 1 AND teacher_rating <= 5),
            quiz_score DECIMAL(5,2),
            behavior_notes TEXT,
            parent_notified BOOLEAN DEFAULT 0,
            notification_sent_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
            UNIQUE(student_id, session_id)
        );
        
        -- جدول المستخدمين
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            full_name VARCHAR(100),
            role VARCHAR(20) DEFAULT 'staff',
            is_active BOOLEAN DEFAULT 1,
            last_login DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        ";
        
        $this->connection->exec($sql);
        
        // إدراج بيانات تجريبية
        $this->insertSampleData();
    }
    
    private function insertSampleData() {
        // التحقق من وجود بيانات
        $stmt = $this->connection->query("SELECT COUNT(*) as count FROM classes");
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            // إدراج فصول تجريبية
            $this->connection->exec("
                INSERT INTO classes (name, description, grade_level, capacity, teacher_name) VALUES
                ('الصف الأول الابتدائي', 'فصل تجريبي للاختبار', 'الأول الابتدائي', 30, 'أحمد محمد'),
                ('الصف الثاني الابتدائي', 'فصل تجريبي للاختبار', 'الثاني الابتدائي', 28, 'فاطمة علي'),
                ('الصف الثالث الابتدائي', 'فصل تجريبي للاختبار', 'الثالث الابتدائي', 32, 'محمد سالم');
            ");
            
            // إدراج طلاب تجريبيين
            $this->connection->exec("
                INSERT INTO students (name, barcode, class_id, parent_phone, grade_level) VALUES
                ('عبدالله أحمد', 'STU001', 1, '966501234567', 'ابتدائي'),
                ('مريم محمد', 'STU002', 1, '966507654321', 'ابتدائي'),
                ('خالد سالم', 'STU003', 2, '966509876543', 'ابتدائي'),
                ('نورا علي', 'STU004', 2, '966502468135', 'ابتدائي'),
                ('يوسف عبدالرحمن', 'STU005', 3, '966508642097', 'ابتدائي');
            ");
            
            // إدراج جلسات تجريبية
            $this->connection->exec("
                INSERT INTO sessions (class_id, subject, description, date, start_time, status) VALUES
                (1, 'الرياضيات', 'جلسة تجريبية للاختبار', date('now'), '08:00:00', 'active'),
                (2, 'اللغة العربية', 'جلسة تجريبية للاختبار', date('now'), '09:00:00', 'scheduled'),
                (3, 'العلوم', 'جلسة تجريبية للاختبار', date('now'), '10:00:00', 'scheduled');
            ");
            
            // إدراج بعض بيانات الحضور
            $this->connection->exec("
                INSERT INTO attendance (student_id, session_id, status, teacher_rating, quiz_score) VALUES
                (1, 1, 'present', 5, 9.5),
                (2, 1, 'present', 4, 8.0),
                (3, 2, 'present', 5, 10.0);
            ");
        }
    }
}

/**
 * فئة الاستجابة الموحدة
 */
class Response {
    public static function success($message = 'تم بنجاح', $data = null, $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    public static function error($message = 'حدث خطأ', $data = null, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * فئة التحقق من صحة البيانات
 */
class Validator {
    public static function required($value, $fieldName) {
        if (empty($value) && $value !== '0') {
            throw new Exception("حقل $fieldName مطلوب");
        }
        return true;
    }
    
    public static function email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("البريد الإلكتروني غير صحيح");
        }
        return true;
    }
    
    public static function phone($phone) {
        $phone = preg_replace('/[^\d]/', '', $phone);
        if (strlen($phone) < 10) {
            throw new Exception("رقم الهاتف غير صحيح");
        }
        return true;
    }
    
    public static function numeric($value, $fieldName) {
        if (!is_numeric($value)) {
            throw new Exception("حقل $fieldName يجب أن يكون رقماً");
        }
        return true;
    }
}

/**
 * فئة التشفير
 */
class Encryption {
    public static function encrypt($data) {
        $key = ENCRYPTION_KEY;
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public static function decrypt($data) {
        $key = ENCRYPTION_KEY;
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
}
?>

