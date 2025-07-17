<?php
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    
    if ($method === 'GET') {
        handleGetReports($pdo);
    } elseif ($method === 'POST') {
        handleGenerateReport($pdo);
    } else {
        Response::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Reports API Error: " . $e->getMessage());
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}

function handleGetReports($pdo) {
    $type = $_GET['type'] ?? 'attendance';
    
    switch ($type) {
        case 'attendance':
            generateAttendanceReport($pdo);
            break;
            
        case 'student':
            generateStudentReport($pdo);
            break;
            
        case 'class':
            generateClassReport($pdo);
            break;
            
        case 'session':
            generateSessionReport($pdo);
            break;
            
        case 'absence':
            generateAbsenceReport($pdo);
            break;
            
        case 'performance':
            generatePerformanceReport($pdo);
            break;
            
        case 'summary':
            generateSummaryReport($pdo);
            break;
            
        default:
            Response::error('Invalid report type', 400);
    }
}

function generateAttendanceReport($pdo) {
    $date_from = $_GET['date_from'] ?? date('Y-m-01'); // بداية الشهر الحالي
    $date_to = $_GET['date_to'] ?? date('Y-m-d'); // اليوم الحالي
    $class_id = $_GET['class_id'] ?? '';
    $student_id = $_GET['student_id'] ?? '';
    $format = $_GET['format'] ?? 'json';
    
    // بناء الاستعلام
    $whereConditions = ["sess.date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if (!empty($class_id)) {
        $whereConditions[] = "sess.class_id = ?";
        $params[] = $class_id;
    }
    
    if (!empty($student_id)) {
        $whereConditions[] = "a.student_id = ?";
        $params[] = $student_id;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $query = "
        SELECT 
            a.id,
            s.name as student_name,
            s.barcode as student_barcode,
            c.name as class_name,
            c.grade_level,
            sess.subject,
            sess.date,
            sess.start_time,
            sess.end_time,
            a.attendance_time,
            a.teacher_rating,
            a.quiz_score,
            a.participation_rating,
            a.behavior_rating,
            a.homework_status,
            a.notes,
            CASE 
                WHEN TIME(a.attendance_time) > ADDTIME(sess.start_time, '00:15:00') 
                THEN 'late' 
                ELSE 'on_time' 
            END as punctuality
        FROM attendance a
        LEFT JOIN students s ON a.student_id = s.id
        LEFT JOIN sessions sess ON a.session_id = sess.id
        LEFT JOIN classes c ON sess.class_id = c.id
        $whereClause
        ORDER BY sess.date DESC, sess.start_time DESC, s.name
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $attendanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات التقرير
    $totalRecords = count($attendanceData);
    $onTimeCount = 0;
    $lateCount = 0;
    $totalQuizScore = 0;
    $quizCount = 0;
    
    foreach ($attendanceData as $record) {
        if ($record['punctuality'] === 'on_time') {
            $onTimeCount++;
        } else {
            $lateCount++;
        }
        
        if (!is_null($record['quiz_score'])) {
            $totalQuizScore += $record['quiz_score'];
            $quizCount++;
        }
    }
    
    $avgQuizScore = $quizCount > 0 ? round($totalQuizScore / $quizCount, 2) : 0;
    $punctualityRate = $totalRecords > 0 ? round(($onTimeCount * 100.0) / $totalRecords, 2) : 0;
    
    $reportData = [
        'report_info' => [
            'type' => 'attendance',
            'date_from' => $date_from,
            'date_to' => $date_to,
            'generated_at' => date('Y-m-d H:i:s'),
            'total_records' => $totalRecords
        ],
        'statistics' => [
            'total_attendance' => $totalRecords,
            'on_time_count' => $onTimeCount,
            'late_count' => $lateCount,
            'punctuality_rate' => $punctualityRate,
            'avg_quiz_score' => $avgQuizScore,
            'quiz_submissions' => $quizCount
        ],
        'data' => $attendanceData
    ];
    
    if ($format === 'csv') {
        generateCSVReport($reportData, 'attendance_report');
    } else {
        Response::success($reportData);
    }
}

function generateStudentReport($pdo) {
    $student_id = intval($_GET['student_id'] ?? 0);
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $format = $_GET['format'] ?? 'json';
    
    if ($student_id <= 0) {
        Response::error('Student ID is required', 400);
        return;
    }
    
    // بيانات الطالب
    $studentQuery = "
        SELECT s.*, c.name as class_name, c.grade_level 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ?
    ";
    $stmt = $pdo->prepare($studentQuery);
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        Response::error('Student not found', 404);
        return;
    }
    
    // فك تشفير رقم الهاتف
    if (!empty($student['parent_phone_encrypted'])) {
        $student['parent_phone'] = Database::decrypt($student['parent_phone_encrypted']);
    }
    
    // سجل الحضور
    $attendanceQuery = "
        SELECT 
            a.*,
            sess.subject,
            sess.date,
            sess.start_time,
            sess.end_time,
            CASE 
                WHEN TIME(a.attendance_time) > ADDTIME(sess.start_time, '00:15:00') 
                THEN 'late' 
                ELSE 'on_time' 
            END as punctuality
        FROM attendance a
        LEFT JOIN sessions sess ON a.session_id = sess.id
        WHERE a.student_id = ? AND sess.date BETWEEN ? AND ?
        ORDER BY sess.date DESC, sess.start_time DESC
    ";
    
    $stmt = $pdo->prepare($attendanceQuery);
    $stmt->execute([$student_id, $date_from, $date_to]);
    $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الطالب
    $totalSessions = 0;
    $attendedSessions = count($attendanceRecords);
    $onTimeCount = 0;
    $lateCount = 0;
    $totalQuizScore = 0;
    $quizCount = 0;
    
    // حساب إجمالي الجلسات للفصل في الفترة المحددة
    $totalSessionsQuery = "
        SELECT COUNT(*) as total 
        FROM sessions 
        WHERE class_id = ? AND date BETWEEN ? AND ? AND status = 'completed'
    ";
    $stmt = $pdo->prepare($totalSessionsQuery);
    $stmt->execute([$student['class_id'], $date_from, $date_to]);
    $totalSessions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    foreach ($attendanceRecords as $record) {
        if ($record['punctuality'] === 'on_time') {
            $onTimeCount++;
        } else {
            $lateCount++;
        }
        
        if (!is_null($record['quiz_score'])) {
            $totalQuizScore += $record['quiz_score'];
            $quizCount++;
        }
    }
    
    $attendanceRate = $totalSessions > 0 ? round(($attendedSessions * 100.0) / $totalSessions, 2) : 0;
    $punctualityRate = $attendedSessions > 0 ? round(($onTimeCount * 100.0) / $attendedSessions, 2) : 0;
    $avgQuizScore = $quizCount > 0 ? round($totalQuizScore / $quizCount, 2) : 0;
    
    $reportData = [
        'report_info' => [
            'type' => 'student',
            'student_id' => $student_id,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'generated_at' => date('Y-m-d H:i:s')
        ],
        'student' => $student,
        'statistics' => [
            'total_sessions' => intval($totalSessions),
            'attended_sessions' => $attendedSessions,
            'missed_sessions' => $totalSessions - $attendedSessions,
            'attendance_rate' => $attendanceRate,
            'on_time_count' => $onTimeCount,
            'late_count' => $lateCount,
            'punctuality_rate' => $punctualityRate,
            'avg_quiz_score' => $avgQuizScore,
            'quiz_submissions' => $quizCount
        ],
        'attendance_records' => $attendanceRecords
    ];
    
    if ($format === 'csv') {
        generateCSVReport($reportData, 'student_report_' . $student['name']);
    } else {
        Response::success($reportData);
    }
}

function generateClassReport($pdo) {
    $class_id = intval($_GET['class_id'] ?? 0);
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $format = $_GET['format'] ?? 'json';
    
    if ($class_id <= 0) {
        Response::error('Class ID is required', 400);
        return;
    }
    
    // بيانات الفصل
    $classQuery = "SELECT * FROM classes WHERE id = ?";
    $stmt = $pdo->prepare($classQuery);
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$class) {
        Response::error('Class not found', 404);
        return;
    }
    
    // إحصائيات الطلاب
    $studentsQuery = "
        SELECT 
            s.id,
            s.name,
            s.barcode,
            COUNT(DISTINCT a.id) as attended_sessions,
            COUNT(DISTINCT sess.id) as total_sessions,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT sess.id), 0)), 0) as attendance_rate,
            AVG(a.quiz_score) as avg_quiz_score,
            COUNT(CASE WHEN TIME(a.attendance_time) <= ADDTIME(sess.start_time, '00:15:00') THEN 1 END) as on_time_count,
            COUNT(CASE WHEN TIME(a.attendance_time) > ADDTIME(sess.start_time, '00:15:00') THEN 1 END) as late_count
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id
        LEFT JOIN sessions sess ON a.session_id = sess.id AND sess.date BETWEEN ? AND ?
        WHERE s.class_id = ?
        GROUP BY s.id
        ORDER BY s.name
    ";
    
    $stmt = $pdo->prepare($studentsQuery);
    $stmt->execute([$date_from, $date_to, $class_id]);
    $studentsStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الجلسات
    $sessionsQuery = "
        SELECT 
            sess.*,
            COUNT(DISTINCT a.id) as attendance_count,
            COUNT(DISTINCT s.id) as total_students,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id), 0)), 0) as attendance_rate
        FROM sessions sess
        LEFT JOIN students s ON sess.class_id = s.class_id
        LEFT JOIN attendance a ON sess.id = a.session_id AND s.id = a.student_id
        WHERE sess.class_id = ? AND sess.date BETWEEN ? AND ?
        GROUP BY sess.id
        ORDER BY sess.date DESC, sess.start_time DESC
    ";
    
    $stmt = $pdo->prepare($sessionsQuery);
    $stmt->execute([$class_id, $date_from, $date_to]);
    $sessionsStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات عامة للفصل
    $totalStudents = count($studentsStats);
    $totalSessions = count($sessionsStats);
    $avgAttendanceRate = 0;
    $avgQuizScore = 0;
    
    if ($totalStudents > 0) {
        $totalAttendanceRate = 0;
        $totalQuizScores = 0;
        $quizCount = 0;
        
        foreach ($studentsStats as $student) {
            $totalAttendanceRate += $student['attendance_rate'];
            if (!is_null($student['avg_quiz_score'])) {
                $totalQuizScores += $student['avg_quiz_score'];
                $quizCount++;
            }
        }
        
        $avgAttendanceRate = round($totalAttendanceRate / $totalStudents, 2);
        $avgQuizScore = $quizCount > 0 ? round($totalQuizScores / $quizCount, 2) : 0;
    }
    
    $reportData = [
        'report_info' => [
            'type' => 'class',
            'class_id' => $class_id,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'generated_at' => date('Y-m-d H:i:s')
        ],
        'class' => $class,
        'statistics' => [
            'total_students' => $totalStudents,
            'total_sessions' => $totalSessions,
            'avg_attendance_rate' => $avgAttendanceRate,
            'avg_quiz_score' => $avgQuizScore
        ],
        'students_stats' => $studentsStats,
        'sessions_stats' => $sessionsStats
    ];
    
    if ($format === 'csv') {
        generateCSVReport($reportData, 'class_report_' . $class['name']);
    } else {
        Response::success($reportData);
    }
}

function generateSessionReport($pdo) {
    $session_id = intval($_GET['session_id'] ?? 0);
    $format = $_GET['format'] ?? 'json';
    
    if ($session_id <= 0) {
        Response::error('Session ID is required', 400);
        return;
    }
    
    // بيانات الجلسة
    $sessionQuery = "
        SELECT sess.*, c.name as class_name, c.grade_level 
        FROM sessions sess 
        LEFT JOIN classes c ON sess.class_id = c.id 
        WHERE sess.id = ?
    ";
    $stmt = $pdo->prepare($sessionQuery);
    $stmt->execute([$session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        Response::error('Session not found', 404);
        return;
    }
    
    // قائمة الحضور والغياب
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
            CASE WHEN a.id IS NOT NULL THEN 'present' ELSE 'absent' END as status,
            CASE 
                WHEN a.id IS NOT NULL AND TIME(a.attendance_time) > ADDTIME(?, '00:15:00') 
                THEN 'late' 
                WHEN a.id IS NOT NULL 
                THEN 'on_time'
                ELSE null
            END as punctuality
        FROM students st
        LEFT JOIN attendance a ON st.id = a.student_id AND a.session_id = ?
        WHERE st.class_id = ?
        ORDER BY st.name
    ";
    
    $stmt = $pdo->prepare($attendanceQuery);
    $stmt->execute([$session['start_time'], $session_id, $session['class_id']]);
    $attendanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الجلسة
    $totalStudents = count($attendanceList);
    $presentCount = 0;
    $absentCount = 0;
    $onTimeCount = 0;
    $lateCount = 0;
    $totalQuizScore = 0;
    $quizCount = 0;
    
    foreach ($attendanceList as $record) {
        if ($record['status'] === 'present') {
            $presentCount++;
            
            if ($record['punctuality'] === 'on_time') {
                $onTimeCount++;
            } else {
                $lateCount++;
            }
            
            if (!is_null($record['quiz_score'])) {
                $totalQuizScore += $record['quiz_score'];
                $quizCount++;
            }
        } else {
            $absentCount++;
        }
    }
    
    $attendanceRate = $totalStudents > 0 ? round(($presentCount * 100.0) / $totalStudents, 2) : 0;
    $punctualityRate = $presentCount > 0 ? round(($onTimeCount * 100.0) / $presentCount, 2) : 0;
    $avgQuizScore = $quizCount > 0 ? round($totalQuizScore / $quizCount, 2) : 0;
    
    $reportData = [
        'report_info' => [
            'type' => 'session',
            'session_id' => $session_id,
            'generated_at' => date('Y-m-d H:i:s')
        ],
        'session' => $session,
        'statistics' => [
            'total_students' => $totalStudents,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'attendance_rate' => $attendanceRate,
            'on_time_count' => $onTimeCount,
            'late_count' => $lateCount,
            'punctuality_rate' => $punctualityRate,
            'avg_quiz_score' => $avgQuizScore,
            'quiz_submissions' => $quizCount
        ],
        'attendance_list' => $attendanceList
    ];
    
    if ($format === 'csv') {
        generateCSVReport($reportData, 'session_report_' . $session['subject'] . '_' . $session['date']);
    } else {
        Response::success($reportData);
    }
}

function generateAbsenceReport($pdo) {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $class_id = $_GET['class_id'] ?? '';
    $min_absences = intval($_GET['min_absences'] ?? 1);
    $format = $_GET['format'] ?? 'json';
    
    // بناء الاستعلام
    $whereConditions = ["sess.date BETWEEN ? AND ?", "sess.status = 'completed'"];
    $params = [$date_from, $date_to];
    
    if (!empty($class_id)) {
        $whereConditions[] = "s.class_id = ?";
        $params[] = $class_id;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    $query = "
        SELECT 
            s.id as student_id,
            s.name as student_name,
            s.barcode,
            s.parent_phone_encrypted,
            c.name as class_name,
            c.grade_level,
            COUNT(sess.id) as total_sessions,
            COUNT(a.id) as attended_sessions,
            (COUNT(sess.id) - COUNT(a.id)) as absent_sessions,
            COALESCE((COUNT(a.id) * 100.0 / NULLIF(COUNT(sess.id), 0)), 0) as attendance_rate
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN sessions sess ON s.class_id = sess.class_id AND sess.date BETWEEN ? AND ? AND sess.status = 'completed'
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
        $whereClause
        GROUP BY s.id
        HAVING absent_sessions >= ?
        ORDER BY absent_sessions DESC, s.name
    ";
    
    $params = array_merge([$date_from, $date_to], $params, [$min_absences]);
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $absenceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // فك تشفير أرقام الهواتف
    foreach ($absenceData as &$record) {
        if (!empty($record['parent_phone_encrypted'])) {
            $record['parent_phone'] = Database::decrypt($record['parent_phone_encrypted']);
        }
    }
    
    // إحصائيات التقرير
    $totalStudents = count($absenceData);
    $totalAbsences = 0;
    $highRiskStudents = 0; // أكثر من 30% غياب
    
    foreach ($absenceData as $record) {
        $totalAbsences += $record['absent_sessions'];
        if ($record['attendance_rate'] < 70) {
            $highRiskStudents++;
        }
    }
    
    $reportData = [
        'report_info' => [
            'type' => 'absence',
            'date_from' => $date_from,
            'date_to' => $date_to,
            'min_absences' => $min_absences,
            'generated_at' => date('Y-m-d H:i:s')
        ],
        'statistics' => [
            'total_students_with_absences' => $totalStudents,
            'total_absences' => intval($totalAbsences),
            'high_risk_students' => $highRiskStudents,
            'avg_absences_per_student' => $totalStudents > 0 ? round($totalAbsences / $totalStudents, 2) : 0
        ],
        'data' => $absenceData
    ];
    
    if ($format === 'csv') {
        generateCSVReport($reportData, 'absence_report');
    } else {
        Response::success($reportData);
    }
}

function generatePerformanceReport($pdo) {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $class_id = $_GET['class_id'] ?? '';
    $format = $_GET['format'] ?? 'json';
    
    // بناء الاستعلام
    $whereConditions = ["sess.date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if (!empty($class_id)) {
        $whereConditions[] = "s.class_id = ?";
        $params[] = $class_id;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $query = "
        SELECT 
            s.id as student_id,
            s.name as student_name,
            s.barcode,
            c.name as class_name,
            c.grade_level,
            COUNT(a.id) as attended_sessions,
            AVG(a.quiz_score) as avg_quiz_score,
            COUNT(CASE WHEN a.quiz_score IS NOT NULL THEN 1 END) as quiz_submissions,
            COUNT(CASE WHEN a.teacher_rating = 'ممتاز' THEN 1 END) as excellent_ratings,
            COUNT(CASE WHEN a.teacher_rating = 'جيد جداً' THEN 1 END) as very_good_ratings,
            COUNT(CASE WHEN a.teacher_rating = 'جيد' THEN 1 END) as good_ratings,
            COUNT(CASE WHEN a.teacher_rating = 'مقبول' THEN 1 END) as acceptable_ratings,
            COUNT(CASE WHEN a.teacher_rating = 'ضعيف' THEN 1 END) as poor_ratings,
            COUNT(CASE WHEN a.homework_status = 'مكتمل' THEN 1 END) as completed_homework,
            COUNT(CASE WHEN a.homework_status = 'غير مكتمل' THEN 1 END) as incomplete_homework,
            COUNT(CASE WHEN a.homework_status = 'لم يحضر' THEN 1 END) as no_homework
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN attendance a ON s.id = a.student_id
        LEFT JOIN sessions sess ON a.session_id = sess.id
        $whereClause
        GROUP BY s.id
        HAVING attended_sessions > 0
        ORDER BY avg_quiz_score DESC, attended_sessions DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $performanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات وحساب النقاط
    foreach ($performanceData as &$record) {
        $record['avg_quiz_score'] = round(floatval($record['avg_quiz_score']), 2);
        
        // حساب نقاط الأداء (من 100)
        $attendancePoints = min(30, $record['attended_sessions'] * 2); // حتى 30 نقطة للحضور
        $quizPoints = min(40, $record['avg_quiz_score'] * 0.4); // حتى 40 نقطة للكويز
        $ratingPoints = ($record['excellent_ratings'] * 5 + $record['very_good_ratings'] * 4 + 
                        $record['good_ratings'] * 3 + $record['acceptable_ratings'] * 2); // حتى 20 نقطة للتقييم
        $homeworkPoints = min(10, $record['completed_homework'] * 2); // حتى 10 نقاط للواجبات
        
        $record['performance_score'] = min(100, $attendancePoints + $quizPoints + $ratingPoints + $homeworkPoints);
        $record['performance_grade'] = getPerformanceGrade($record['performance_score']);
    }
    
    // إحصائيات التقرير
    $totalStudents = count($performanceData);
    $avgPerformanceScore = 0;
    $gradeDistribution = [
        'A+' => 0, 'A' => 0, 'B+' => 0, 'B' => 0, 
        'C+' => 0, 'C' => 0, 'D' => 0, 'F' => 0
    ];
    
    foreach ($performanceData as $record) {
        $avgPerformanceScore += $record['performance_score'];
        $gradeDistribution[$record['performance_grade']]++;
    }
    
    $avgPerformanceScore = $totalStudents > 0 ? round($avgPerformanceScore / $totalStudents, 2) : 0;
    
    $reportData = [
        'report_info' => [
            'type' => 'performance',
            'date_from' => $date_from,
            'date_to' => $date_to,
            'generated_at' => date('Y-m-d H:i:s')
        ],
        'statistics' => [
            'total_students' => $totalStudents,
            'avg_performance_score' => $avgPerformanceScore,
            'grade_distribution' => $gradeDistribution
        ],
        'data' => $performanceData
    ];
    
    if ($format === 'csv') {
        generateCSVReport($reportData, 'performance_report');
    } else {
        Response::success($reportData);
    }
}

function generateSummaryReport($pdo) {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $format = $_GET['format'] ?? 'json';
    
    // إحصائيات عامة
    $generalStatsQuery = "
        SELECT 
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT c.id) as total_classes,
            COUNT(DISTINCT sess.id) as total_sessions,
            COUNT(DISTINCT a.id) as total_attendance_records
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND sess.date BETWEEN ? AND ?
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
    ";
    
    $stmt = $pdo->prepare($generalStatsQuery);
    $stmt->execute([$date_from, $date_to]);
    $generalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إحصائيات الحضور حسب الفصل
    $classStatsQuery = "
        SELECT 
            c.name as class_name,
            c.grade_level,
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT sess.id) as total_sessions,
            COUNT(DISTINCT a.id) as total_attendance,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id) * COUNT(DISTINCT sess.id), 0)), 0) as attendance_rate
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND sess.date BETWEEN ? AND ?
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
        GROUP BY c.id
        ORDER BY attendance_rate DESC
    ";
    
    $stmt = $pdo->prepare($classStatsQuery);
    $stmt->execute([$date_from, $date_to]);
    $classStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات يومية
    $dailyStatsQuery = "
        SELECT 
            sess.date,
            COUNT(DISTINCT sess.id) as sessions_count,
            COUNT(DISTINCT a.id) as attendance_count,
            COUNT(DISTINCT s.id) as unique_students
        FROM sessions sess
        LEFT JOIN attendance a ON sess.id = a.session_id
        LEFT JOIN students s ON a.student_id = s.id
        WHERE sess.date BETWEEN ? AND ?
        GROUP BY sess.date
        ORDER BY sess.date
    ";
    
    $stmt = $pdo->prepare($dailyStatsQuery);
    $stmt->execute([$date_from, $date_to]);
    $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // أفضل الطلاب أداءً
    $topStudentsQuery = "
        SELECT 
            s.name as student_name,
            c.name as class_name,
            COUNT(a.id) as attended_sessions,
            AVG(a.quiz_score) as avg_quiz_score,
            COALESCE((COUNT(a.id) * 100.0 / NULLIF(COUNT(DISTINCT sess.id), 0)), 0) as attendance_rate
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND sess.date BETWEEN ? AND ?
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
        GROUP BY s.id
        HAVING attended_sessions > 0
        ORDER BY attendance_rate DESC, avg_quiz_score DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($topStudentsQuery);
    $stmt->execute([$date_from, $date_to]);
    $topStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تنسيق البيانات
    foreach ($classStats as &$class) {
        $class['attendance_rate'] = round(floatval($class['attendance_rate']), 2);
    }
    
    foreach ($topStudents as &$student) {
        $student['avg_quiz_score'] = round(floatval($student['avg_quiz_score']), 2);
        $student['attendance_rate'] = round(floatval($student['attendance_rate']), 2);
    }
    
    $reportData = [
        'report_info' => [
            'type' => 'summary',
            'date_from' => $date_from,
            'date_to' => $date_to,
            'generated_at' => date('Y-m-d H:i:s')
        ],
        'general_statistics' => [
            'total_students' => intval($generalStats['total_students']),
            'total_classes' => intval($generalStats['total_classes']),
            'total_sessions' => intval($generalStats['total_sessions']),
            'total_attendance_records' => intval($generalStats['total_attendance_records'])
        ],
        'class_statistics' => $classStats,
        'daily_statistics' => $dailyStats,
        'top_students' => $topStudents
    ];
    
    if ($format === 'csv') {
        generateCSVReport($reportData, 'summary_report');
    } else {
        Response::success($reportData);
    }
}

function getPerformanceGrade($score) {
    if ($score >= 95) return 'A+';
    if ($score >= 90) return 'A';
    if ($score >= 85) return 'B+';
    if ($score >= 80) return 'B';
    if ($score >= 75) return 'C+';
    if ($score >= 70) return 'C';
    if ($score >= 60) return 'D';
    return 'F';
}

function generateCSVReport($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // إضافة BOM للدعم العربي في Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // كتابة معلومات التقرير
    fputcsv($output, ['تقرير: ' . $data['report_info']['type']]);
    fputcsv($output, ['تاريخ الإنشاء: ' . $data['report_info']['generated_at']]);
    fputcsv($output, []);
    
    // كتابة البيانات حسب نوع التقرير
    if (isset($data['data']) && is_array($data['data']) && !empty($data['data'])) {
        // كتابة العناوين
        $headers = array_keys($data['data'][0]);
        fputcsv($output, $headers);
        
        // كتابة البيانات
        foreach ($data['data'] as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit();
}

function handleGenerateReport($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $type = $input['type'] ?? '';
    $format = $input['format'] ?? 'json';
    $parameters = $input['parameters'] ?? [];
    
    // تحويل المعاملات إلى GET parameters للاستفادة من الدوال الموجودة
    foreach ($parameters as $key => $value) {
        $_GET[$key] = $value;
    }
    $_GET['format'] = $format;
    
    switch ($type) {
        case 'attendance':
            generateAttendanceReport($pdo);
            break;
            
        case 'student':
            generateStudentReport($pdo);
            break;
            
        case 'class':
            generateClassReport($pdo);
            break;
            
        case 'session':
            generateSessionReport($pdo);
            break;
            
        case 'absence':
            generateAbsenceReport($pdo);
            break;
            
        case 'performance':
            generatePerformanceReport($pdo);
            break;
            
        case 'summary':
            generateSummaryReport($pdo);
            break;
            
        default:
            Response::error('Invalid report type', 400);
    }
}
?>


