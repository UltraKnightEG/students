-- إنشاء جداول نظام إدارة حضور الطلاب (نسخة SQLite)

-- جدول المستخدمين
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    email TEXT,
    full_name TEXT,
    role TEXT DEFAULT 'staff' CHECK(role IN ('admin', 'teacher', 'staff')),
    is_active INTEGER DEFAULT 1,
    last_login TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- جدول الفصول
CREATE TABLE IF NOT EXISTS classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    grade_level TEXT,
    capacity INTEGER DEFAULT 30,
    teacher_name TEXT,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- جدول الطلاب
CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    barcode TEXT UNIQUE NOT NULL,
    class_id INTEGER,
    parent_phone TEXT,
    parent_email TEXT,
    grade_level TEXT,
    date_of_birth TEXT,
    address TEXT,
    emergency_contact TEXT,
    medical_notes TEXT,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
);

-- جدول الجلسات
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id INTEGER NOT NULL,
    subject TEXT NOT NULL,
    description TEXT,
    date TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT,
    status TEXT DEFAULT 'scheduled' CHECK(status IN ('scheduled', 'active', 'completed', 'cancelled')),
    quiz_total_score INTEGER DEFAULT 10,
    teacher_id INTEGER,
    location TEXT,
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
);

-- جدول الحضور
CREATE TABLE IF NOT EXISTS attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    session_id INTEGER NOT NULL,
    attendance_time TEXT DEFAULT CURRENT_TIMESTAMP,
    status TEXT DEFAULT 'present' CHECK(status IN ('present', 'absent', 'late', 'excused')),
    teacher_rating INTEGER CHECK (teacher_rating >= 1 AND teacher_rating <= 5),
    quiz_score REAL,
    behavior_notes TEXT,
    parent_notified INTEGER DEFAULT 0,
    notification_sent_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    UNIQUE (student_id, session_id)
);

-- جدول سجل الأنشطة
CREATE TABLE IF NOT EXISTS activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    table_name TEXT,
    record_id INTEGER,
    old_values TEXT,
    new_values TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- جدول الإعدادات
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- إدراج بيانات تجريبية
-- يجب إدراج بيانات الفصول أولاً لأن الطلاب يعتمدون عليها
INSERT INTO classes (name, description, grade_level, capacity, teacher_name) VALUES
('الصف الأول الابتدائي', 'فصل تجريبي للاختبار', 'الأول الابتدائي', 30, 'أحمد محمد'),
('الصف الثاني الابتدائي', 'فصل تجريبي للاختبار', 'الثاني الابتدائي', 28, 'فاطمة علي'),
('الصف الثالث الابتدائي', 'فصل تجريبي للاختبار', 'الثالث الابتدائي', 32, 'محمد سالم');

INSERT INTO students (name, barcode, class_id, parent_phone, grade_level) VALUES
('عبدالله أحمد', 'STU001', 1, '966501234567', 'ابتدائي'),
('مريم محمد', 'STU002', 1, '966507654321', 'ابتدائي'),
('خالد سالم', 'STU003', 2, '966509876543', 'ابتدائي'),
('نورا علي', 'STU004', 2, '966502468135', 'ابتدائي'),
('يوسف عبدالرحمن', 'STU005', 3, '966508642097', 'ابتدائي');

INSERT INTO sessions (class_id, subject, description, date, start_time, status) VALUES
(1, 'الرياضيات', 'جلسة تجريبية للاختبار', DATE('now'), '08:00:00', 'active'),
(2, 'اللغة العربية', 'جلسة تجريبية للاختبار', DATE('now'), '09:00:00', 'scheduled'),
(3, 'العلوم', 'جلسة تجريبية للاختبار', DATE('now'), '10:00:00', 'scheduled');

-- إدراج بعض بيانات الحضور التجريبية
INSERT INTO attendance (student_id, session_id, status, teacher_rating, quiz_score) VALUES
(1, 1, 'present', 5, 9.5),
(2, 1, 'present', 4, 8.0),
(3, 2, 'present', 5, 10.0);

-- إدراج الإعدادات الافتراضية
INSERT INTO settings (setting_key, setting_value, description) VALUES
('system_name', 'نظام إدارة حضور الطلاب', 'اسم النظام'),
('timezone', 'Asia/Riyadh', 'المنطقة الزمنية'),
('default_session_duration', '60', 'مدة الجلسة الافتراضية بالدقائق'),
('whatsapp_enabled', '1', 'تفعيل خدمة الواتساب'),
('auto_notifications', '1', 'تفعيل الإشعارات التلقائية');


