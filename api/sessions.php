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
            handleGetSessions($pdo);
            break;
            
        case 'POST':
            handleCreateSession($pdo, $input);
            break;
            
        case 'PUT':
            handleUpdateSession($pdo, $input);
            break;
            
        case 'DELETE':
            handleDeleteSession($pdo, $input);
            break;
            
        default:
            Response::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Sessions API Error: " . $e->getMessage());
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}

function handleGetSessions($pdo) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            getSessionsList($pdo);
            break;
            
        case 'details':
            getSessionDetails($pdo);
            break;
            
        case 'active':
            getActiveSessions($pdo);
            break;
            
        case 'attendance':
            getSessionAttendance($pdo);
            break;
            
        case 'stats':
            getSessionStats($pdo);
            break;
            
        default:
            Response::error('Invalid action', 400);
    }
}

function getSessionsList($pdo) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));
    $search = $_GET['search'] ?? '';
    $class_id = $_GET['class_id'] ?? '';
    $status = $_GET['status'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    
    $offset = ($page - 1) * $limit;
    
    // بناء الاستعلام مع الفلترة
    $whereConditions = [];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(s.subject LIKE ? OR s.description LIKE ? OR c.name LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($class_id)) {
        $whereConditions[] = "s.class_id = ?";
        $params[] = $class_id;
    }
    
    if (!empty($status)) {
        $whereConditions[] = "s.status = ?";
        $params[] = $status;
    }
    
    if (!empty($date_from)) {
        $whereConditions[] = "s.date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $whereConditions[] = "s.date <= ?";
        $params[] = $date_to;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // عدد الجلسات الإجمالي
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM sessions s 
        LEFT JOIN classes c ON s.class_id = c.id 
        $whereClause
    ";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalSessions = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // قائمة الجلسات مع الإحصائيات
    $query = "
        SELECT 
            s.*,
            c.name as class_name,
            c.grade_level,
            COUNT(DISTINCT st.id) as total_students,
            COUNT(DISTINCT a.id) as present_count,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT st.id), 0)), 0) as attendance_rate
        FROM sessions s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN students st ON c.id = st.class_id
        LEFT JOIN attendance a ON s.id = a.session_id AND st.id = a.student_id
        $whereClause
        GROUP BY s.id
        ORDER BY s.date DESC, s.start_time DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    foreach ($sessions as &$session) {
        $session['total_students'] = intval($session['total_students']);
        $session['present_count'] = intval($session['present_count']);
        $session['absent_count'] = $session['total_students'] - $session['present_count'];
        $session['attendance_rate'] = round(floatval($session['attendance_rate']), 2);
        $session['quiz_total_score'] = intval($session['quiz_total_score']);
    }
    
    Response::success([
        'sessions' => $sessions,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($totalSessions / $limit),
            'total_items' => intval($totalSessions),
            'items_per_page' => $limit
        ]
    ]);
}

function getSessionDetails($pdo) {
    $sessionId = intval($_GET['id'] ?? 0);
    
    if ($sessionId <= 0) {
        Response::error('Invalid session ID', 400);
        return;
    }
    
    // تفاصيل الجلسة
    $query = "
        SELECT 
            s.*,
            c.name as class_name,
            c.grade_level,
            c.capacity,
            COUNT(DISTINCT st.id) as total_students,
            COUNT(DISTINCT a.id) as present_count,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT st.id), 0)), 0) as attendance_rate,
            AVG(CASE WHEN a.quiz_score IS NOT NULL THEN a.quiz_score END) as avg_quiz_score
        FROM sessions s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN students st ON c.id = st.class_id
        LEFT JOIN attendance a ON s.id = a.session_id AND st.id = a.student_id
        WHERE s.id = ?
        GROUP BY s.id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        Response::error('Session not found', 404);
        return;
    }
    
    // قائمة الحضور
    $attendanceQuery = "
        SELECT 
            st.id as student_id,
            st.name as student_name,
            st.barcode,
            a.id as attendance_id,
            a.attendance_time,
            a.teacher_rating,
            a.quiz_score,
            a.participation_rating,
            a.behavior_rating,
            a.homework_status,
            a.notes,
            CASE WHEN a.id IS NOT NULL THEN 'present' ELSE 'absent' END as status
        FROM students st
        LEFT JOIN attendance a ON st.id = a.student_id AND a.session_id = ?
        WHERE st.class_id = ?
        ORDER BY st.name
    ";
    
    $stmt = $pdo->prepare($attendanceQuery);
    $stmt->execute([$sessionId, $session['class_id']]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    $session['total_students'] = intval($session['total_students']);
    $session['present_count'] = intval($session['present_count']);
    $session['absent_count'] = $session['total_students'] - $session['present_count'];
    $session['attendance_rate'] = round(floatval($session['attendance_rate']), 2);
    $session['avg_quiz_score'] = round(floatval($session['avg_quiz_score']), 2);
    $session['quiz_total_score'] = intval($session['quiz_total_score']);
    
    $session['attendance'] = $attendance;
    
    Response::success($session);
}

function getActiveSessions($pdo) {
    $query = "
        SELECT 
            s.*,
            c.name as class_name,
            c.grade_level,
            COUNT(DISTINCT st.id) as total_students,
            COUNT(DISTINCT a.id) as present_count
        FROM sessions s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN students st ON c.id = st.class_id
        LEFT JOIN attendance a ON s.id = a.session_id AND st.id = a.student_id
        WHERE s.status = 'active'
        GROUP BY s.id
        ORDER BY s.date DESC, s.start_time DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    foreach ($sessions as &$session) {
        $session['total_students'] = intval($session['total_students']);
        $session['present_count'] = intval($session['present_count']);
        $session['absent_count'] = $session['total_students'] - $session['present_count'];
        $session['attendance_rate'] = $session['total_students'] > 0 ? 
            round(($session['present_count'] * 100.0) / $session['total_students'], 2) : 0;
    }
    
    Response::success($sessions);
}

function getSessionAttendance($pdo) {
    $sessionId = intval($_GET['id'] ?? 0);
    
    if ($sessionId <= 0) {
        Response::error('Invalid session ID', 400);
        return;
    }
    
    $query = "
        SELECT 
            st.id as student_id,
            st.name as student_name,
            st.barcode,
            st.parent_phone_encrypted,
            a.id as attendance_id,
            a.attendance_time,
            a.teacher_rating,
            a.quiz_score,
            a.participation_rating,
            a.behavior_rating,
            a.homework_status,
            a.notes,
            CASE WHEN a.id IS NOT NULL THEN 'present' ELSE 'absent' END as status
        FROM students st
        LEFT JOIN attendance a ON st.id = a.student_id AND a.session_id = ?
        WHERE st.class_id = (SELECT class_id FROM sessions WHERE id = ?)
        ORDER BY st.name
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$sessionId, $sessionId]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // فك تشفير أرقام الهواتف
    foreach ($attendance as &$record) {
        if (!empty($record['parent_phone_encrypted'])) {
            $record['parent_phone'] = Database::decrypt($record['parent_phone_encrypted']);
        }
    }
    
    Response::success($attendance);
}

function getSessionStats($pdo) {
    // إحصائيات عامة للجلسات
    $statsQuery = "
        SELECT 
            COUNT(*) as total_sessions,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_sessions,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_sessions,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_sessions,
            COUNT(CASE WHEN date = CURDATE() THEN 1 END) as today_sessions
        FROM sessions
    ";
    
    $stmt = $pdo->prepare($statsQuery);
    $stmt->execute();
    $generalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إحصائيات الحضور
    $attendanceStatsQuery = "
        SELECT 
            COUNT(DISTINCT a.student_id) as total_attendances,
            COUNT(DISTINCT s.id) as sessions_with_attendance,
            AVG(attendance_rate.rate) as avg_attendance_rate
        FROM sessions s
        LEFT JOIN attendance a ON s.id = a.session_id
        LEFT JOIN (
            SELECT 
                sess.id,
                (COUNT(a.id) * 100.0 / COUNT(DISTINCT st.id)) as rate
            FROM sessions sess
            LEFT JOIN students st ON sess.class_id = st.class_id
            LEFT JOIN attendance a ON sess.id = a.session_id AND st.id = a.student_id
            WHERE sess.status = 'completed'
            GROUP BY sess.id
        ) attendance_rate ON s.id = attendance_rate.id
        WHERE s.status = 'completed'
    ";
    
    $stmt = $pdo->prepare($attendanceStatsQuery);
    $stmt->execute();
    $attendanceStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إحصائيات حسب الفصل
    $classStatsQuery = "
        SELECT 
            c.name as class_name,
            COUNT(s.id) as session_count,
            COUNT(CASE WHEN s.status = 'completed' THEN 1 END) as completed_sessions,
            AVG(attendance_rate.rate) as avg_attendance_rate
        FROM classes c
        LEFT JOIN sessions s ON c.id = s.class_id
        LEFT JOIN (
            SELECT 
                sess.id,
                (COUNT(a.id) * 100.0 / COUNT(DISTINCT st.id)) as rate
            FROM sessions sess
            LEFT JOIN students st ON sess.class_id = st.class_id
            LEFT JOIN attendance a ON sess.id = a.session_id AND st.id = a.student_id
            WHERE sess.status = 'completed'
            GROUP BY sess.id
        ) attendance_rate ON s.id = attendance_rate.id
        GROUP BY c.id
        ORDER BY avg_attendance_rate DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($classStatsQuery);
    $stmt->execute();
    $classStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    foreach ($classStats as &$class) {
        $class['avg_attendance_rate'] = round(floatval($class['avg_attendance_rate']), 2);
    }
    
    Response::success([
        'general' => [
            'total_sessions' => intval($generalStats['total_sessions']),
            'active_sessions' => intval($generalStats['active_sessions']),
            'completed_sessions' => intval($generalStats['completed_sessions']),
            'cancelled_sessions' => intval($generalStats['cancelled_sessions']),
            'today_sessions' => intval($generalStats['today_sessions'])
        ],
        'attendance' => [
            'total_attendances' => intval($attendanceStats['total_attendances']),
            'sessions_with_attendance' => intval($attendanceStats['sessions_with_attendance']),
            'avg_attendance_rate' => round(floatval($attendanceStats['avg_attendance_rate']), 2)
        ],
        'by_class' => $classStats
    ]);
}

function handleCreateSession($pdo, $input) {
    // التحقق من صحة البيانات
    $validator = new Validator();
    $validator->required($input, ['class_id', 'subject', 'date', 'start_time']);
    $validator->integer($input['class_id'], 'class_id', 1);
    $validator->string($input['subject'], 'subject', 2, 100);
    $validator->date($input['date'], 'date');
    $validator->time($input['start_time'], 'start_time');
    
    if (!empty($input['end_time'])) {
        $validator->time($input['end_time'], 'end_time');
    }
    
    if (!empty($input['description'])) {
        $validator->string($input['description'], 'description', 0, 500);
    }
    
    if (!empty($input['quiz_total_score'])) {
        $validator->integer($input['quiz_total_score'], 'quiz_total_score', 1, 100);
    }
    
    if ($validator->hasErrors()) {
        Response::error('Validation failed', 400, $validator->getErrors());
        return;
    }
    
    // التحقق من وجود الفصل
    $classQuery = "SELECT id FROM classes WHERE id = ?";
    $stmt = $pdo->prepare($classQuery);
    $stmt->execute([$input['class_id']]);
    
    if (!$stmt->fetch()) {
        Response::error('Class not found', 404);
        return;
    }
    
    // التحقق من عدم تداخل الجلسات
    $conflictQuery = "
        SELECT id FROM sessions 
        WHERE class_id = ? AND date = ? AND status = 'active'
        AND (
            (start_time <= ? AND end_time >= ?) OR
            (start_time <= ? AND end_time >= ?) OR
            (start_time >= ? AND start_time <= ?)
        )
    ";
    
    $endTime = $input['end_time'] ?? '23:59:59';
    $stmt = $pdo->prepare($conflictQuery);
    $stmt->execute([
        $input['class_id'],
        $input['date'],
        $input['start_time'], $input['start_time'],
        $endTime, $endTime,
        $input['start_time'], $endTime
    ]);
    
    if ($stmt->fetch()) {
        Response::error('Session time conflicts with existing active session', 409);
        return;
    }
    
    // إنشاء الجلسة الجديدة
    $query = "
        INSERT INTO sessions (
            class_id, subject, description, date, start_time, end_time, 
            quiz_total_score, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ";
    
    $stmt = $pdo->prepare($query);
    $success = $stmt->execute([
        $input['class_id'],
        $input['subject'],
        $input['description'] ?? '',
        $input['date'],
        $input['start_time'],
        $input['end_time'] ?? null,
        $input['quiz_total_score'] ?? 10
    ]);
    
    if ($success) {
        $sessionId = $pdo->lastInsertId();
        
        // جلب بيانات الجلسة المُنشأة
        $selectQuery = "
            SELECT s.*, c.name as class_name 
            FROM sessions s 
            LEFT JOIN classes c ON s.class_id = c.id 
            WHERE s.id = ?
        ";
        $stmt = $pdo->prepare($selectQuery);
        $stmt->execute([$sessionId]);
        $newSession = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success($newSession, 'Session created successfully', 201);
    } else {
        Response::error('Failed to create session', 500);
    }
}

function handleUpdateSession($pdo, $input) {
    $sessionId = intval($input['id'] ?? 0);
    $action = $input['action'] ?? 'update';
    
    if ($sessionId <= 0) {
        Response::error('Invalid session ID', 400);
        return;
    }
    
    // التحقق من وجود الجلسة
    $checkQuery = "SELECT * FROM sessions WHERE id = ?";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        Response::error('Session not found', 404);
        return;
    }
    
    switch ($action) {
        case 'end':
            endSession($pdo, $sessionId, $session);
            break;
            
        case 'update':
            updateSession($pdo, $sessionId, $input);
            break;
            
        default:
            Response::error('Invalid action', 400);
    }
}

function endSession($pdo, $sessionId, $session) {
    if ($session['status'] !== 'active') {
        Response::error('Session is not active', 400);
        return;
    }
    
    // تحديث حالة الجلسة
    $updateQuery = "UPDATE sessions SET status = 'completed', end_time = NOW(), updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($updateQuery);
    $success = $stmt->execute([$sessionId]);
    
    if ($success) {
        // جلب قائمة الحضور والغياب
        $attendanceQuery = "
            SELECT 
                st.id as student_id,
                st.name as student_name,
                st.parent_phone_encrypted,
                a.id as attendance_id,
                a.attendance_time,
                a.teacher_rating,
                a.quiz_score,
                a.participation_rating,
                a.behavior_rating,
                a.homework_status,
                a.notes,
                CASE WHEN a.id IS NOT NULL THEN 'present' ELSE 'absent' END as status
            FROM students st
            LEFT JOIN attendance a ON st.id = a.student_id AND a.session_id = ?
            WHERE st.class_id = ?
        ";
        
        $stmt = $pdo->prepare($attendanceQuery);
        $stmt->execute([$sessionId, $session['class_id']]);
        $attendanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // فك تشفير أرقام الهواتف
        foreach ($attendanceList as &$record) {
            if (!empty($record['parent_phone_encrypted'])) {
                $record['parent_phone'] = Database::decrypt($record['parent_phone_encrypted']);
            }
        }
        
        // إعداد التقارير للإرسال
        $reports = [
            'present' => [],
            'absent' => []
        ];
        
        foreach ($attendanceList as $record) {
            if ($record['status'] === 'present') {
                $reports['present'][] = [
                    'student_name' => $record['student_name'],
                    'parent_phone' => $record['parent_phone'],
                    'attendance_time' => $record['attendance_time'],
                    'teacher_rating' => $record['teacher_rating'],
                    'quiz_score' => $record['quiz_score'],
                    'participation_rating' => $record['participation_rating'],
                    'behavior_rating' => $record['behavior_rating'],
                    'homework_status' => $record['homework_status'],
                    'notes' => $record['notes']
                ];
            } else {
                $reports['absent'][] = [
                    'student_name' => $record['student_name'],
                    'parent_phone' => $record['parent_phone']
                ];
            }
        }
        
        Response::success([
            'session_id' => $sessionId,
            'status' => 'completed',
            'reports' => $reports,
            'message' => 'Session ended successfully. Reports ready for WhatsApp sending.'
        ]);
    } else {
        Response::error('Failed to end session', 500);
    }
}

function updateSession($pdo, $sessionId, $input) {
    // التحقق من صحة البيانات
    $validator = new Validator();
    
    if (isset($input['subject'])) {
        $validator->string($input['subject'], 'subject', 2, 100);
    }
    
    if (isset($input['description'])) {
        $validator->string($input['description'], 'description', 0, 500);
    }
    
    if (isset($input['date'])) {
        $validator->date($input['date'], 'date');
    }
    
    if (isset($input['start_time'])) {
        $validator->time($input['start_time'], 'start_time');
    }
    
    if (isset($input['end_time'])) {
        $validator->time($input['end_time'], 'end_time');
    }
    
    if (isset($input['quiz_total_score'])) {
        $validator->integer($input['quiz_total_score'], 'quiz_total_score', 1, 100);
    }
    
    if ($validator->hasErrors()) {
        Response::error('Validation failed', 400, $validator->getErrors());
        return;
    }
    
    // بناء استعلام التحديث
    $updateFields = [];
    $params = [];
    
    $allowedFields = ['subject', 'description', 'date', 'start_time', 'end_time', 'quiz_total_score'];
    
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
    $params[] = $sessionId;
    
    $query = "UPDATE sessions SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($query);
    $success = $stmt->execute($params);
    
    if ($success) {
        // جلب بيانات الجلسة المُحدثة
        $selectQuery = "
            SELECT s.*, c.name as class_name 
            FROM sessions s 
            LEFT JOIN classes c ON s.class_id = c.id 
            WHERE s.id = ?
        ";
        $stmt = $pdo->prepare($selectQuery);
        $stmt->execute([$sessionId]);
        $updatedSession = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success($updatedSession, 'Session updated successfully');
    } else {
        Response::error('Failed to update session', 500);
    }
}

function handleDeleteSession($pdo, $input) {
    $sessionId = intval($input['id'] ?? 0);
    
    if ($sessionId <= 0) {
        Response::error('Invalid session ID', 400);
        return;
    }
    
    // التحقق من وجود الجلسة
    $checkQuery = "SELECT status FROM sessions WHERE id = ?";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        Response::error('Session not found', 404);
        return;
    }
    
    if ($session['status'] === 'active') {
        Response::error('Cannot delete active session', 409);
        return;
    }
    
    // حذف سجلات الحضور المرتبطة أولاً
    $deleteAttendanceQuery = "DELETE FROM attendance WHERE session_id = ?";
    $stmt = $pdo->prepare($deleteAttendanceQuery);
    $stmt->execute([$sessionId]);
    
    // حذف الجلسة
    $deleteQuery = "DELETE FROM sessions WHERE id = ?";
    $stmt = $pdo->prepare($deleteQuery);
    $success = $stmt->execute([$sessionId]);
    
    if ($success) {
        Response::success(null, 'Session deleted successfully');
    } else {
        Response::error('Failed to delete session', 500);
    }
}
?>


