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
            handleGetAttendance($pdo);
            break;
            
        case 'POST':
            handleMarkAttendance($pdo, $input);
            break;
            
        case 'PUT':
            handleUpdateAttendance($pdo, $input);
            break;
            
        case 'DELETE':
            handleDeleteAttendance($pdo, $input);
            break;
            
        default:
            Response::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Attendance API Error: " . $e->getMessage());
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}

function handleGetAttendance($pdo) {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            getAttendanceList($pdo);
            break;
            
        case 'session':
            getSessionAttendance($pdo);
            break;
            
        case 'student':
            getStudentAttendance($pdo);
            break;
            
        case 'stats':
            getAttendanceStats($pdo);
            break;
            
        case 'scan':
            handleBarcodeScan($pdo);
            break;
            
        default:
            Response::error('Invalid action', 400);
    }
}

function getAttendanceList($pdo) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));
    $session_id = $_GET['session_id'] ?? '';
    $student_id = $_GET['student_id'] ?? '';
    $class_id = $_GET['class_id'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    
    $offset = ($page - 1) * $limit;
    
    // بناء الاستعلام مع الفلترة
    $whereConditions = [];
    $params = [];
    
    if (!empty($session_id)) {
        $whereConditions[] = "a.session_id = ?";
        $params[] = $session_id;
    }
    
    if (!empty($student_id)) {
        $whereConditions[] = "a.student_id = ?";
        $params[] = $student_id;
    }
    
    if (!empty($class_id)) {
        $whereConditions[] = "s.class_id = ?";
        $params[] = $class_id;
    }
    
    if (!empty($date_from)) {
        $whereConditions[] = "sess.date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $whereConditions[] = "sess.date <= ?";
        $params[] = $date_to;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // عدد سجلات الحضور الإجمالي
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM attendance a
        LEFT JOIN students s ON a.student_id = s.id
        LEFT JOIN sessions sess ON a.session_id = sess.id
        $whereClause
    ";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalAttendance = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // قائمة سجلات الحضور
    $query = "
        SELECT 
            a.*,
            s.name as student_name,
            s.barcode as student_barcode,
            sess.subject as session_subject,
            sess.date as session_date,
            sess.start_time as session_start_time,
            c.name as class_name,
            c.grade_level
        FROM attendance a
        LEFT JOIN students s ON a.student_id = s.id
        LEFT JOIN sessions sess ON a.session_id = sess.id
        LEFT JOIN classes c ON sess.class_id = c.id
        $whereClause
        ORDER BY a.attendance_time DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    Response::success([
        'attendance' => $attendance,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($totalAttendance / $limit),
            'total_items' => intval($totalAttendance),
            'items_per_page' => $limit
        ]
    ]);
}

function getSessionAttendance($pdo) {
    $sessionId = intval($_GET['session_id'] ?? 0);
    
    if ($sessionId <= 0) {
        Response::error('Invalid session ID', 400);
        return;
    }
    
    // التحقق من وجود الجلسة
    $sessionQuery = "SELECT * FROM sessions WHERE id = ?";
    $stmt = $pdo->prepare($sessionQuery);
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        Response::error('Session not found', 404);
        return;
    }
    
    // قائمة الحضور للجلسة
    $query = "
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
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$sessionId, $session['class_id']]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الجلسة
    $presentCount = 0;
    $absentCount = 0;
    $lateCount = 0;
    
    foreach ($attendance as $record) {
        if ($record['status'] === 'present') {
            $presentCount++;
            
            // تحديد المتأخرين (بعد 15 دقيقة من بداية الجلسة)
            $sessionStart = strtotime($session['date'] . ' ' . $session['start_time']);
            $attendanceTime = strtotime($record['attendance_time']);
            
            if ($attendanceTime > ($sessionStart + 900)) { // 15 دقيقة = 900 ثانية
                $lateCount++;
            }
        } else {
            $absentCount++;
        }
    }
    
    $totalStudents = count($attendance);
    $attendanceRate = $totalStudents > 0 ? round(($presentCount * 100.0) / $totalStudents, 2) : 0;
    
    Response::success([
        'session' => $session,
        'attendance' => $attendance,
        'stats' => [
            'total_students' => $totalStudents,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'late_count' => $lateCount,
            'attendance_rate' => $attendanceRate
        ]
    ]);
}

function getStudentAttendance($pdo) {
    $studentId = intval($_GET['student_id'] ?? 0);
    
    if ($studentId <= 0) {
        Response::error('Invalid student ID', 400);
        return;
    }
    
    // تفاصيل الطالب
    $studentQuery = "
        SELECT s.*, c.name as class_name 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ?
    ";
    $stmt = $pdo->prepare($studentQuery);
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        Response::error('Student not found', 404);
        return;
    }
    
    // سجل حضور الطالب
    $attendanceQuery = "
        SELECT 
            a.*,
            sess.subject,
            sess.date,
            sess.start_time,
            sess.end_time
        FROM attendance a
        LEFT JOIN sessions sess ON a.session_id = sess.id
        WHERE a.student_id = ?
        ORDER BY sess.date DESC, sess.start_time DESC
    ";
    
    $stmt = $pdo->prepare($attendanceQuery);
    $stmt->execute([$studentId]);
    $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الطالب
    $totalSessions = 0;
    $attendedSessions = count($attendanceRecords);
    $totalQuizScore = 0;
    $quizCount = 0;
    
    // حساب إجمالي الجلسات للفصل
    $totalSessionsQuery = "
        SELECT COUNT(*) as total 
        FROM sessions 
        WHERE class_id = ? AND status = 'completed'
    ";
    $stmt = $pdo->prepare($totalSessionsQuery);
    $stmt->execute([$student['class_id']]);
    $totalSessions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // حساب متوسط درجات الكويز
    foreach ($attendanceRecords as $record) {
        if (!is_null($record['quiz_score'])) {
            $totalQuizScore += $record['quiz_score'];
            $quizCount++;
        }
    }
    
    $avgQuizScore = $quizCount > 0 ? round($totalQuizScore / $quizCount, 2) : 0;
    $attendanceRate = $totalSessions > 0 ? round(($attendedSessions * 100.0) / $totalSessions, 2) : 0;
    
    // فك تشفير رقم الهاتف
    if (!empty($student['parent_phone_encrypted'])) {
        $student['parent_phone'] = Database::decrypt($student['parent_phone_encrypted']);
    }
    
    Response::success([
        'student' => $student,
        'attendance_records' => $attendanceRecords,
        'stats' => [
            'total_sessions' => intval($totalSessions),
            'attended_sessions' => $attendedSessions,
            'missed_sessions' => $totalSessions - $attendedSessions,
            'attendance_rate' => $attendanceRate,
            'avg_quiz_score' => $avgQuizScore,
            'quiz_count' => $quizCount
        ]
    ]);
}

function getAttendanceStats($pdo) {
    // إحصائيات عامة
    $generalStatsQuery = "
        SELECT 
            COUNT(*) as total_attendance_records,
            COUNT(DISTINCT student_id) as unique_students,
            COUNT(DISTINCT session_id) as sessions_with_attendance,
            AVG(quiz_score) as avg_quiz_score
        FROM attendance
        WHERE quiz_score IS NOT NULL
    ";
    
    $stmt = $pdo->prepare($generalStatsQuery);
    $stmt->execute();
    $generalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إحصائيات اليوم
    $todayStatsQuery = "
        SELECT 
            COUNT(*) as today_attendance,
            COUNT(DISTINCT a.student_id) as today_unique_students,
            COUNT(DISTINCT a.session_id) as today_sessions
        FROM attendance a
        LEFT JOIN sessions s ON a.session_id = s.id
        WHERE DATE(s.date) = CURDATE()
    ";
    
    $stmt = $pdo->prepare($todayStatsQuery);
    $stmt->execute();
    $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إحصائيات الأسبوع الحالي
    $weekStatsQuery = "
        SELECT 
            DATE(s.date) as date,
            COUNT(*) as attendance_count,
            COUNT(DISTINCT a.student_id) as unique_students
        FROM attendance a
        LEFT JOIN sessions s ON a.session_id = s.id
        WHERE WEEK(s.date) = WEEK(CURDATE()) AND YEAR(s.date) = YEAR(CURDATE())
        GROUP BY DATE(s.date)
        ORDER BY date
    ";
    
    $stmt = $pdo->prepare($weekStatsQuery);
    $stmt->execute();
    $weekStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    Response::success([
        'general' => [
            'total_attendance_records' => intval($generalStats['total_attendance_records']),
            'unique_students' => intval($generalStats['unique_students']),
            'sessions_with_attendance' => intval($generalStats['sessions_with_attendance']),
            'avg_quiz_score' => round(floatval($generalStats['avg_quiz_score']), 2)
        ],
        'today' => [
            'attendance_count' => intval($todayStats['today_attendance']),
            'unique_students' => intval($todayStats['today_unique_students']),
            'sessions' => intval($todayStats['today_sessions'])
        ],
        'week' => $weekStats
    ]);
}

function handleBarcodeScan($pdo) {
    $barcode = $_GET['barcode'] ?? '';
    $sessionId = intval($_GET['session_id'] ?? 0);
    
    if (empty($barcode)) {
        Response::error('Barcode is required', 400);
        return;
    }
    
    if ($sessionId <= 0) {
        Response::error('Session ID is required', 400);
        return;
    }
    
    // التحقق من وجود الجلسة وأنها نشطة
    $sessionQuery = "SELECT * FROM sessions WHERE id = ? AND status = 'active'";
    $stmt = $pdo->prepare($sessionQuery);
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        Response::error('Active session not found', 404);
        return;
    }
    
    // البحث عن الطالب بالباركود
    $studentQuery = "SELECT * FROM students WHERE barcode = ?";
    $stmt = $pdo->prepare($studentQuery);
    $stmt->execute([$barcode]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        // التسجيل السريع للطالب الجديد
        Response::success([
            'status' => 'student_not_found',
            'barcode' => $barcode,
            'session_id' => $sessionId,
            'message' => 'Student not found. Quick registration required.',
            'quick_register' => true
        ]);
        return;
    }
    
    // التحقق من أن الطالب ينتمي لنفس الفصل
    if ($student['class_id'] != $session['class_id']) {
        Response::error('Student does not belong to this class', 403);
        return;
    }
    
    // التحقق من عدم تسجيل الحضور مسبقاً
    $existingAttendanceQuery = "SELECT id FROM attendance WHERE student_id = ? AND session_id = ?";
    $stmt = $pdo->prepare($existingAttendanceQuery);
    $stmt->execute([$student['id'], $sessionId]);
    
    if ($stmt->fetch()) {
        Response::error('Student already marked as present', 409);
        return;
    }
    
    // تسجيل الحضور
    $insertQuery = "
        INSERT INTO attendance (student_id, session_id, attendance_time, created_at) 
        VALUES (?, ?, NOW(), NOW())
    ";
    
    $stmt = $pdo->prepare($insertQuery);
    $success = $stmt->execute([$student['id'], $sessionId]);
    
    if ($success) {
        $attendanceId = $pdo->lastInsertId();
        
        // جلب بيانات الحضور المُسجل
        $attendanceQuery = "
            SELECT 
                a.*,
                s.name as student_name,
                s.barcode as student_barcode
            FROM attendance a
            LEFT JOIN students s ON a.student_id = s.id
            WHERE a.id = ?
        ";
        
        $stmt = $pdo->prepare($attendanceQuery);
        $stmt->execute([$attendanceId]);
        $attendanceRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success([
            'status' => 'success',
            'message' => 'Attendance marked successfully',
            'attendance' => $attendanceRecord,
            'student' => $student,
            'auto_advance' => true // إشارة للانتقال التلقائي للسطر التالي
        ]);
    } else {
        Response::error('Failed to mark attendance', 500);
    }
}

function handleMarkAttendance($pdo, $input) {
    $action = $input['action'] ?? 'mark';
    
    switch ($action) {
        case 'mark':
            markAttendance($pdo, $input);
            break;
            
        case 'quick_register':
            quickRegisterAndMark($pdo, $input);
            break;
            
        default:
            Response::error('Invalid action', 400);
    }
}

function markAttendance($pdo, $input) {
    // التحقق من صحة البيانات
    $validator = new Validator();
    $validator->required($input, ['student_id', 'session_id']);
    $validator->integer($input['student_id'], 'student_id', 1);
    $validator->integer($input['session_id'], 'session_id', 1);
    
    if ($validator->hasErrors()) {
        Response::error('Validation failed', 400, $validator->getErrors());
        return;
    }
    
    $studentId = $input['student_id'];
    $sessionId = $input['session_id'];
    
    // التحقق من وجود الطالب والجلسة
    $checkQuery = "
        SELECT 
            s.id as student_id, s.name as student_name, s.class_id,
            sess.id as session_id, sess.class_id as session_class_id, sess.status
        FROM students s, sessions sess
        WHERE s.id = ? AND sess.id = ?
    ";
    
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$studentId, $sessionId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) {
        Response::error('Student or session not found', 404);
        return;
    }
    
    if ($data['status'] !== 'active') {
        Response::error('Session is not active', 400);
        return;
    }
    
    if ($data['class_id'] !== $data['session_class_id']) {
        Response::error('Student does not belong to this class', 403);
        return;
    }
    
    // التحقق من عدم تسجيل الحضور مسبقاً
    $existingQuery = "SELECT id FROM attendance WHERE student_id = ? AND session_id = ?";
    $stmt = $pdo->prepare($existingQuery);
    $stmt->execute([$studentId, $sessionId]);
    
    if ($stmt->fetch()) {
        Response::error('Attendance already marked', 409);
        return;
    }
    
    // تسجيل الحضور
    $insertQuery = "
        INSERT INTO attendance (
            student_id, session_id, attendance_time, 
            teacher_rating, quiz_score, participation_rating, 
            behavior_rating, homework_status, notes, created_at
        ) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW())
    ";
    
    $stmt = $pdo->prepare($insertQuery);
    $success = $stmt->execute([
        $studentId,
        $sessionId,
        $input['teacher_rating'] ?? null,
        $input['quiz_score'] ?? null,
        $input['participation_rating'] ?? null,
        $input['behavior_rating'] ?? null,
        $input['homework_status'] ?? null,
        $input['notes'] ?? null
    ]);
    
    if ($success) {
        $attendanceId = $pdo->lastInsertId();
        
        // جلب بيانات الحضور المُسجل
        $selectQuery = "
            SELECT 
                a.*,
                s.name as student_name,
                s.barcode as student_barcode
            FROM attendance a
            LEFT JOIN students s ON a.student_id = s.id
            WHERE a.id = ?
        ";
        
        $stmt = $pdo->prepare($selectQuery);
        $stmt->execute([$attendanceId]);
        $attendanceRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success($attendanceRecord, 'Attendance marked successfully', 201);
    } else {
        Response::error('Failed to mark attendance', 500);
    }
}

function quickRegisterAndMark($pdo, $input) {
    // التحقق من صحة البيانات
    $validator = new Validator();
    $validator->required($input, ['barcode', 'name', 'session_id']);
    $validator->string($input['barcode'], 'barcode', 1, 50);
    $validator->string($input['name'], 'name', 2, 100);
    $validator->integer($input['session_id'], 'session_id', 1);
    
    if (!empty($input['parent_phone'])) {
        $validator->phone($input['parent_phone'], 'parent_phone');
    }
    
    if ($validator->hasErrors()) {
        Response::error('Validation failed', 400, $validator->getErrors());
        return;
    }
    
    $sessionId = $input['session_id'];
    
    // التحقق من وجود الجلسة وأنها نشطة
    $sessionQuery = "SELECT * FROM sessions WHERE id = ? AND status = 'active'";
    $stmt = $pdo->prepare($sessionQuery);
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        Response::error('Active session not found', 404);
        return;
    }
    
    // التحقق من عدم تكرار الباركود
    $barcodeCheckQuery = "SELECT id FROM students WHERE barcode = ?";
    $stmt = $pdo->prepare($barcodeCheckQuery);
    $stmt->execute([$input['barcode']]);
    
    if ($stmt->fetch()) {
        Response::error('Barcode already exists', 409);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // تشفير رقم الهاتف إذا تم توفيره
        $encryptedPhone = !empty($input['parent_phone']) ? 
            Database::encrypt($input['parent_phone']) : null;
        
        // تسجيل الطالب الجديد
        $insertStudentQuery = "
            INSERT INTO students (
                name, barcode, class_id, parent_phone_encrypted, 
                grade_level, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ";
        
        $stmt = $pdo->prepare($insertStudentQuery);
        $success = $stmt->execute([
            $input['name'],
            $input['barcode'],
            $session['class_id'],
            $encryptedPhone,
            $input['grade_level'] ?? ''
        ]);
        
        if (!$success) {
            throw new Exception('Failed to register student');
        }
        
        $studentId = $pdo->lastInsertId();
        
        // تسجيل الحضور
        $insertAttendanceQuery = "
            INSERT INTO attendance (student_id, session_id, attendance_time, created_at) 
            VALUES (?, ?, NOW(), NOW())
        ";
        
        $stmt = $pdo->prepare($insertAttendanceQuery);
        $success = $stmt->execute([$studentId, $sessionId]);
        
        if (!$success) {
            throw new Exception('Failed to mark attendance');
        }
        
        $attendanceId = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // جلب بيانات الطالب والحضور المُسجل
        $resultQuery = "
            SELECT 
                s.id as student_id,
                s.name as student_name,
                s.barcode as student_barcode,
                a.id as attendance_id,
                a.attendance_time
            FROM students s
            LEFT JOIN attendance a ON s.id = a.student_id AND a.id = ?
            WHERE s.id = ?
        ";
        
        $stmt = $pdo->prepare($resultQuery);
        $stmt->execute([$attendanceId, $studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success([
            'status' => 'success',
            'message' => 'Student registered and attendance marked successfully',
            'student' => [
                'id' => $studentId,
                'name' => $input['name'],
                'barcode' => $input['barcode']
            ],
            'attendance' => $result,
            'auto_advance' => true
        ], 'Quick registration completed', 201);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        Response::error('Failed to register student and mark attendance: ' . $e->getMessage(), 500);
    }
}

function handleUpdateAttendance($pdo, $input) {
    $attendanceId = intval($input['id'] ?? 0);
    
    if ($attendanceId <= 0) {
        Response::error('Invalid attendance ID', 400);
        return;
    }
    
    // التحقق من وجود سجل الحضور
    $checkQuery = "SELECT * FROM attendance WHERE id = ?";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$attendanceId]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$attendance) {
        Response::error('Attendance record not found', 404);
        return;
    }
    
    // التحقق من صحة البيانات
    $validator = new Validator();
    
    if (isset($input['teacher_rating'])) {
        $validator->string($input['teacher_rating'], 'teacher_rating', 0, 20);
    }
    
    if (isset($input['quiz_score'])) {
        $validator->integer($input['quiz_score'], 'quiz_score', 0, 100);
    }
    
    if (isset($input['participation_rating'])) {
        $validator->string($input['participation_rating'], 'participation_rating', 0, 20);
    }
    
    if (isset($input['behavior_rating'])) {
        $validator->string($input['behavior_rating'], 'behavior_rating', 0, 20);
    }
    
    if (isset($input['homework_status'])) {
        $validator->string($input['homework_status'], 'homework_status', 0, 20);
    }
    
    if (isset($input['notes'])) {
        $validator->string($input['notes'], 'notes', 0, 500);
    }
    
    if ($validator->hasErrors()) {
        Response::error('Validation failed', 400, $validator->getErrors());
        return;
    }
    
    // بناء استعلام التحديث
    $updateFields = [];
    $params = [];
    
    $allowedFields = [
        'teacher_rating', 'quiz_score', 'participation_rating', 
        'behavior_rating', 'homework_status', 'notes'
    ];
    
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
    $params[] = $attendanceId;
    
    $query = "UPDATE attendance SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($query);
    $success = $stmt->execute($params);
    
    if ($success) {
        // جلب بيانات الحضور المُحدث
        $selectQuery = "
            SELECT 
                a.*,
                s.name as student_name,
                s.barcode as student_barcode
            FROM attendance a
            LEFT JOIN students s ON a.student_id = s.id
            WHERE a.id = ?
        ";
        
        $stmt = $pdo->prepare($selectQuery);
        $stmt->execute([$attendanceId]);
        $updatedAttendance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success($updatedAttendance, 'Attendance updated successfully');
    } else {
        Response::error('Failed to update attendance', 500);
    }
}

function handleDeleteAttendance($pdo, $input) {
    $attendanceId = intval($input['id'] ?? 0);
    
    if ($attendanceId <= 0) {
        Response::error('Invalid attendance ID', 400);
        return;
    }
    
    // التحقق من وجود سجل الحضور
    $checkQuery = "SELECT * FROM attendance WHERE id = ?";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->execute([$attendanceId]);
    
    if (!$stmt->fetch()) {
        Response::error('Attendance record not found', 404);
        return;
    }
    
    // حذف سجل الحضور
    $deleteQuery = "DELETE FROM attendance WHERE id = ?";
    $stmt = $pdo->prepare($deleteQuery);
    $success = $stmt->execute([$attendanceId]);
    
    if ($success) {
        Response::success(null, 'Attendance record deleted successfully');
    } else {
        Response::error('Failed to delete attendance record', 500);
    }
}
?>


