-- إنشاء جداول نظام إدارة حضور الطلاب
USE attendance_system;

-- جدول المستخدمين
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    full_name VARCHAR(100),
    role ENUM(\'admin\', \'teacher\', \'staff\') DEFAULT \'staff\',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- جدول الفصول
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    grade_level VARCHAR(50),
    capacity INT DEFAULT 30,
    teacher_name VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- جدول الطلاب
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    barcode VARCHAR(50) UNIQUE NOT NULL,
    class_id INT,
    parent_phone VARCHAR(20),
    parent_email VARCHAR(100),
    grade_level VARCHAR(50),
    date_of_birth DATE,
    address TEXT,
    emergency_contact VARCHAR(20),
    medical_notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
);

-- جدول الجلسات
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    description TEXT,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME,
    status ENUM(\'scheduled\', \'active\', \'completed\', \'cancelled\') DEFAULT \'scheduled\',
    quiz_total_score INT DEFAULT 10,
    teacher_id INT,
    location VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
);

-- جدول الحضور
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    attendance_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM(\'present\', \'absent\', \'late\', \'excused\') DEFAULT \'present\',
    teacher_rating INT CHECK (teacher_rating >= 1 AND teacher_rating <= 5),
    quiz_score DECIMAL(5,2),
    behavior_notes TEXT,
    parent_notified BOOLEAN DEFAULT FALSE,
    notification_sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (student_id, session_id)
);

-- جدول سجل الأنشطة
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- جدول الإعدادات
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- إدراج بيانات تجريبية
-- يجب إدراج بيانات الفصول أولاً لأن الطلاب يعتمدون عليها
INSERT INTO classes (name, description, grade_level, capacity, teacher_name) VALUES
(\'الصف الأول الابتدائي\', \'فصل تجريبي للاختبار\', \'الأول الابتدائي\', 30, \'أحمد محمد\'),
(\'الصف الثاني الابتدائي\', \'فصل تجريبي للاختبار\', \'الثاني الابتدائي\', 28, \'فاطمة علي\'),
(\'الصف الثالث الابتدائي\', \'فصل تجريبي للاختبار\', \'الثالث الابتدائي\', 32, \'محمد سالم\')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO students (name, barcode, class_id, parent_phone, grade_level) VALUES
(\'عبدالله أحمد\', \'STU001\', 1, \'966501234567\', \'ابتدائي\'),
(\'مريم محمد\', \'STU002\', 1, \'966507654321\', \'ابتدائي\'),
(\'خالد سالم\', \'STU003\', 2, \'966509876543\', \'ابتدائي\'),
(\'نورا علي\', \'STU004\', 2, \'966502468135\', \'ابتدائي\'),
(\'يوسف عبدالرحمن\', \'STU005\', 3, \'966508642097\', \'ابتدائي\')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO sessions (class_id, subject, description, date, start_time, status) VALUES
(1, \'الرياضيات\', \'جلسة تجريبية للاختبار\', CURDATE(), \'08:00:00\', \'active\'),
(2, \'اللغة العربية\', \'جلسة تجريبية للاختبار\', CURDATE(), \'09:00:00\', \'scheduled\'),
(3, \'العلوم\', \'جلسة تجريبية للاختبار\', CURDATE(), \'10:00:00\', \'scheduled\')
ON DUPLICATE KEY UPDATE subject = VALUES(subject);

-- إدراج بعض بيانات الحضور التجريبية
INSERT INTO attendance (student_id, session_id, status, teacher_rating, quiz_score) VALUES
(1, 1, \'present\', 5, 9.5),
(2, 1, \'present\', 4, 8.0),
(3, 2, \'present\', 5, 10.0)
ON DUPLICATE KEY UPDATE status = VALUES(status);

-- إدراج الإعدادات الافتراضية
INSERT INTO settings (setting_key, setting_value, description) VALUES
(\'system_name\', \'نظام إدارة حضور الطلاب\', \'اسم النظام\'),
(\'timezone\', \'Asia/Riyadh\', \'المنطقة الزمنية\'),
(\'default_session_duration\', \'60\', \'مدة الجلسة الافتراضية بالدقائق\'),
(\'whatsapp_enabled\', \'1\', \'تفعيل خدمة الواتساب\'),
(\'auto_notifications\', \'1\', \'تفعيل الإشعارات التلقائية\')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

