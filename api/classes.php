<?php
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$pdo = null;
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    Response::error('فشل الاتصال بقاعدة البيانات: ' . $e->getMessage(), 500);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($method) {
        case 'GET':
            handleGetClasses($pdo);
            break;
            
        case 'POST':
            handleCreateClass($pdo, $input);
            break;
            
        case 'PUT':
            handleUpdateClass($pdo, $input);
            break;
            
        case 'DELETE':
            handleDeleteClass($pdo, $input);
            break;
            
        default:
            Response::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Classes API Error: " . $e->getMessage());
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}

function handleGetClasses($pdo) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            getClassesList($pdo);
            break;
            
        case 'details':
            getClassDetails($pdo);
            break;
            
        case 'students':
            getClassStudents($pdo);
            break;
            
        case 'stats':
            getClassStats($pdo);
            break;
            
        default:
            Response::error('Invalid action', 400);
    }
}

function getClassesList($pdo) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));
    $search = $_GET['search'] ?? '';
    $grade_level = $_GET['grade_level'] ?? '';
    
    $offset = ($page - 1) * $limit;
    
    // بناء الاستعلام مع الفلترة
    $whereConditions = [];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(name LIKE ? OR description LIKE ? OR teacher_name LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($grade_level)) {
        $whereConditions[] = "grade_level = ?";
        $params[] = $grade_level;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // عدد الفصول الإجمالي
    $countQuery = "SELECT COUNT(*) as total FROM classes $whereClause";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalClasses = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // قائمة الفصول مع الإحصائيات
    $query = "
        SELECT 
            c.*,
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT sess.id) as total_sessions,
            COALESCE(AVG(attendance_stats.attendance_rate), 0) as avg_attendance_rate
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN sessions sess ON c.id = sess.class_id
        LEFT JOIN (
            SELECT 
                sess.class_id,
                (COUNT(a.id) * 100.0 / COUNT(DISTINCT s.id)) as attendance_rate
            FROM sessions sess
            LEFT JOIN students s ON sess.class_id = s.class_id
            LEFT JOIN attendance a ON sess.id = a.session_id AND s.id = a.student_id
            WHERE sess.status = 'completed'
            GROUP BY sess.id
        ) attendance_stats ON c.id = attendance_stats.class_id
        $whereClause
        GROUP BY c.id
        ORDER BY c.name
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    foreach ($classes as &$class) {
        $class['total_students'] = intval($class['total_students']);
        $class['total_sessions'] = intval($class['total_sessions']);
        $class['avg_attendance_rate'] = round(floatval($class['avg_attendance_rate']), 2);
        $class['capacity'] = intval($class['capacity']);
        $class['available_spots'] = max(0, $class['capacity'] - $class['total_students']);
    }
    
    Response::success([
        'classes' => $classes,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($totalClasses / $limit),
            'total_items' => intval($totalClasses),
            'items_per_page' => $limit
        ]
    ]);
}

function getClassDetails($pdo) {
    $classId = intval($_GET['id'] ?? 0);
    
    if ($classId <= 0) {
        Response::error('Invalid class ID', 400);
        return;
    }
    
    // تفاصيل الفصل
    $query = "
        SELECT 
            c.*,
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT sess.id) as total_sessions,
            COUNT(DISTINCT CASE WHEN sess.status = 'active' THEN sess.id END) as active_sessions,
            COUNT(DISTINCT CASE WHEN sess.status = 'completed' THEN sess.id END) as completed_sessions
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN sessions sess ON c.id = sess.class_id
        WHERE c.id = ?
        GROUP BY c.id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$class) {
        Response::error('Class not found', 404);
        return;
    }
    
    // إحصائيات الحضور للفصل
    $attendanceQuery = "
        SELECT 
            COUNT(DISTINCT a.student_id) as total_attendances,
            COUNT(DISTINCT s.id) as total_possible_attendances,
            COALESCE((COUNT(DISTINCT a.student_id) * 100.0 / NULLIF(COUNT(DISTINCT s.id), 0)), 0) as attendance_rate
        FROM sessions sess
        LEFT JOIN students s ON sess.class_id = s.class_id
        LEFT JOIN attendance a ON sess.id = a.session_id AND s.id = a.student_id
        WHERE sess.class_id = ? AND sess.status = 'completed'
    ";
    
    $stmt = $pdo->prepare($attendanceQuery);
    $stmt->execute([$classId]);
    $attendanceStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // آخر الجلسات
    $recentSessionsQuery = "
        SELECT 
            id, subject, date, start_time, end_time, status,
            (SELECT COUNT(*) FROM attendance WHERE session_id = sessions.id) as attendance_count
        FROM sessions 
        WHERE class_id = ? 
        ORDER BY date DESC, start_time DESC 
        LIMIT 5
    ";
    
    $stmt = $pdo->prepare($recentSessionsQuery);
    $stmt->execute([$classId]);
    $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    $class['total_students'] = intval($class['total_students']);
    $class['total_sessions'] = intval($class['total_sessions']);
    $class['active_sessions'] = intval($class['active_sessions']);
    $class['completed_sessions'] = intval($class['completed_sessions']);
    $class['capacity'] = intval($class['capacity']);
    $class['available_spots'] = max(0, $class['capacity'] - $class['total_students']);
    
    $class['attendance_stats'] = [
        'total_attendances' => intval($attendanceStats['total_attendances']),
        'total_possible_attendances' => intval($attendanceStats['total_possible_attendances']),
        'attendance_rate' => round(floatval($attendanceStats['attendance_rate']), 2)
    ];
    
    $class['recent_sessions'] = $recentSessions;
    
    Response::success($class);
}

function getClassStudents($pdo) {
    $classId = intval($_GET['id'] ?? 0);
    
    if ($classId <= 0) {
        Response::error('Invalid class ID', 400);
        return;
    }
    
    $query = "
        SELECT 
            s.*,
            COUNT(DISTINCT a.id) as total_attendances,
            COUNT(DISTINCT sess.id) as total_sessions,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT sess.id), 0)), 0) as attendance_rate,
            MAX(a.created_at) as last_attendance
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id
        LEFT JOIN sessions sess ON s.class_id = sess.class_id AND sess.status = 'completed'
        WHERE s.class_id = ?
        GROUP BY s.id
        ORDER BY s.name
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$classId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    foreach ($students as &$student) {
        $student['total_attendances'] = intval($student['total_attendances']);
        $student['total_sessions'] = intval($student['total_sessions']);
        $student['attendance_rate'] = round(floatval($student['attendance_rate']), 2);
        
        // فك تشفير رقم الهاتف
        if (!empty($student['parent_phone_encrypted'])) { // تم التعديل هنا
            $student['parent_phone'] = Database::decrypt($student['parent_phone_encrypted']); // تم التعديل هنا
        }
    }
    
    Response::success($students);
}

function getClassStats($pdo) {
    // إحصائيات عامة للفصول
    $statsQuery = "
        SELECT 
            COUNT(*) as total_classes,
            SUM(capacity) as total_capacity,
            COUNT(DISTINCT grade_level) as grade_levels,
            AVG(capacity) as avg_capacity
        FROM classes
    ";
    
    $stmt = $pdo->prepare($statsQuery);
    $stmt->execute();
    $generalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إحصائيات حسب المستوى الدراسي
    $gradeStatsQuery = "
        SELECT 
            grade_level,
            COUNT(*) as class_count,
            SUM(capacity) as total_capacity,
            COUNT(DISTINCT s.id) as total_students
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        GROUP BY grade_level
        ORDER BY grade_level
    ";
    
    $stmt = $pdo->prepare($gradeStatsQuery);
    $stmt->execute();
    $gradeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // أفضل الفصول حضوراً
    $topClassesQuery = "
        SELECT 
            c.name,
            c.grade_level,
            COUNT(DISTINCT a.student_id) as total_attendances,
            COUNT(DISTINCT s.id) as total_students,
            COALESCE((COUNT(DISTINCT a.student_id) * 100.0 / NULLIF(COUNT(DISTINCT s.id), 0)), 0) as attendance_rate
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND sess.status = 'completed'
        LEFT JOIN attendance a ON sess.id = a.session_id AND s.id = a.student_id
        GROUP BY c.id
        HAVING total_students > 0
        ORDER BY attendance_rate DESC
        LIMIT 5
    ";
    
    $stmt = $pdo->prepare($topClassesQuery);
    $stmt->execute();
    $topClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    foreach ($topClasses as &$class) {
        $class['attendance_rate'] = round(floatval($class['attendance_rate']), 2);
    }
    
    Response::success([
        'general' => [
            'total_classes' => intval($generalStats['total_classes']),
            'total_capacity' => intval($generalStats['total_capacity']),
            'grade_levels' => intval($generalStats['grade_levels']),
            'avg_capacity' => round(floatval($generalStats['avg_capacity']), 1)
        ],
        'by_grade' => $gradeStats,
        'top_classes' => $topClasses
    ]);
}

function handleCreateClass($pdo, $input) {
    // التحقق من صحة البيانات
    $validator = new Validator();
    $validator->required($input, ['name', 'grade_level', 'capacity']);
    $validator->string($input['name'], 'name', 2, 100);
    $validator->string($input['grade_level'], 'grade_level', 2, 50);
    $validator->integer($input['capacity'], 'capacity', 1, 100);
    
    if (!empty($input['description'])) {
        $validator->string($input['description'], 'description', 0, 500);
    }
    
    if (!empty($input['teacher_name'])) {
        $validator->string($input['teacher_name'], 'teacher_name', 2, 100);
    }
    
    if ($validator->hasErrors()) {
        Response::error('Validation failed', 400, $validator->getErrors());
        return;
    }
    
    // التحقق من عدم تكرار اسم الفصل
    $checkQuery = "SELECT id FROM classes WHERE name = ?";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$input['name']]);
    
    if ($stmt->fetch()) {
        Response::error('Class name already exists', 409);
        return;
    }
    
    // إنشاء الفصل الجديد
    $query = "
        INSERT INTO classes (name, description, grade_level, capacity, teacher_name, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ";
    
    $stmt = $pdo->prepare($query);
    $success = $stmt->execute([
        $input['name'],
        $input['description'] ?? '',
        $input['grade_level'],
        $input['capacity'],
        $input['teacher_name'] ?? ''
    ]);
    
    if ($success) {
        $classId = $pdo->lastInsertId();
        
        // جلب بيانات الفصل المُنشأ
        $selectQuery = "SELECT * FROM classes WHERE id = ?";
        $stmt = $pdo->prepare($selectQuery);
        $stmt->execute([$classId]);
        $newClass = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success($newClass, 'Class created successfully', 201);
    } else {
        Response::error('Failed to create class', 500);
    }
}

function handleUpdateClass($pdo, $input) {
    $classId = intval($input['id'] ?? 0);
    
    if ($classId <= 0) {
        Response::error('Invalid class ID', 400);
        return;
    }
    
    // التحقق من وجود الفصل
    $checkQuery = "SELECT id FROM classes WHERE id = ?";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$classId]);
    
    if (!$stmt->fetch()) {
        Response::error('Class not found', 404);
        return;
    }
    
    // التحقق من صحة البيانات
    $validator = new Validator();
    
    if (isset($input['name'])) {
        $validator->string($input['name'], 'name', 2, 100);
        
        // التحقق من عدم تكرار اسم الفصل
        $nameCheckQuery = "SELECT id FROM classes WHERE name = ? AND id != ?";
        $stmt = $pdo->prepare($nameCheckQuery);
        $stmt->execute([$input['name'], $classId]);
        
        if ($stmt->fetch()) {
            Response::error('Class name already exists', 409);
            return;
        }
    }
    
    if (isset($input['grade_level'])) {
        $validator->string($input['grade_level'], 'grade_level', 2, 50);
    }
    
    if (isset($input['capacity'])) {
        $validator->integer($input['capacity'], 'capacity', 1, 100);
    }
    
    if (isset($input['description'])) {
        $validator->string($input['description'], 'description', 0, 500);
    }
    
    if (isset($input['teacher_name'])) {
        $validator->string($input['teacher_name'], 'teacher_name', 0, 100);
    }
    
    if ($validator->hasErrors()) {
        Response::error('Validation failed', 400, $validator->getErrors());
        return;
    }
    
    // بناء استعلام التحديث
    $updateFields = [];
    $params = [];
    
    $allowedFields = ['name', 'description', 'grade_level', 'capacity', 'teacher_name'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    if (empty($updateFields)) {
        Response::error('No fields to update', 400);
        return;
    }
    
    $updateFields[] = "updated_at = NOW()";
    $params[] = $classId;
    
    $query = "UPDATE classes SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($query);
    $success = $stmt->execute($params);
    
    if ($success) {
        // جلب بيانات الفصل المُحدث
        $selectQuery = "SELECT * FROM classes WHERE id = ?";
        $stmt = $pdo->prepare($selectQuery);
        $stmt->execute([$classId]);
        $updatedClass = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success($updatedClass, 'Class updated successfully');
    } else {
        Response::error('Failed to update class', 500);
    }
}

function handleDeleteClass($pdo, $input) {
    $classId = intval($input['id'] ?? 0);
    
    if ($classId <= 0) {
        Response::error('Invalid class ID', 400);
        return;
    }
    
    // التحقق من وجود الفصل
    $checkQuery = "SELECT id FROM classes WHERE id = ?";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$classId]);
    
    if (!$stmt->fetch()) {
        Response::error('Class not found', 404);
        return;
    }
    
    // التحقق من وجود طلاب في الفصل
    $studentsQuery = "SELECT COUNT(*) as count FROM students WHERE class_id = ?";
    $stmt = $pdo->prepare($studentsQuery);
    $stmt->execute([$classId]);
    $studentCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($studentCount > 0) {
        Response::error('Cannot delete class with enrolled students', 409);
        return;
    }
    
    // التحقق من وجود جلسات للفصل
    $sessionsQuery = "SELECT COUNT(*) as count FROM sessions WHERE class_id = ?";
    $stmt = $pdo->prepare($sessionsQuery);
    $stmt->execute([$classId]);
    $sessionCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($sessionCount > 0) {
        Response::error('Cannot delete class with existing sessions', 409);
        return;
    }
    
    // حذف الفصل
    $deleteQuery = "DELETE FROM classes WHERE id = ?";
    $stmt = $pdo->prepare($deleteQuery);
    $success = $stmt->execute([$classId]);
    
    if ($success) {
        Response::success(null, 'Class deleted successfully');
    } else {
        Response::error('Failed to delete class', 500);
    }
}
?>


