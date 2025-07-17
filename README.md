# نظام إدارة حضور الطلاب - النسخة النهائية المتكاملة

## نظرة عامة

نظام إدارة حضور الطلاب هو حل شامل ومتطور لإدارة حضور الطلاب في المؤسسات التعليمية. يتميز النظام بواجهة مستخدم عصرية وسهلة الاستخدام، مع دعم كامل لماسح الباركود وإرسال الإشعارات عبر الواتساب.

## الميزات الرئيسية

### 🎯 إدارة شاملة للحضور
- **ماسح الباركود المتقدم**: تسجيل سريع ودقيق للحضور باستخدام كاميرا الجهاز
- **التسجيل السريع**: إمكانية تسجيل طلاب جدد أثناء عملية الحضور
- **الانتقال التلقائي**: انتقال تلقائي للطالب التالي بعد تسجيل الحضور
- **تقييم المعلم**: إمكانية إعطاء تقييم للطالب من 1-5 نجوم
- **درجات الاختبارات**: تسجيل درجات الاختبارات والواجبات

### 👥 إدارة الطلاب والفصول
- **قاعدة بيانات شاملة**: معلومات كاملة عن الطلاب وأولياء الأمور
- **إدارة الفصول**: تنظيم الطلاب في فصول مختلفة
- **البحث والتصفية**: بحث سريع عن الطلاب والفصول
- **الإحصائيات المفصلة**: معدلات الحضور والأداء لكل طالب

### 📊 التقارير والإحصائيات
- **تقارير مفصلة**: تقارير يومية وأسبوعية وشهرية
- **الرسوم البيانية**: عرض بصري للإحصائيات والاتجاهات
- **تصدير البيانات**: تصدير التقارير بصيغ مختلفة (PDF, Excel)
- **تحليل الأداء**: تحليل شامل لأداء الطلاب والفصول

### 📱 خدمة الواتساب المتقدمة
- **إشعارات تلقائية**: إرسال تقارير الحضور لأولياء الأمور
- **تنبيهات الغياب**: إشعارات فورية عند غياب الطالب
- **رسائل مخصصة**: إمكانية إرسال رسائل مخصصة
- **إدارة القوائم**: إدارة قوائم أولياء الأمور والمجموعات

### 🎨 واجهة مستخدم عصرية
- **تصميم متجاوب**: يعمل على جميع الأجهزة (كمبيوتر، تابلت، هاتف)
- **دعم اللغة العربية**: واجهة كاملة باللغة العربية مع دعم RTL
- **ألوان متناسقة**: تصميم عصري بألوان جذابة ومريحة للعين
- **سهولة الاستخدام**: واجهة بديهية وسهلة التعلم

## المتطلبات التقنية

### متطلبات الخادم
- **PHP**: الإصدار 8.1 أو أحدث
- **قاعدة البيانات**: SQLite (مدمجة) أو MySQL
- **خادم الويب**: Apache أو Nginx أو PHP Built-in Server
- **الامتدادات المطلوبة**:
  - PDO
  - PDO_SQLite
  - JSON
  - OpenSSL
  - cURL

### متطلبات خدمة الواتساب
- **Node.js**: الإصدار 16.0.0 أو أحدث
- **npm**: الإصدار 8.0.0 أو أحدث
- **Google Chrome**: للتشغيل الآلي للواتساب

### متطلبات المتصفح
- **المتصفحات المدعومة**:
  - Google Chrome (الموصى به)
  - Mozilla Firefox
  - Safari
  - Microsoft Edge
- **الكاميرا**: مطلوبة لماسح الباركود
- **JavaScript**: يجب أن يكون مفعلاً

## هيكل المشروع

```
attendance_system_final/
├── api/                          # ملفات APIs
│   ├── students.php             # API إدارة الطلاب
│   ├── classes.php              # API إدارة الفصول
│   ├── sessions.php             # API إدارة الجلسات
│   ├── attendance.php           # API إدارة الحضور
│   ├── reports.php              # API التقارير
│   └── dashboard.php            # API لوحة التحكم
├── assets/                       # الملفات الثابتة
│   ├── css/
│   │   └── dashboard.css        # ملف التصميم الرئيسي
│   └── js/
│       └── dashboard.js         # ملف JavaScript الرئيسي
├── config/                       # ملفات الإعدادات
│   ├── database.php             # إعدادات MySQL
│   └── database_sqlite.php      # إعدادات SQLite
├── database/                     # ملفات قاعدة البيانات
│   └── attendance_system.db     # قاعدة بيانات SQLite
├── whatsapp_service/            # خدمة الواتساب
│   ├── server.js                # خادم الواتساب
│   ├── package.json             # تبعيات Node.js
│   └── README.md                # دليل خدمة الواتساب
├── dashboard.html               # الواجهة الأمامية الرئيسية
├── test_sqlite.php              # ملف اختبار النظام
├── basic_test.php               # ملف اختبار أساسي
└── README.md                    # هذا الملف
```

## التثبيت والإعداد

### 1. تحضير البيئة

#### تثبيت PHP وامتداداته
```bash
# Ubuntu/Debian
sudo apt update
sudo apt install php php-sqlite3 php-json php-curl php-openssl

# CentOS/RHEL
sudo yum install php php-pdo php-sqlite3 php-json php-curl php-openssl
```

#### تثبيت Node.js (لخدمة الواتساب)
```bash
# Ubuntu/Debian
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# أو باستخدام nvm
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
nvm install 18
nvm use 18
```

### 2. تحميل وإعداد النظام

```bash
# تحميل النظام
wget https://example.com/attendance_system_final.zip
unzip attendance_system_final.zip
cd attendance_system_final

# إعداد الصلاحيات
chmod 755 -R .
chmod 777 database/
```

### 3. إعداد قاعدة البيانات

#### استخدام SQLite (الموصى به للبداية)
```bash
# لا حاجة لإعداد إضافي - سيتم إنشاء قاعدة البيانات تلقائياً
php test_sqlite.php
```

#### استخدام MySQL (للبيئات الإنتاجية)
```bash
# إنشاء قاعدة البيانات
mysql -u root -p -e "CREATE DATABASE attendance_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# تحديث إعدادات الاتصال في config/database.php
# تنفيذ ملف SQL لإنشاء الجداول
mysql -u root -p attendance_system < create_tables.sql
```

### 4. تشغيل النظام

#### تشغيل خادم PHP
```bash
# للتطوير والاختبار
php -S 0.0.0.0:8080

# للإنتاج - استخدم Apache أو Nginx
```

#### تشغيل خدمة الواتساب
```bash
cd whatsapp_service
npm install
npm start
```

### 5. الوصول للنظام

- **الواجهة الرئيسية**: http://localhost:8080/dashboard.html
- **اختبار النظام**: http://localhost:8080/test_sqlite.php
- **خدمة الواتساب**: http://localhost:3000

## دليل الاستخدام

### البدء السريع

1. **فتح النظام**: انتقل إلى الواجهة الرئيسية
2. **إنشاء فصل جديد**: من قائمة "إدارة الفصول"
3. **إضافة طلاب**: من قائمة "إدارة الطلاب"
4. **إنشاء جلسة**: من قائمة "الجلسات"
5. **تسجيل الحضور**: استخدم "ماسح الحضور"

### استخدام ماسح الباركود

1. انقر على "ماسح الحضور" في القائمة الرئيسية
2. اختر الجلسة المطلوبة
3. امنح الإذن للكاميرا عند الطلب
4. وجه الكاميرا نحو باركود الطالب
5. سيتم تسجيل الحضور تلقائياً والانتقال للطالب التالي

### إعداد خدمة الواتساب

1. تشغيل خدمة الواتساب
2. زيارة http://localhost:3000/api/qr
3. مسح QR Code باستخدام تطبيق الواتساب
4. انتظار تأكيد الاتصال
5. تفعيل الإشعارات التلقائية من إعدادات النظام

### إنتاج التقارير

1. انتقل إلى قسم "التقارير"
2. اختر نوع التقرير المطلوب
3. حدد الفترة الزمنية
4. اختر الفصل أو الطالب
5. انقر على "إنتاج التقرير"
6. يمكن تصدير التقرير بصيغة PDF أو Excel

## الأمان والحماية

### حماية البيانات
- **تشفير البيانات الحساسة**: استخدام AES-256 لتشفير البيانات المهمة
- **حماية قاعدة البيانات**: استخدام Prepared Statements لمنع SQL Injection
- **التحقق من المدخلات**: فلترة وتنظيف جميع المدخلات
- **إدارة الجلسات**: حماية جلسات المستخدمين

### أفضل الممارسات الأمنية
- تغيير كلمات المرور الافتراضية
- استخدام HTTPS في البيئة الإنتاجية
- تحديث النظام بانتظام
- عمل نسخ احتياطية دورية
- مراقبة سجلات النظام

## استكشاف الأخطاء

### مشاكل شائعة وحلولها

#### 1. خطأ في الاتصال بقاعدة البيانات
```
خطأ: could not find driver
```
**الحل**: تثبيت امتداد SQLite
```bash
sudo apt install php-sqlite3
```

#### 2. مشكلة في ماسح الباركود
```
خطأ: الكاميرا غير متاحة
```
**الحل**: 
- التأكد من منح الإذن للكاميرا
- استخدام HTTPS أو localhost
- التحقق من دعم المتصفح للكاميرا

#### 3. فشل في إرسال رسائل الواتساب
```
خطأ: الواتساب غير متصل
```
**الحل**:
- إعادة مسح QR Code
- التحقق من اتصال الإنترنت
- إعادة تشغيل خدمة الواتساب

#### 4. بطء في تحميل الصفحات
**الحل**:
- تحسين إعدادات PHP
- استخدام خادم ويب مخصص بدلاً من PHP Built-in Server
- تحسين قاعدة البيانات

### ملفات السجلات

```bash
# سجلات PHP
tail -f /var/log/php_errors.log

# سجلات خدمة الواتساب
tail -f whatsapp_service/logs/combined.log

# سجلات خادم الويب
tail -f /var/log/apache2/error.log
```

## التخصيص والتطوير

### تخصيص الواجهة

#### تغيير الألوان
```css
/* في ملف assets/css/dashboard.css */
:root {
    --primary-color: #your-color;
    --secondary-color: #your-color;
    --accent-color: #your-color;
}
```

#### إضافة شعار المؤسسة
```html
<!-- في ملف dashboard.html -->
<img src="assets/images/logo.png" alt="شعار المؤسسة" class="logo">
```

### إضافة ميزات جديدة

#### إنشاء API جديد
```php
<?php
require_once '../config/database_sqlite.php';

// إعداد الاستجابة
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// منطق API الجديد
try {
    $db = Database::getInstance();
    // كود API
    Response::success('تم بنجاح', $data);
} catch (Exception $e) {
    Response::error('حدث خطأ: ' . $e->getMessage());
}
?>
```

#### إضافة صفحة جديدة
```html
<!-- إنشاء ملف HTML جديد -->
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>صفحة جديدة</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <!-- محتوى الصفحة -->
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
```

## النشر في الإنتاج

### متطلبات الخادم الإنتاجي

#### مواصفات الخادم الموصى بها
- **المعالج**: 2 CPU cores أو أكثر
- **الذاكرة**: 4GB RAM أو أكثر
- **التخزين**: 50GB SSD أو أكثر
- **الشبكة**: اتصال إنترنت مستقر

#### إعداد Apache
```apache
<VirtualHost *:80>
    ServerName attendance.example.com
    DocumentRoot /var/www/attendance_system_final
    
    <Directory /var/www/attendance_system_final>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/attendance_error.log
    CustomLog ${APACHE_LOG_DIR}/attendance_access.log combined
</VirtualHost>
```

#### إعداد Nginx
```nginx
server {
    listen 80;
    server_name attendance.example.com;
    root /var/www/attendance_system_final;
    index dashboard.html;
    
    location / {
        try_files $uri $uri/ =404;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### إعداد HTTPS
```bash
# تثبيت Let's Encrypt
sudo apt install certbot python3-certbot-apache

# الحصول على شهادة SSL
sudo certbot --apache -d attendance.example.com
```

### النسخ الاحتياطي
```bash
#!/bin/bash
# سكريبت النسخ الاحتياطي اليومي

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backup/attendance_system"

# نسخ احتياطي لقاعدة البيانات
cp database/attendance_system.db $BACKUP_DIR/db_$DATE.db

# نسخ احتياطي للملفات
tar -czf $BACKUP_DIR/files_$DATE.tar.gz .

# حذف النسخ القديمة (أكثر من 30 يوم)
find $BACKUP_DIR -name "*.db" -mtime +30 -delete
find $BACKUP_DIR -name "*.tar.gz" -mtime +30 -delete
```

## الدعم والصيانة

### التحديثات
- فحص التحديثات شهرياً
- تطبيق تحديثات الأمان فوراً
- اختبار التحديثات في بيئة التطوير أولاً

### المراقبة
- مراقبة استخدام الموارد
- فحص سجلات الأخطاء يومياً
- مراقبة أداء قاعدة البيانات

### الصيانة الدورية
- تنظيف ملفات السجلات القديمة
- تحسين قاعدة البيانات
- فحص سلامة النسخ الاحتياطية

## الترخيص والحقوق

هذا النظام مطور بواسطة Manus AI Assistant ومتاح للاستخدام التعليمي والتجاري.

### شروط الاستخدام
- يُسمح بالاستخدام والتعديل والتوزيع
- يجب الاحتفاظ بحقوق المطور الأصلي
- لا توجد ضمانات صريحة أو ضمنية

### إخلاء المسؤولية
- المطور غير مسؤول عن أي أضرار ناتجة عن استخدام النظام
- يجب على المستخدم اتخاذ الاحتياطات الأمنية المناسبة
- يُنصح بإجراء اختبارات شاملة قبل الاستخدام الإنتاجي

## معلومات الاتصال

- **المطور**: Manus AI Assistant
- **التاريخ**: يوليو 2025
- **الإصدار**: 1.0.0 Final

---

**ملاحظة**: هذا النظام مصمم ليكون حلاً شاملاً لإدارة حضور الطلاب. للحصول على أفضل النتائج، يُرجى قراءة هذا الدليل بعناية واتباع التعليمات خطوة بخطوة.

#   s t u d e n t s  
 