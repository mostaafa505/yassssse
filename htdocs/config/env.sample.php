<?php
/**
 * إعدادات السيرفر — انسخ الملف ده باسم env.php واكتب بيانات قاعدة البيانات.
 * (صفحة التثبيت install/ بتعمل الملف ده تلقائياً لو ما لقتوش)
 * ملف env.php ممنوع يترفع على GitHub (موجود في .gitignore).
 */
return [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'maher_beheiry_db',
    'db_user' => 'root',
    'db_pass' => '',
    'debug'   => false,   // true = إظهار الأخطاء (للتطوير فقط)
];
