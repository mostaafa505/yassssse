<?php
/**
 * مُثبّت النظام — بيعمل جداول قاعدة البيانات + أول مستخدم (مدير) + البيانات الأساسية.
 * بعد التثبيت بيتقفل تلقائياً (installed.lock). امسح فولدر install/ بعد ما تخلص.
 */
declare(strict_types=1);
define('MB_INSTALLER', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$lockFile = __DIR__ . '/installed.lock';
$envPath  = dirname(__DIR__) . '/config/env.php';
$errors = [];
$done = false;

if (is_file($lockFile)) {
    http_response_code(403);
    exit('<div style="font-family:sans-serif;direction:rtl;text-align:center;padding:60px"><h2>النظام مثبّت بالفعل</h2><p>لو محتاج إعادة التثبيت امسح ملف <code>install/installed.lock</code> (وده هيمسح البيانات لو مسحت قاعدة البيانات).</p><a href="' . e(url('')) . '">الذهاب للنظام</a></div>');
}

$v = [
    'db_host' => $ENV['db_host'] ?? 'localhost', 'db_port' => $ENV['db_port'] ?? '3306',
    'db_name' => $ENV['db_name'] ?? 'maher_beheiry_db', 'db_user' => $ENV['db_user'] ?? '',
    'admin_user' => 'admin', 'admin_name' => 'مدير النظام',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    foreach (['db_host', 'db_port', 'db_name', 'db_user', 'admin_user', 'admin_name'] as $k) { $v[$k] = trim((string)($_POST[$k] ?? '')); }
    $dbPass = (string)($_POST['db_pass'] ?? '');
    if ($dbPass === '' && $ENV) { $dbPass = (string)($ENV['db_pass'] ?? ''); }
    $adminPass = (string)($_POST['admin_pass'] ?? '');

    if ($v['db_host'] === '' || $v['db_name'] === '' || $v['db_user'] === '') { $errors[] = 'بيانات الاتصال بقاعدة البيانات ناقصة.'; }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $v['admin_user'])) { $errors[] = 'اسم المستخدم: حروف إنجليزية وأرقام فقط (3 أحرف على الأقل).'; }
    if ($v['admin_name'] === '') { $errors[] = 'اسم المدير مطلوب.'; }
    if (mb_strlen($adminPass) < 8) { $errors[] = 'كلمة مرور المدير لازم تكون 8 أحرف على الأقل.'; }
    if ($adminPass !== (string)($_POST['admin_pass2'] ?? '')) { $errors[] = 'تأكيد كلمة المرور غير مطابق.'; }

    if (!$errors) {
        try {
            $env = ['db_host' => $v['db_host'], 'db_port' => $v['db_port'] ?: '3306', 'db_name' => $v['db_name'],
                    'db_user' => $v['db_user'], 'db_pass' => $dbPass, 'debug' => false];
            $pdo = new PDO(
                'mysql:host=' . $env['db_host'] . ';port=' . $env['db_port'] . ';dbname=' . $env['db_name'] . ';charset=utf8mb4',
                $env['db_user'], $env['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $pdo->exec("SET time_zone = '" . date('P') . "'");

            if ($pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
                $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if ($hasUsers > 0) { throw new RuntimeException('قاعدة البيانات دي فيها بيانات بالفعل. استخدم قاعدة بيانات فاضية جديدة.'); }
            }

            // 1) الجداول
            $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) { $pdo->exec($stmt); }

            // 2) البيانات الأساسية
            $now = date('Y-m-d H:i:s');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users (username, full_name, password_hash, role, permissions, is_active, created_at) VALUES (?,?,?,?,NULL,1,?)')
                ->execute([$v['admin_user'], $v['admin_name'], password_hash($adminPass, PASSWORD_DEFAULT), 'admin', $now]);
            $pdo->exec("INSERT INTO warehouses (name, location, is_default, is_active) VALUES ('المخزن الرئيسي', NULL, 1, 1)");
            $pdo->exec("INSERT INTO treasury_accounts (name, acc_type, opening_balance, is_active) VALUES ('الخزينة النقدية', 'cash', 0, 1), ('الحساب البنكي', 'bank', 0, 1)");
            $st = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
            foreach ([
                'company_name' => 'ماهر البحيري للاستيراد والتصدير', 'company_name_en' => 'Maher El-Beheiry Import & Export',
                'company_phone' => '', 'company_address' => '', 'company_tax_no' => '', 'company_commercial_no' => '',
                'invoice_footer' => 'شكراً لتعاملكم معنا', 'vat_rate' => '14', 'allow_negative_treasury' => '0',
            ] as $k => $val) { $st->execute([$k, $val]); }
            $pdo->commit();

            // 3) ملف الإعدادات + قفل التثبيت
            $export = "<?php\n// أُنشئ تلقائياً بواسطة المُثبّت — ممنوع رفعه على GitHub\nreturn " . var_export($env, true) . ";\n";
            if (!is_file($envPath) || $ENV !== $env) {
                if (@file_put_contents($envPath, $export, LOCK_EX) === false) {
                    throw new RuntimeException('تم إنشاء الجداول لكن تعذّر كتابة config/env.php — اكتبه يدوياً (انسخ env.sample.php).');
                }
            }
            @file_put_contents($lockFile, date('c'));
            $done = true;
        } catch (Throwable $ex) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = ($ex instanceof PDOException ? 'خطأ في قاعدة البيانات: ' : '') . $ex->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تثبيت النظام | <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<style>
body{font-family:'Cairo',sans-serif;background:linear-gradient(135deg,#0c2540,#17436b);min-height:100vh;display:flex;align-items:center;padding:24px 0}
.card{border:0;border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,.35)}
.brand{background:#0f2d4a;color:#fff;border-radius:16px 16px 0 0;padding:22px;text-align:center;border-bottom:4px solid #c9972b}
.brand img{height:64px}.btn-brand{background:#c9972b;border:0;color:#fff;font-weight:700}.btn-brand:hover{background:#b3841f;color:#fff}
</style>
</head>
<body>
<div class="container" style="max-width:640px">
  <div class="card">
    <div class="brand"><img src="<?= e(url('assets/img/logo.svg')) ?>" alt=""><h4 class="mt-2 mb-0 fw-bold"><?= e(APP_NAME) ?></h4><small>تثبيت النظام</small></div>
    <div class="card-body p-4">
    <?php if ($done): ?>
      <div class="alert alert-success"><b>تم التثبيت بنجاح ✅</b><br>اتعملت الجداول وحساب المدير، والمخزن الرئيسي، وحساب الخزينة والبنك.</div>
      <div class="alert alert-warning small">لأمان النظام: <b>امسح فولدر <code>install/</code></b> من السيرفر دلوقتي.</div>
      <a class="btn btn-brand w-100" href="<?= e(url('auth/login.php')) ?>">تسجيل الدخول</a>
    <?php else: ?>
      <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <h6 class="fw-bold text-secondary">1) قاعدة البيانات (فاضية وجديدة)</h6>
        <div class="row g-2 mb-3">
          <div class="col-8"><label class="form-label small">السيرفر (Host)</label><input class="form-control" name="db_host" value="<?= e($v['db_host']) ?>" required></div>
          <div class="col-4"><label class="form-label small">المنفذ</label><input class="form-control" name="db_port" value="<?= e($v['db_port']) ?>"></div>
          <div class="col-12"><label class="form-label small">اسم قاعدة البيانات</label><input class="form-control" name="db_name" value="<?= e($v['db_name']) ?>" required></div>
          <div class="col-6"><label class="form-label small">المستخدم</label><input class="form-control" name="db_user" value="<?= e($v['db_user']) ?>" required></div>
          <div class="col-6"><label class="form-label small">كلمة المرور</label><input class="form-control" type="password" name="db_pass"></div>
        </div>
        <h6 class="fw-bold text-secondary">2) حساب مدير النظام</h6>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small">الاسم</label><input class="form-control" name="admin_name" value="<?= e($v['admin_name']) ?>" required></div>
          <div class="col-6"><label class="form-label small">اسم المستخدم (إنجليزي)</label><input class="form-control" name="admin_user" value="<?= e($v['admin_user']) ?>" required dir="ltr"></div>
          <div class="col-6"><label class="form-label small">كلمة المرور (8 أحرف+)</label><input class="form-control" type="password" name="admin_pass" required minlength="8" dir="ltr"></div>
          <div class="col-6"><label class="form-label small">تأكيد كلمة المرور</label><input class="form-control" type="password" name="admin_pass2" required minlength="8" dir="ltr"></div>
        </div>
        <button class="btn btn-brand w-100 py-2">تثبيت النظام</button>
      </form>
    <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
