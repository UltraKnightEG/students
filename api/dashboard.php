<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/../logs/php_error.log");

require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$db = null;
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error in dashboard.php: " . $e->getMessage());
    Response::error('فشل الاتصال بقاعدة البيانات: ' . $e->getMessage(), 500);
}

$response = new Response();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $response->error('Method not allowed', 405);
        return;
    }
    
    $action = $_GET['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            getDashboardOverview($db, $response);
            break;
            
        case 'stats':
            getDashboardStats($db, $response);
            break;
            
        case 'charts':
            getDashboardCharts($db, $response);
            break;
            
        case 'recent':
            getRecentActivity($db, $response);
            break;
            
        case 'alerts':
            getDashboardAlerts($db, $response);
            break;
            
        default:
            $response->error('Invalid action', 400);
    }
    
} catch (Exception $e) {
    error_log("Dashboard API Fatal Error: " . $e->getMessage());
    $response->error('Internal server error: ' . $e->getMessage(), 500);
}

function getDashboardOverview($db, $response) {
    // الإحصائيات الأساسية
    $basicStatsQuery = "
        SELECT 
            (SELECT COUNT(*) FROM students) as total_students,
            (SELECT COUNT(*) FROM classes) as total_classes,
            (SELECT COUNT(*) FROM sessions WHERE status = 'active') as active_sessions,
            (SELECT COUNT(*) FROM sessions WHERE status = 'completed') as completed_sessions,
            (SELECT COUNT(*) FROM attendance WHERE DATE(attendance_time) = CURDATE()) as today_attendance,
            (SELECT COUNT(DISTINCT student_id) FROM attendance WHERE DATE(attendance_time) = CURDATE()) as today_unique_students
    ";
    
    $basicStats = $db->fetchOne($basicStatsQuery);
    
    // إحصائيات الحضور اليوم
    $todayStatsQuery = "
        SELECT 
            COUNT(DISTINCT s.id) as total_students_today,
            COUNT(DISTINCT a.id) as present_students_today,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id), 0)), 0) as today_attendance_rate
        FROM sessions sess
        LEFT JOIN students s ON sess.class_id = s.class_id
        LEFT JOIN attendance a ON sess.id = a.session_id AND s.id = a.student_id
        WHERE DATE(sess.date) = CURDATE() AND sess.status IN ('active', 'completed')
    ";
    
    $todayStats = $db->fetchOne($todayStatsQuery);
    
    // إحصائيات الأسبوع الحالي
    $weekStatsQuery = "
        SELECT 
            COUNT(DISTINCT sess.id) as week_sessions,
            COUNT(DISTINCT a.id) as week_attendance,
            COUNT(DISTINCT a.student_id) as week_unique_students
        FROM sessions sess
        LEFT JOIN attendance a ON sess.id = a.session_id
        WHERE WEEK(sess.date) = WEEK(CURDATE()) AND YEAR(sess.date) = YEAR(CURDATE())
    ";
    
    $weekStats = $db->fetchOne($weekStatsQuery);
    
    // إحصائيات الشهر الحالي
    $monthStatsQuery = "
        SELECT 
            COUNT(DISTINCT sess.id) as month_sessions,
            COUNT(DISTINCT a.id) as month_attendance,
            COUNT(DISTINCT a.student_id) as month_unique_students,
            AVG(a.quiz_score) as month_avg_quiz_score
        FROM sessions sess
        LEFT JOIN attendance a ON sess.id = a.session_id
        WHERE MONTH(sess.date) = MONTH(CURDATE()) AND YEAR(sess.date) = YEAR(CURDATE())
    ";
    
    $monthStats = $db->fetchOne($monthStatsQuery);
    
    // أفضل الفصول حضوراً
    $topClassesQuery = "
        SELECT 
            c.name as class_name,
            c.grade_level,
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT a.id) as total_attendance,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id) * COUNT(DISTINCT sess.id), 0)), 0) as attendance_rate
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND sess.status = 'completed' AND MONTH(sess.date) = MONTH(CURDATE())
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
        GROUP BY c.id
        HAVING total_students > 0
        ORDER BY attendance_rate DESC
        LIMIT 5
    ";
    
    $topClasses = $db->fetchAll($topClassesQuery);
    
    // تنسيق البيانات
    foreach ($topClasses as &$class) {
        $class['attendance_rate'] = round(floatval($class['attendance_rate']), 2);
    }
    
    $response->success([
        'basic_stats' => [
            'total_students' => intval($basicStats['total_students']),
            'total_classes' => intval($basicStats['total_classes']),
            'active_sessions' => intval($basicStats['active_sessions']),
            'completed_sessions' => intval($basicStats['completed_sessions']),
            'today_attendance' => intval($basicStats['today_attendance']),
            'today_unique_students' => intval($basicStats['today_unique_students'])
        ],
        'today_stats' => [
            'total_students' => intval($todayStats['total_students_today']),
            'present_students' => intval($todayStats['present_students_today']),
            'absent_students' => intval($todayStats['total_students_today']) - intval($todayStats['present_students_today']),
            'attendance_rate' => round(floatval($todayStats['today_attendance_rate']), 2)
        ],
        'week_stats' => [
            'sessions' => intval($weekStats['week_sessions']),
            'attendance' => intval($weekStats['week_attendance']),
            'unique_students' => intval($weekStats['week_unique_students'])
        ],
        'month_stats' => [
            'sessions' => intval($monthStats['month_sessions']),
            'attendance' => intval($monthStats['month_attendance']),
            'unique_students' => intval($monthStats['month_unique_students']),
            'avg_quiz_score' => round(floatval($monthStats['month_avg_quiz_score']), 2)
        ],
        'top_classes' => $topClasses
    ]);
}

function getDashboardStats($db, $response) {
    $period = $_GET['period'] ?? 'week'; // week, month, year
    
    // تحديد الفترة الزمنية
    switch ($period) {
        case 'week':
            $dateCondition = "WEEK(sess.date) = WEEK(CURDATE()) AND YEAR(sess.date) = YEAR(CURDATE())";
            break;
        case 'month':
            $dateCondition = "MONTH(sess.date) = MONTH(CURDATE()) AND YEAR(sess.date) = YEAR(CURDATE())";
            break;
        case 'year':
            $dateCondition = "YEAR(sess.date) = YEAR(CURDATE())";
            break;
        default:
            $dateCondition = "WEEK(sess.date) = WEEK(CURDATE()) AND YEAR(sess.date) = YEAR(CURDATE())";
    }
    
    // إحصائيات الحضور حسب الفصل
    $classStatsQuery = "
        SELECT 
            c.id,
            c.name as class_name,
            c.grade_level,
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT sess.id) as total_sessions,
            COUNT(DISTINCT a.id) as total_attendance,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id) * COUNT(DISTINCT sess.id), 0)), 0) as attendance_rate,
            AVG(a.quiz_score) as avg_quiz_score
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND $dateCondition
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
        GROUP BY c.id
        ORDER BY c.name
    ";
    
    $classStats = $db->fetchAll($classStatsQuery);
    
    // إحصائيات الطلاب الأكثر حضوراً
    $topStudentsQuery = "
        SELECT 
            s.id,
            s.name as student_name,
            c.name as class_name,
            COUNT(a.id) as attendance_count,
            AVG(a.quiz_score) as avg_quiz_score,
            COUNT(CASE WHEN a.teacher_rating = 'ممتاز' THEN 1 END) as excellent_count
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN attendance a ON s.id = a.student_id
        LEFT JOIN sessions sess ON a.session_id = sess.id AND $dateCondition
        GROUP BY s.id
        HAVING attendance_count > 0
        ORDER BY attendance_count DESC, avg_quiz_score DESC
        LIMIT 10
    ";
    
    $topStudents = $db->fetchAll($topStudentsQuery);
    
    // إحصائيات المواد
    $subjectStatsQuery = "
        SELECT 
            sess.subject,
            COUNT(sess.id) as session_count,
            COUNT(a.id) as attendance_count,
            AVG(a.quiz_score) as avg_quiz_score,
            COUNT(DISTINCT a.student_id) as unique_students
        FROM sessions sess
        LEFT JOIN attendance a ON sess.id = a.session_id
        WHERE $dateCondition
        GROUP BY sess.subject
        ORDER BY session_count DESC
    ";
    
    $subjectStats = $db->fetchAll($subjectStatsQuery);
    
    // تنسيق البيانات
    foreach ($classStats as &$class) {
        $class['attendance_rate'] = round(floatval($class['attendance_rate']), 2);
        $class['avg_quiz_score'] = round(floatval($class['avg_quiz_score']), 2);
    }
    
    foreach ($topStudents as &$student) {
        $student['avg_quiz_score'] = round(floatval($student['avg_quiz_score']), 2);
    }
    
    foreach ($subjectStats as &$subject) {
        $subject['avg_quiz_score'] = round(floatval($subject['avg_quiz_score']), 2);
    }
    
    $response->success([
        'period' => $period,
        'class_stats' => $classStats,
        'top_students' => $topStudents,
        'subject_stats' => $subjectStats
    ]);
}

function getDashboardCharts($db, $response) {
    $type = $_GET['type'] ?? 'attendance_trend';
    
    switch ($type) {
        case 'attendance_trend':
            getAttendanceTrendChart($db, $response);
            break;
            
        case 'class_distribution':
            getClassDistributionChart($db, $response);
            break;
            
        case 'performance_distribution':
            getPerformanceDistributionChart($db, $response);
            break;
            
        case 'weekly_comparison':
            getWeeklyComparisonChart($db, $response);
            break;
            
        default:
            $response->error('Invalid chart type', 400);
    }
}

function getAttendanceTrendChart($db, $response) {
    $days = intval($_GET['days'] ?? 7); // آخر 7 أيام افتراضياً
    
    $query = "
        SELECT 
            DATE(sess.date) as date,
            COUNT(DISTINCT sess.id) as sessions_count,
            COUNT(DISTINCT a.id) as attendance_count,
            COUNT(DISTINCT a.student_id) as unique_students,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id), 0)), 0) as attendance_rate
        FROM sessions sess
        LEFT JOIN students s ON sess.class_id = s.class_id
        LEFT JOIN attendance a ON sess.id = a.session_id AND s.id = a.student_id
        WHERE sess.date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(sess.date)
        ORDER BY date
    ";
    
    $trendData = $db->fetchAll($query, [$days]);
    
    // تنسيق البيانات للرسم البياني
    $labels = [];
    $attendanceData = [];
    $sessionsData = [];
    $rateData = [];
    
    foreach ($trendData as $day) {
        $labels[] = date('M d', strtotime($day['date']));
        $attendanceData[] = intval($day['attendance_count']);
        $sessionsData[] = intval($day['sessions_count']);
        $rateData[] = round(floatval($day['attendance_rate']), 2);
    }
    
    $response->success([
        'chart_type' => 'line',
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'عدد الحضور',
                'data' => $attendanceData,
                'borderColor' => '#4CAF50',
                'backgroundColor' => 'rgba(76, 175, 80, 0.1)'
            ],
            [
                'label' => 'عدد الجلسات',
                'data' => $sessionsData,
                'borderColor' => '#2196F3',
                'backgroundColor' => 'rgba(33, 150, 243, 0.1)'
            ]
        ],
        'rate_data' => [
            'label' => 'معدل الحضور (%)',
            'data' => $rateData,
            'borderColor' => '#FF9800',
            'backgroundColor' => 'rgba(255, 152, 0, 0.1)'
        ]
    ]);
}

function getClassDistributionChart($db, $response) {
    $query = "
        SELECT 
            c.name as class_name,
            COUNT(DISTINCT s.id) as student_count,
            COUNT(DISTINCT a.id) as attendance_count,
            COALESCE((COUNT(DISTINCT a.id) * 100.0 / NULLIF(COUNT(DISTINCT s.id) * COUNT(DISTINCT sess.id), 0)), 0) as attendance_rate
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND MONTH(sess.date) = MONTH(CURDATE())
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
        GROUP BY c.id
        ORDER BY student_count DESC
    ";
    
    $classData = $db->fetchAll($query);
    
    $labels = [];
    $studentCounts = [];
    $attendanceRates = [];
    $colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40'];
    
    foreach ($classData as $index => $class) {
        $labels[] = $class['class_name'];
        $studentCounts[] = intval($class['student_count']);
        $attendanceRates[] = round(floatval($class['attendance_rate']), 2);
    }
    
    $response->success([
        'chart_type' => 'doughnut',
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'عدد الطلاب',
                'data' => $studentCounts,
                'backgroundColor' => array_slice($colors, 0, count($labels)),
                'borderWidth' => 2
            ]
        ],
        'attendance_rates' => $attendanceRates
    ]);
}

function getPerformanceDistributionChart($db, $response) {
    $query = "
        SELECT 
            CASE 
                WHEN AVG(a.quiz_score) >= 90 THEN 'ممتاز (90-100)'
                WHEN AVG(a.quiz_score) >= 80 THEN 'جيد جداً (80-89)'
                WHEN AVG(a.quiz_score) >= 70 THEN 'جيد (70-79)'
                WHEN AVG(a.quiz_score) >= 60 THEN 'مقبول (60-69)'
                ELSE 'ضعيف (أقل من 60)'
            END as performance_level,
            COUNT(DISTINCT s.id) as student_count
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id
        LEFT JOIN sessions sess ON a.session_id = sess.id AND MONTH(sess.date) = MONTH(CURDATE())
        WHERE a.quiz_score IS NOT NULL
        GROUP BY s.id
        HAVING AVG(a.quiz_score) IS NOT NULL
    ";
    
    $performanceData = $db->fetchAll($query);
    
    // تجميع البيانات حسب مستوى الأداء
    $distribution = [
        'ممتاز (90-100)' => 0,
        'جيد جداً (80-89)' => 0,
        'جيد (70-79)' => 0,
        'مقبول (60-69)' => 0,
        'ضعيف (أقل من 60)' => 0
    ];
    
    foreach ($performanceData as $data) {
        $distribution[$data['performance_level']] += intval($data['student_count']);
    }
    
    $labels = array_keys($distribution);
    $values = array_values($distribution);
    $colors = ['#4CAF50', '#8BC34A', '#FFC107', '#FF9800', '#F44336'];
    
    $response->success([
        'chart_type' => 'pie',
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'توزيع الأداء',
                'data' => $values,
                'backgroundColor' => $colors,
                'borderWidth' => 2
            ]
        ]
    ]);
}

function getWeeklyComparisonChart($db, $response) {
    $weeks = intval($_GET['weeks'] ?? 4); // آخر 4 أسابيع افتراضياً
    
    $query = "
        SELECT 
            WEEK(sess.date) as week_number,
            YEAR(sess.date) as year,
            COUNT(DISTINCT sess.id) as sessions_count,
            COUNT(DISTINCT a.id) as attendance_count,
            COUNT(DISTINCT a.student_id) as unique_students
        FROM sessions sess
        LEFT JOIN attendance a ON sess.id = a.session_id
        WHERE sess.date >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
        GROUP BY YEAR(sess.date), WEEK(sess.date)
        ORDER BY year, week_number
    ";
    
    $weeklyData = $db->fetchAll($query, [$weeks]);
    
    $labels = [];
    $sessionsData = [];
    $attendanceData = [];
    $studentsData = [];
    
    foreach ($weeklyData as $week) {
        $labels[] = "أسبوع " . $week['week_number'];
        $sessionsData[] = intval($week['sessions_count']);
        $attendanceData[] = intval($week['attendance_count']);
        $studentsData[] = intval($week['unique_students']);
    }
    
    $response->success([
        'chart_type' => 'bar',
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'عدد الجلسات',
                'data' => $sessionsData,
                'backgroundColor' => '#2196F3',
                'borderColor' => '#1976D2',
                'borderWidth' => 1
            ],
            [
                'label' => 'عدد الحضور',
                'data' => $attendanceData,
                'backgroundColor' => '#4CAF50',
                'borderColor' => '#388E3C',
                'borderWidth' => 1
            ],
            [
                'label' => 'الطلاب الفريدون',
                'data' => $studentsData,
                'backgroundColor' => '#FF9800',
                'borderColor' => '#F57C00',
                'borderWidth' => 1
            ]
        ]
    ]);
}

function getRecentActivity($db, $response) {
    $limit = intval($_GET['limit'] ?? 10);
    
    // آخر سجلات الحضور
    $recentAttendanceQuery = "
        SELECT 
            'attendance' as type,
            a.attendance_time as timestamp,
            s.name as student_name,
            c.name as class_name,
            sess.subject,
            'تم تسجيل حضور الطالب' as action
        FROM attendance a
        LEFT JOIN students s ON a.student_id = s.id
        LEFT JOIN sessions sess ON a.session_id = sess.id
        LEFT JOIN classes c ON sess.class_id = c.id
        ORDER BY a.attendance_time DESC
        LIMIT ?
    ";
    
    $recentAttendance = $db->fetchAll($recentAttendanceQuery, [$limit]);
    
    // آخر الجلسات المُنشأة
    $recentSessionsQuery = "
        SELECT 
            'session' as type,
            sess.created_at as timestamp,
            sess.subject,
            c.name as class_name,
            'تم إنشاء جلسة جديدة' as action
        FROM sessions sess
        LEFT JOIN classes c ON sess.class_id = c.id
        ORDER BY sess.created_at DESC
        LIMIT ?
    ";
    
    $recentSessions = $db->fetchAll($recentSessionsQuery, [$limit]);
    
    // دمج النشاطات وترتيبها
    $allActivities = array_merge($recentAttendance, $recentSessions);
    
    // ترتيب حسب الوقت
    usort($allActivities, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    // أخذ العدد المطلوب فقط
    $activities = array_slice($allActivities, 0, $limit);
    
    $response->success($activities);
}

function getDashboardAlerts($db, $response) {
    $alerts = [];
    
    // تنبيه الجلسات النشطة
    $activeSessionsQuery = "
        SELECT 
            sess.id,
            sess.subject,
            c.name as class_name,
            sess.start_time,
            COUNT(a.id) as attendance_count,
            COUNT(DISTINCT s.id) as total_students
        FROM sessions sess
        LEFT JOIN classes c ON sess.class_id = c.id
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN attendance a ON sess.id = a.session_id AND s.id = a.student_id
        WHERE sess.status = 'active'
        GROUP BY sess.id
    ";
    
    $activeSessions = $db->fetchAll($activeSessionsQuery);
    
    foreach ($activeSessions as $session) {
        $attendanceRate = $session['total_students'] > 0 ? 
            round(($session['attendance_count'] * 100.0) / $session['total_students'], 2) : 0;
        
        if ($attendanceRate < 50) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'معدل حضور منخفض',
                'message' => "جلسة {$session['subject']} في {$session['class_name']} لديها معدل حضور {$attendanceRate}%",
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    // تنبيه الطلاب كثيري الغياب
    $absentStudentsQuery = "
        SELECT 
            s.name as student_name,
            c.name as class_name,
            COUNT(sess.id) as total_sessions,
            COUNT(a.id) as attended_sessions,
            (COUNT(sess.id) - COUNT(a.id)) as absent_sessions
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN sessions sess ON c.id = sess.class_id AND sess.status = 'completed' AND MONTH(sess.date) = MONTH(CURDATE())
        LEFT JOIN attendance a ON s.id = a.student_id AND sess.id = a.session_id
        GROUP BY s.id
        HAVING absent_sessions >= 3
        ORDER BY absent_sessions DESC
        LIMIT 5
    ";
    
    $absentStudents = $db->fetchAll($absentStudentsQuery);
    
    foreach ($absentStudents as $student) {
        $alerts[] = [
            'type' => 'danger',
            'title' => 'طالب كثير الغياب',
            'message' => "الطالب {$student['student_name']} من {$student['class_name']} غاب {$student['absent_sessions']} مرات هذا الشهر",
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // تنبيه الفصول بدون جلسات اليوم
    $classesWithoutSessionsQuery = "
        SELECT 
            c.name as class_name,
            c.grade_level
        FROM classes c
        LEFT JOIN sessions sess ON c.id = sess.class_id AND DATE(sess.date) = CURDATE()
        WHERE sess.id IS NULL
    ";
    
    $classesWithoutSessions = $db->fetchAll($classesWithoutSessionsQuery);
    
    foreach ($classesWithoutSessions as $class) {
        $alerts[] = [
            'type' => 'info',
            'title' => 'لا توجد جلسات اليوم',
            'message' => "الفصل {$class['class_name']} ({$class['grade_level']}) ليس لديه جلسات مجدولة اليوم",
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // ترتيب التنبيهات حسب الأهمية
    $priorityOrder = ['danger' => 1, 'warning' => 2, 'info' => 3];
    usort($alerts, function($a, $b) use ($priorityOrder) {
        return $priorityOrder[$a['type']] - $priorityOrder[$b['type']];
    });
    
    $response->success([
        'alerts' => array_slice($alerts, 0, 10), // أقصى 10 تنبيهات
        'total_alerts' => count($alerts)
    ]);
}
?>


