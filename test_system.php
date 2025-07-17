<?php
/**
 * ملف اختبار شامل لنظام إدارة حضور الطلاب
 * Comprehensive Test File for Student Attendance System
 */

require_once 'config/database.php';

// إعداد الاستجابة
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// معالجة طلبات OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class SystemTester {
    private $db;
    private $results = [];
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * تشغيل جميع الاختبارات
     */
    public function runAllTests() {
        $this->results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'tests' => []
        ];
        
        // اختبار قاعدة البيانات
        $this->testDatabase();
        
        // اختبار الجداول
        $this->testTables();
        
        // اختبار APIs
        $this->testAPIs();
        
        // اختبار البيانات التجريبية
        $this->testSampleData();
        
        // حساب النتيجة الإجمالية
        $this->calculateOverallResult();
        
        return $this->results;
    }
    
    /**
     * اختبار الاتصال بقاعدة البيانات
     */
    private function testDatabase() {
        $test = [
            'name' => 'Database Connection',
            'description' => 'اختبار الاتصال بقاعدة البيانات',
            'status' => 'failed',
            'message' => '',
            'details' => []
        ];
        
        try {
            if ($this->db) {
                $stmt = $this->db->query("SELECT 1");
                if ($stmt) {
                    $test['status'] = 'passed';
                    $test['message'] = 'تم الاتصال بقاعدة البيانات بنجاح';
                    
                    // معلومات إضافية
                    $version = $this->db->query("SELECT VERSION() as version")->fetch(PDO::FETCH_ASSOC);
                    $test['details']['mysql_version'] = $version['version'];
                    $test['details']['charset'] = $this->db->query("SELECT @@character_set_database as charset")->fetch(PDO::FETCH_ASSOC)['charset'];
                } else {
                    $test['message'] = 'فشل في تنفيذ استعلام الاختبار';
                }
            } else {
                $test['message'] = 'فشل في الاتصال بقاعدة البيانات';
            }
        } catch (Exception $e) {
            $test['message'] = 'خطأ في الاتصال: ' . $e->getMessage();
        }
        
        $this->results['tests']['database'] = $test;
    }
    
    /**
     * اختبار وجود الجداول المطلوبة
     */
    private function testTables() {
        $requiredTables = [
            'classes' => 'جدول الفصول',
            'students' => 'جدول الطلاب',
            'sessions' => 'جدول الجلسات',
            'attendance' => 'جدول الحضور',
            'users' => 'جدول المستخدمين'
        ];
        
        $test = [
            'name' => 'Database Tables',
            'description' => 'اختبار وجود الجداول المطلوبة',
            'status' => 'passed',
            'message' => '',
            'details' => []
        ];
        
        try {
            foreach ($requiredTables as $table => $description) {
                $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                
                if ($stmt->rowCount() > 0) {
                    $test['details'][$table] = [
                        'exists' => true,
                        'description' => $description
                    ];
                    
                    // عدد السجلات
                    $countStmt = $this->db->query("SELECT COUNT(*) as count FROM `$table`");
                    $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
                    $test['details'][$table]['record_count'] = $count;
                    
                } else {
                    $test['details'][$table] = [
                        'exists' => false,
                        'description' => $description
                    ];
                    $test['status'] = 'failed';
                }
            }
            
            if ($test['status'] === 'passed') {
                $test['message'] = 'جميع الجداول المطلوبة موجودة';
            } else {
                $test['message'] = 'بعض الجداول المطلوبة غير موجودة';
            }
            
        } catch (Exception $e) {
            $test['status'] = 'failed';
            $test['message'] = 'خطأ في فحص الجداول: ' . $e->getMessage();
        }
        
        $this->results['tests']['tables'] = $test;
    }
    
    /**
     * اختبار APIs
     */
    private function testAPIs() {
        $apis = [
            'students.php' => 'API إدارة الطلاب',
            'classes.php' => 'API إدارة الفصول',
            'sessions.php' => 'API إدارة الجلسات',
            'attendance.php' => 'API إدارة الحضور',
            'reports.php' => 'API التقارير',
            'dashboard.php' => 'API لوحة التحكم'
        ];
        
        $test = [
            'name' => 'API Endpoints',
            'description' => 'اختبار توفر ملفات APIs',
            'status' => 'passed',
            'message' => '',
            'details' => []
        ];
        
        foreach ($apis as $file => $description) {
            $filePath = __DIR__ . '/api/' . $file;
            
            if (file_exists($filePath)) {
                $test['details'][$file] = [
                    'exists' => true,
                    'description' => $description,
                    'size' => filesize($filePath),
                    'modified' => date('Y-m-d H:i:s', filemtime($filePath))
                ];
            } else {
                $test['details'][$file] = [
                    'exists' => false,
                    'description' => $description
                ];
                $test['status'] = 'failed';
            }
        }
        
        if ($test['status'] === 'passed') {
            $test['message'] = 'جميع ملفات APIs موجودة';
        } else {
            $test['message'] = 'بعض ملفات APIs غير موجودة';
        }
        
        $this->results['tests']['apis'] = $test;
    }
    
    /**
     * اختبار البيانات التجريبية
     */
    private function testSampleData() {
        $test = [
            'name' => 'Sample Data',
            'description' => 'اختبار وجود البيانات التجريبية',
            'status' => 'passed',
            'message' => '',
            'details' => []
        ];
        
        try {
            // فحص الفصول
            $classesCount = $this->db->query("SELECT COUNT(*) as count FROM classes")->fetch(PDO::FETCH_ASSOC)['count'];
            $test['details']['classes_count'] = $classesCount;
            
            // فحص الطلاب
            $studentsCount = $this->db->query("SELECT COUNT(*) as count FROM students")->fetch(PDO::FETCH_ASSOC)['count'];
            $test['details']['students_count'] = $studentsCount;
            
            // فحص الجلسات
            $sessionsCount = $this->db->query("SELECT COUNT(*) as count FROM sessions")->fetch(PDO::FETCH_ASSOC)['count'];
            $test['details']['sessions_count'] = $sessionsCount;
            
            // فحص الحضور
            $attendanceCount = $this->db->query("SELECT COUNT(*) as count FROM attendance")->fetch(PDO::FETCH_ASSOC)['count'];
            $test['details']['attendance_count'] = $attendanceCount;
            
            // التحقق من وجود بيانات كافية للاختبار
            if ($classesCount > 0 && $studentsCount > 0) {
                $test['message'] = 'توجد بيانات تجريبية كافية للاختبار';
            } else {
                $test['status'] = 'warning';
                $test['message'] = 'لا توجد بيانات تجريبية كافية - يُنصح بإضافة بيانات للاختبار';
            }
            
        } catch (Exception $e) {
            $test['status'] = 'failed';
            $test['message'] = 'خطأ في فحص البيانات: ' . $e->getMessage();
        }
        
        $this->results['tests']['sample_data'] = $test;
    }
    
    /**
     * حساب النتيجة الإجمالية
     */
    private function calculateOverallResult() {
        $totalTests = count($this->results['tests']);
        $passedTests = 0;
        $failedTests = 0;
        $warningTests = 0;
        
        foreach ($this->results['tests'] as $test) {
            switch ($test['status']) {
                case 'passed':
                    $passedTests++;
                    break;
                case 'failed':
                    $failedTests++;
                    break;
                case 'warning':
                    $warningTests++;
                    break;
            }
        }
        
        $this->results['summary'] = [
            'total_tests' => $totalTests,
            'passed' => $passedTests,
            'failed' => $failedTests,
            'warnings' => $warningTests,
            'success_rate' => round(($passedTests / $totalTests) * 100, 2)
        ];
        
        if ($failedTests === 0) {
            $this->results['overall_status'] = 'passed';
            $this->results['overall_message'] = 'جميع الاختبارات نجحت! النظام جاهز للاستخدام.';
        } else {
            $this->results['overall_status'] = 'failed';
            $this->results['overall_message'] = "فشل في $failedTests اختبار(ات). يجب إصلاح المشاكل قبل الاستخدام.";
        }
    }
    
    /**
     * إنشاء بيانات تجريبية للاختبار
     */
    public function createSampleData() {
        try {
            $this->db->beginTransaction();
            
            // إنشاء فصول تجريبية
            $classes = [
                ['name' => 'الصف الأول الابتدائي', 'grade_level' => 'الأول الابتدائي', 'capacity' => 30, 'teacher_name' => 'أحمد محمد'],
                ['name' => 'الصف الثاني الابتدائي', 'grade_level' => 'الثاني الابتدائي', 'capacity' => 28, 'teacher_name' => 'فاطمة علي'],
                ['name' => 'الصف الثالث الابتدائي', 'grade_level' => 'الثالث الابتدائي', 'capacity' => 32, 'teacher_name' => 'محمد سالم']
            ];
            
            foreach ($classes as $class) {
                $stmt = $this->db->prepare("
                    INSERT INTO classes (name, description, grade_level, capacity, teacher_name, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE name = VALUES(name)
                ");
                $stmt->execute([
                    $class['name'],
                    'فصل تجريبي للاختبار',
                    $class['grade_level'],
                    $class['capacity'],
                    $class['teacher_name']
                ]);
            }
            
            // إنشاء طلاب تجريبيين
            $students = [
                ['name' => 'عبدالله أحمد', 'barcode' => 'STU001', 'class_id' => 1, 'parent_phone' => '966501234567'],
                ['name' => 'مريم محمد', 'barcode' => 'STU002', 'class_id' => 1, 'parent_phone' => '966507654321'],
                ['name' => 'خالد سالم', 'barcode' => 'STU003', 'class_id' => 2, 'parent_phone' => '966509876543'],
                ['name' => 'نورا علي', 'barcode' => 'STU004', 'class_id' => 2, 'parent_phone' => '966502468135'],
                ['name' => 'يوسف عبدالرحمن', 'barcode' => 'STU005', 'class_id' => 3, 'parent_phone' => '966508642097']
            ];
            
            foreach ($students as $student) {
                $stmt = $this->db->prepare("
                    INSERT INTO students (name, barcode, class_id, parent_phone, grade_level, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE name = VALUES(name)
                ");
                $stmt->execute([
                    $student['name'],
                    $student['barcode'],
                    $student['class_id'],
                    $student['parent_phone'],
                    'ابتدائي'
                ]);
            }
            
            // إنشاء جلسة تجريبية
            $stmt = $this->db->prepare("
                INSERT INTO sessions (class_id, subject, description, date, start_time, status, created_at) 
                VALUES (1, 'الرياضيات', 'جلسة تجريبية للاختبار', CURDATE(), '08:00:00', 'active', NOW())
                ON DUPLICATE KEY UPDATE subject = VALUES(subject)
            ");
            $stmt->execute();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'تم إنشاء البيانات التجريبية بنجاح'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'خطأ في إنشاء البيانات التجريبية: ' . $e->getMessage()
            ];
        }
    }
}

// معالجة الطلبات
$action = $_GET['action'] ?? 'test';

$tester = new SystemTester();

switch ($action) {
    case 'test':
        $results = $tester->runAllTests();
        Response::success('تم تشغيل الاختبارات بنجاح', $results);
        break;
        
    case 'create_sample':
        $result = $tester->createSampleData();
        if ($result['success']) {
            Response::success($result['message']);
        } else {
            Response::error($result['message']);
        }
        break;
        
    default:
        Response::error('إجراء غير صحيح');
}
?>

