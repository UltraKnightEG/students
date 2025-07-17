<?php
/**
 * API إدارة الطلاب - نظام إدارة حضور الطلاب
 * Students Management API - Student Attendance Management System
 * 
 * @version 2.0
 * @author Manus AI Assistant
 * @date 2025-07-17
 */

require_once '../config/database.php';

// الحصول على اتصال قاعدة البيانات
$pdo = null;
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    Response::error('فشل الاتصال بقاعدة البيانات: ' . $e->getMessage(), 500);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        case 'PUT':
            handlePut($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
            break;
        default:
            Response::error('طريقة غير مدعومة', 405);
    }
    
} catch (Exception $e) {
    error_log("Students API Error: " . $e->getMessage());
    Response::error('حدث خطأ في الخادم', 500);
}

/**
 * معالجة طلبات GET
 */
function handleGet($pdo) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            getStudentsList($pdo);
            break;
        case 'get':
            getStudent($pdo);
            break;
        case 'search':
            searchStudents($pdo);
            break;
        case 'stats':
            getStudentsStats($pdo);
            break;
        case 'attendance_summary':
            getAttendanceSummary($pdo);
            break;
        default:
            Response::error('إجراء غير صحيح');
    }
}

/**
 * معالجة طلبات POST
 */
function handlePost($pdo) {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'create';
    
    switch ($action) {
        case 'create':
            createStudent($pdo);
            break;
        case 'bulk_import':
            bulkImportStudents($pdo);
            break;
        case 'quick_register':
            quickRegisterStudent($pdo);
            break;
        default:
            Response::error('إجراء غير صحيح');
    }
}

/**
 * معالجة طلبات PUT
 */
function handlePut($pdo) {
    parse_str(file_get_contents("php://input"), $data);
    updateStudent($pdo, $data);
}

/**
 * معالجة طلبات DELETE
 */
function handleDelete($pdo) {
    $student_id = $_GET['student_id'] ?? null;
    if (!$student_id) {
        Response::error('معرف الطالب مطلوب');
    }
    deleteStudent($pdo, $student_id);
}

/**
 * الحصول على قائمة الطلاب
 */
function getStudentsList($pdo) {
    $class = $_GET['class'] ?? '';
    $status = $_GET['status'] ?? 'active';
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    $where_conditions = ["s.status = :status"];
    $params = ['status' => $status];
    
    if (!empty($class)) {
        $where_conditions[] = "s.class_id = :class_id"; // تم التعديل هنا
        $params['class_id'] = $class; // تم التعديل هنا
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // الحصول على العدد الإجمالي
    $count_sql = "SELECT COUNT(*) as total FROM students s WHERE $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // الحصول على البيانات
    $sql = "
        SELECT 
            s.id,
            s.barcode as student_id, // استخدام barcode كـ student_id
            s.name,
            s.class_id, // تم التعديل هنا
            s.email,
            s.date_of_birth,
            s.address,
            s.emergency_contact,
            s.medical_notes,
            s.status,
            s.enrollment_date,
            s.photo_path,
            s.created_at,
            s.updated_at,
            c.name as class_name,
            c.grade_level,
            (SELECT COUNT(*) FROM attendance a 
             JOIN sessions sess ON a.session_id = sess.id 
             WHERE a.student_id = s.id AND sess.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as attendance_last_30_days,
            (SELECT COUNT(*) FROM sessions sess 
             WHERE sess.class_id = s.class_id AND sess.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as total_sessions_last_30_days
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE $where_clause
        ORDER BY s.name ASC
        LIMIT :limit OFFSET :offset
    ";
    
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // حساب معدل الحضور لكل طالب
    foreach ($students as &$student) {
        if ($student['total_sessions_last_30_days'] > 0) {
            $student['attendance_rate'] = round(
                ($student['attendance_last_30_days'] / $student['total_sessions_last_30_days']) * 100, 
                2
            );
        } else {
            $student['attendance_rate'] = 0;
        }
        
        // إخفاء البيانات الحساسة
        unset($student['parent_phone_encrypted']);
        unset($student['emergency_phone_encrypted']);
        unset($student['parent_phone_hash']);
    }
    
    Response::success([
        'students' => $students,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_records' => $total,
            'per_page' => $limit
        ]
    ]);
}

/**
 * الحصول على بيانات طالب واحد
 */
function getStudent($pdo) {
    $student_barcode = $_GET['student_id'] ?? ''; // استخدام student_id كـ barcode
    
    if (empty($student_barcode)) {
        Response::error('معرف الطالب (الباركود) مطلوب');
    }
    
    $sql = "
        SELECT 
            s.*,
            c.name as class_name,
            c.grade_level,
            c.room_number,
            (SELECT COUNT(*) FROM attendance a 
             WHERE a.student_id = s.id) as total_attendance,
            (SELECT COUNT(*) FROM sessions sess 
             WHERE sess.class_id = s.class_id) as total_sessions,
            (SELECT AVG(a.quiz_score) FROM attendance a 
             WHERE a.student_id = s.id AND a.quiz_score IS NOT NULL) as avg_quiz_score,
            (SELECT COUNT(*) FROM attendance a 
             WHERE a.student_id = s.id AND a.status = 'late') as late_count
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.barcode = :barcode
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['barcode' => $student_barcode]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        Response::error('الطالب غير موجود', 404);
    }
    
    // حساب معدل الحضور
    if ($student['total_sessions'] > 0) {
        $student['attendance_rate'] = round(
            ($student['total_attendance'] / $student['total_sessions']) * 100, 
            2
        );
    } else {
        $student['attendance_rate'] = 0;
    }
    
    // إخفاء البيانات الحساسة
    unset($student['parent_phone_encrypted']);
    unset($student['emergency_phone_encrypted']);
    unset($student['parent_phone_hash']);
    
    Response::success($student);
}

/**
 * البحث في الطلاب
 */
function searchStudents($pdo) {
    $query = $_GET['q'] ?? '';
    $class = $_GET['class'] ?? '';
    $limit = (int)($_GET['limit'] ?? 20);
    
    if (empty($query)) {
        Response::error('نص البحث مطلوب');
    }
    
    $where_conditions = [
        "s.status = 'active'",
        "(s.name LIKE :query OR s.barcode LIKE :query OR s.email LIKE :query)" // استخدام barcode
    ];
    $params = ['query' => "%$query%"];
    
    if (!empty($class)) {
        $where_conditions[] = "s.class_id = :class_id"; // تم التعديل هنا
        $params['class_id'] = $class; // تم التعديل هنا
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT 
            s.barcode as student_id, // استخدام barcode كـ student_id
            s.name,
            s.class_id, // تم التعديل هنا
            s.email,
            s.status,
            c.name as class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE $where_clause
        ORDER BY s.name ASC
        LIMIT :limit
    ";
    
    $params['limit'] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    Response::success($students);
}

/**
 * الحصول على إحصائيات الطلاب
 */
function getStudentsStats($pdo) {
    $stats = [];
    
    // إجمالي الطلاب
    $total_students = $pdo->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);
    $stats['total_students'] = $total_students['count'];
    
    // الطلاب حسب الفصل
    $by_class = $pdo->query("
        SELECT 
            s.class_id,
            COUNT(*) as count,
            c.name as class_name,
            c.grade_level
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.status = 'active'
        GROUP BY s.class_id
        ORDER BY c.grade_level, c.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $stats['by_class'] = $by_class;
    
    // الطلاب الجدد هذا الشهر
    $new_this_month = $pdo->query("
        SELECT COUNT(*) as count 
        FROM students 
        WHERE status = 'active' 
        AND enrollment_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['new_this_month'] = $new_this_month['count'];
    
    // معدل الحضور العام
    $attendance_rate = $pdo->query("
        SELECT 
            ROUND(
                (COUNT(DISTINCT a.student_id, a.session_id) / 
                 NULLIF(COUNT(DISTINCT s.id) * (SELECT COUNT(*) FROM sessions WHERE status = 'completed' AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0)) * 100, 
                2
            ) as rate
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id
        WHERE s.status = 'active'
        AND EXISTS (SELECT 1 FROM sessions WHERE status = 'completed' AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['overall_attendance_rate'] = $attendance_rate['rate'] ?? 0;
    
    Response::success($stats);
}

/**
 * الحصول على ملخص الحضور
 */
function getAttendanceSummary($pdo) {
    $student_barcode = $_GET['student_id'] ?? ''; // استخدام student_id كـ barcode
    $start_date = $_GET['start_date'] ?? date('Y-m-01'); // بداية الشهر الحالي
    $end_date = $_GET['end_date'] ?? date('Y-m-d'); // اليوم
    
    if (empty($student_barcode)) {
        Response::error('معرف الطالب (الباركود) مطلوب');
    }
    
    // الحصول على id الطالب من الباركود
    $student_id_row = $pdo->prepare("SELECT id, class_id FROM students WHERE barcode = :barcode");
    $student_id_row->execute(['barcode' => $student_barcode]);
    $student_data = $student_id_row->fetch(PDO::FETCH_ASSOC);

    if (!$student_data) {
        Response::error('الطالب غير موجود', 404);
    }
    $student_db_id = $student_data['id'];
    $student_class_id = $student_data['class_id'];

    $sql = "
        SELECT 
            sess.date as session_date,
            sess.subject as session_name,
            COALESCE(a.status, 'absent') as attendance_status,
            a.attendance_time,
            a.teacher_rating as teacher_evaluation,
            a.quiz_score,
            a.max_quiz_score,
            a.participation_score,
            a.behavior_score,
            a.homework_status,
            a.notes,
            a.late_minutes
        FROM sessions sess
        LEFT JOIN attendance a ON sess.id = a.session_id AND a.student_id = :student_db_id
        WHERE sess.class_id = :student_class_id
        AND sess.date BETWEEN :start_date AND :end_date
        ORDER BY sess.date DESC, sess.start_time DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'student_db_id' => $student_db_id,
        'student_class_id' => $student_class_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // حساب الإحصائيات
    $total_sessions = count($summary);
    $present_count = 0;
    $late_count = 0;
    $absent_count = 0;
    $total_quiz_score = 0;
    $quiz_count = 0;
    
    foreach ($summary as $session) {
        switch ($session['attendance_status']) {
            case 'present':
                $present_count++;
                break;
            case 'late':
                $late_count++;
                break;
            case 'absent':
                $absent_count++;
                break;
        }
        
        if ($session['quiz_score'] !== null) {
            $total_quiz_score += $session['quiz_score'];
            $quiz_count++;
        }
    }
    
    $stats = [
        'total_sessions' => $total_sessions,
        'present_count' => $present_count,
        'late_count' => $late_count,
        'absent_count' => $absent_count,
        'attendance_rate' => $total_sessions > 0 ? round(($present_count + $late_count) / $total_sessions * 100, 2) : 0,
        'average_quiz_score' => $quiz_count > 0 ? round($total_quiz_score / $quiz_count, 2) : null
    ];
    
    Response::success([
        'summary' => $summary,
        'stats' => $stats,
        'period' => [
            'start_date' => $start_date,
            'end_date' => $end_date
        ]
    ]);
}

/**
 * إنشاء طالب جديد
 */
function createStudent($pdo) {
    $required_fields = ['barcode', 'name', 'class_id']; // تم التعديل هنا
    $data = [];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            Response::error("الحقل '$field' مطلوب");
        }
        $data[$field] = Validator::sanitize($_POST[$field]);
    }
    
    // التحقق من صحة الباركود
    if (!Validator::barcode($data['barcode'])) {
        Response::error('رقم الباركود غير صحيح');
    }
    
    // الحقول الاختيارية
    $optional_fields = ['email', 'date_of_birth', 'address', 'emergency_contact', 'medical_notes', 'parent_phone'];
    foreach ($optional_fields as $field) {
        if (isset($_POST[$field]) && !empty($_POST[$field])) {
            $data[$field] = Validator::sanitize($_POST[$field]);
        }
    }
    
    // التحقق من البريد الإلكتروني
    if (isset($data['email']) && !Validator::email($data['email'])) {
        Response::error('البريد الإلكتروني غير صحيح');
    }
    
    // التحقق من التاريخ
    if (isset($data['date_of_birth']) && !Validator::date($data['date_of_birth'])) {
        Response::error('تاريخ الميلاد غير صحيح');
    }
    
    try {
        $pdo->beginTransaction();
        
        // التحقق من عدم وجود الطالب مسبقاً باستخدام الباركود
        $existing = $pdo->prepare(
            "SELECT barcode FROM students WHERE barcode = :barcode",
        );
        $existing->execute(['barcode' => $data['barcode']]);
        
        if ($existing->fetch(PDO::FETCH_ASSOC)) {
            Response::error('رقم الباركود موجود مسبقاً');
        }
        
        // تشفير رقم الهاتف إذا كان موجوداً
        $parent_phone_encrypted = null;
        $parent_phone_hash = null;
        if (isset($data['parent_phone'])) {
            if (!Validator::phone($data['parent_phone'])) {
                Response::error('رقم هاتف ولي الأمر غير صحيح');
            }
            $parent_phone_encrypted = Database::encrypt($data['parent_phone']);
            $parent_phone_hash = Database::createHash($data['parent_phone']);
        }
        
        // تشفير رقم الطوارئ إذا كان موجوداً
        $emergency_phone_encrypted = null;
        if (isset($data['emergency_contact'])) { // تم التعديل هنا
            if (!Validator::phone($data['emergency_contact'])) { // تم التعديل هنا
                Response::error('رقم هاتف الطوارئ غير صحيح');
            }
            $emergency_phone_encrypted = Database::encrypt($data['emergency_contact']); // تم التعديل هنا
        }
        
        // إدراج الطالب
        $sql = "
            INSERT INTO students (
                barcode, name, class_id, email, date_of_birth, address, 
                emergency_contact, medical_notes, parent_phone_encrypted, 
                parent_phone_hash, emergency_phone_encrypted, status, 
                enrollment_date, created_at
            ) VALUES (
                :barcode, :name, :class_id, :email, :date_of_birth, :address,
                :emergency_contact, :medical_notes, :parent_phone_encrypted,
                :parent_phone_hash, :emergency_phone_encrypted, 'active',
                CURDATE(), NOW()
            )
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'barcode' => $data['barcode'],
            'name' => $data['name'],
            'class_id' => $data['class_id'],
            'email' => $data['email'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'address' => $data['address'] ?? null,
            'emergency_contact' => $data['emergency_contact'] ?? null,
            'medical_notes' => $data['medical_notes'] ?? null,
            'parent_phone_encrypted' => $parent_phone_encrypted,
            'parent_phone_hash' => $parent_phone_hash,
            'emergency_phone_encrypted' => $emergency_phone_encrypted
        ]);
        
        $student_db_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        Response::success([
            'barcode' => $data['barcode'],
            'name' => $data['name'],
            'class_id' => $data['class_id'],
            'db_id' => $student_db_id
        ], 'تم إنشاء الطالب بنجاح');
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Create student error: " . $e->getMessage());
        Response::error('فشل في إنشاء الطالب: ' . $e->getMessage());
    }
}

/**
 * التسجيل السريع للطالب
 */
function quickRegisterStudent($pdo) {
    $barcode = $_POST['barcode'] ?? ''; // استخدام barcode
    $name = $_POST['name'] ?? '';
    $class_id = $_POST['class_id'] ?? ''; // استخدام class_id
    $session_id = $_POST['session_id'] ?? null;
    
    if (empty($barcode) || empty($name) || empty($class_id)) {
        Response::error('البيانات الأساسية مطلوبة');
    }
    
    try {
        $pdo->beginTransaction();
        
        // التحقق من عدم وجود الطالب
        $existing = $pdo->prepare(
            "SELECT barcode FROM students WHERE barcode = :barcode",
        );
        $existing->execute(['barcode' => $barcode]);
        
        if ($existing->fetch(PDO::FETCH_ASSOC)) {
            Response::error('رقم الباركود موجود مسبقاً');
        }
        
        // إدراج في جدول التسجيل السريع
        $quick_reg_sql = "
            INSERT INTO quick_registration (
                barcode, name, class_id, session_id, status, created_at
            ) VALUES (
                :barcode, :name, :class_id, :session_id, 'pending', NOW()
            )
        ";
        
        $stmt = $pdo->prepare($quick_reg_sql);
        $stmt->execute([
            'barcode' => $barcode,
            'name' => $name,
            'class_id' => $class_id,
            'session_id' => $session_id
        ]);
        $quick_reg_id = $pdo->lastInsertId();
        
        // التحقق من إعدادات الموافقة التلقائية
        $auto_approve_setting = $pdo->prepare(
            "SELECT setting_value FROM system_settings 
             WHERE category = 'attendance' AND setting_key = 'auto_approve_quick_registration'"
        );
        $auto_approve_setting->execute();
        $auto_approve = $auto_approve_setting->fetch(PDO::FETCH_ASSOC);
        
        if ($auto_approve && $auto_approve['setting_value'] === 'true') {
            // الموافقة التلقائية
            $student_sql = "
                INSERT INTO students (
                    barcode, name, class_id, status, enrollment_date, created_at
                ) VALUES (
                    :barcode, :name, :class_id, 'active', CURDATE(), NOW()
                )
            ";
            
            $stmt = $pdo->prepare($student_sql);
            $stmt->execute([
                'barcode' => $barcode,
                'name' => $name,
                'class_id' => $class_id
            ]);
            
            // تحديث حالة التسجيل السريع
            $stmt = $pdo->prepare(
                "UPDATE quick_registration SET status = 'auto_approved', approved_at = NOW() WHERE id = :id"
            );
            $stmt->execute(['id' => $quick_reg_id]);
            
            $pdo->commit();
            
            Response::success([
                'barcode' => $barcode,
                'name' => $name,
                'class_id' => $class_id,
                'status' => 'approved'
            ], 'تم تسجيل الطالب تلقائياً');
        } else {
            $pdo->commit();
            
            Response::success([
                'barcode' => $barcode,
                'name' => $name,
                'class_id' => $class_id,
                'status' => 'pending'
            ], 'تم إرسال طلب التسجيل للمراجعة');
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Quick register error: " . $e->getMessage());
        Response::error('فشل في التسجيل السريع: ' . $e->getMessage());
    }
}

/**
 * تحديث بيانات الطالب
 */
function updateStudent($pdo, $data) {
    $barcode = $data['barcode'] ?? ''; // استخدام barcode
    
    if (empty($barcode)) {
        Response::error('معرف الطالب (الباركود) مطلوب');
    }
    
    // التحقق من وجود الطالب
    $existing = $pdo->prepare(
        "SELECT * FROM students WHERE barcode = :barcode",
    );
    $existing->execute(['barcode' => $barcode]);
    $existing_student = $existing->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing_student) {
        Response::error('الطالب غير موجود', 404);
    }
    
    try {
        $pdo->beginTransaction();
        
        $update_fields = [];
        $params = ['barcode' => $barcode];
        
        // الحقول القابلة للتحديث
        $updatable_fields = ['name', 'class_id', 'email', 'date_of_birth', 'address', 'emergency_contact', 'medical_notes', 'status']; // تم التعديل هنا
        
        foreach ($updatable_fields as $field) {
            if (isset($data[$field])) {
                $update_fields[] = "$field = :$field";
                $params[$field] = Validator::sanitize($data[$field]);
            }
        }
        
        // التحقق من البريد الإلكتروني
        if (isset($data['email']) && !empty($data['email']) && !Validator::email($data['email'])) {
            Response::error('البريد الإلكتروني غير صحيح');
        }
        
        // التحقق من التاريخ
        if (isset($data['date_of_birth']) && !empty($data['date_of_birth']) && !Validator::date($data['date_of_birth'])) {
            Response::error('تاريخ الميلاد غير صحيح');
        }
        
        // تحديث رقم الهاتف إذا كان موجوداً
        if (isset($data['parent_phone'])) {
            if (!empty($data['parent_phone'])) {
                if (!Validator::phone($data['parent_phone'])) {
                    Response::error('رقم هاتف ولي الأمر غير صحيح');
                }
                $update_fields[] = "parent_phone_encrypted = :parent_phone_encrypted";
                $update_fields[] = "parent_phone_hash = :parent_phone_hash";
                $params['parent_phone_encrypted'] = Database::encrypt($data['parent_phone']);
                $params['parent_phone_hash'] = Database::createHash($data['parent_phone']);
            } else {
                $update_fields[] = "parent_phone_encrypted = NULL";
                $update_fields[] = "parent_phone_hash = NULL";
            }
        }
        
        if (empty($update_fields)) {
            Response::error('لا توجد بيانات للتحديث');
        }
        
        $update_fields[] = "updated_at = NOW()";
        
        $sql = "UPDATE students SET " . implode(', ', $update_fields) . " WHERE barcode = :barcode";
        
        $stmt = $pdo->prepare($sql);
        $affected_rows = $stmt->execute($params);
        
        $pdo->commit();
        
        if ($affected_rows > 0) {
            Response::success(['barcode' => $barcode], 'تم تحديث بيانات الطالب بنجاح');
        } else {
            Response::error('لم يتم تحديث أي بيانات');
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Update student error: " . $e->getMessage());
        Response::error('فشل في تحديث بيانات الطالب: ' . $e->getMessage());
    }
}

/**
 * حذف الطالب
 */
function deleteStudent($pdo, $barcode) { // استخدام barcode
    try {
        $pdo->beginTransaction();
        
        // التحقق من وجود الطالب
        $existing = $pdo->prepare(
            "SELECT barcode FROM students WHERE barcode = :barcode",
        );
        $existing->execute(['barcode' => $barcode]);
        
        if (!$existing->fetch(PDO::FETCH_ASSOC)) {
            Response::error('الطالب غير موجود', 404);
        }
        
        // تحديث الحالة بدلاً من الحذف الفعلي
        $stmt = $pdo->prepare(
            "UPDATE students SET status = 'inactive', updated_at = NOW() WHERE barcode = :barcode"
        );
        $affected_rows = $stmt->execute(['barcode' => $barcode]);
        
        $pdo->commit();
        
        if ($affected_rows > 0) {
            Response::success(['barcode' => $barcode], 'تم حذف الطالب بنجاح');
        } else {
            Response::error('فشل في حذف الطالب');
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Delete student error: " . $e->getMessage());
        Response::error('فشل في حذف الطالب: ' . $e->getMessage());
    }
}
?>


