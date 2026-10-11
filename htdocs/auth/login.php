<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (is_logged_in()) { redirect(home_path()); }

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $username = post_str('username', 60);
    $password = (string)($_POST['password'] ?? '');
    $u = db_one('SELECT * FROM users WHERE username = ?', [$username]);
    $generic = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
    if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
        $error = 'الحساب مقفل مؤقتاً بسبب محاولات خاطئة كثيرة. حاول بعد ' . max(1, (int)ceil((strtotime($u['locked_until']) - time()) / 60)) . ' دقيقة.';
    } elseif (!$u || !password_verify($password, $u['password_hash'])) {
        $error = $generic;
        if ($u) {
            $fails = (int)$u['failed_logins'] + 1;
            db_exec('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?',
                [$fails, $fails >= 5 ? date('Y-m-d H:i:s', time() + 600) : null, $u['id']]);
            if ($fails >= 5) { db_exec('UPDATE users SET failed_logins=0 WHERE id=?', [$u['id']]); }
        } else {
            password_verify($password, '$2y$10$SRXbUvqzagQyHnSyrUda.eY6PqBpTkh8XMKUSCP5KZ66WXk2WAbka'); // توحيد زمن الرد (ما نكشفش وجود الحساب)
        }
        usleep(300000);
    } elseif (!$u['is_active']) {
        $error = 'هذا الحساب موقوف. تواصل مع مدير النظام.';
    } else {
        session_regenerate_id(true);
        load_user_session($u);
        db_exec('UPDATE users SET failed_logins=0, locked_until=NULL, last_login=? WHERE id=?', [now(), $u['id']]);
        audit('login', 'users', (int)$u['id'], 'تسجيل دخول');
        $to = $_SESSION['_redirect'] ?? '';
        unset($_SESSION['_redirect']);
        $base = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
        // نسمح بإعادة التوجيه لمسارات داخل النظام فقط
        if ($to && $to[0] === '/' && !str_starts_with($to, '//') && ($base === '' || str_starts_with($to, $base . '/')) && !str_contains($to, 'auth/')) {
            $origin = (preg_match('#^(https?://[^/]+)#', BASE_URL, $m) ? $m[1] : '');
            redirect($origin . $to);
        }
        redirect(home_path());
    }
}
$page_title = 'تسجيل الدخول';
require __DIR__ . '/_auth_layout_top.php';
?>
    <form method="post" class="p-4 pt-3" autocomplete="on">
        <?= csrf_field() ?>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <div class="mb-3">
            <label class="form-label">اسم المستخدم</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-person"></i></span>
            <input class="form-control" name="username" value="<?= e($username) ?>" required autofocus dir="ltr" autocomplete="username" placeholder="admin"></div>
        </div>
        <div class="mb-4">
            <label class="form-label">كلمة المرور</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input class="form-control" type="password" name="password" required dir="ltr" autocomplete="current-password" placeholder="••••••••"></div>
        </div>
        <button class="btn btn-brand w-100 py-2 fs-6">دخول <i class="bi bi-box-arrow-in-left"></i></button>
    </form>
    <div class="auth-foot"><a href="<?= e(url('auth/forgot.php')) ?>"><i class="bi bi-question-circle"></i> نسيت كلمة المرور؟</a></div>
<?php require __DIR__ . '/_auth_layout_bottom.php';
