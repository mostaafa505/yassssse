<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (is_logged_in()) { redirect(home_path()); }

$error = ''; $newCode = null; $username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $username = post_str('username', 60);
    try {
        $newCode = reset_password_with_code($username, (string)($_POST['code'] ?? ''), (string)($_POST['new_password'] ?? ''), (string)($_POST['new_password2'] ?? ''));
    } catch (Throwable $ex) { $error = user_error_message($ex); }
}
$page_title = 'نسيت كلمة المرور';
require __DIR__ . '/_auth_layout_top.php';
?>
<?php if ($newCode): ?>
  <div class="p-4">
    <div class="alert alert-success"><b>تم تغيير كلمة المرور ✅</b><br>تقدر تدخل بيها دلوقتي.</div>
    <div class="recovery-box">
      <div class="small text-muted mb-1">رمز الاسترداد الجديد — احفظه الآن، مش هيظهر تاني</div>
      <div class="recovery-code" dir="ltr" id="rcode"><?= e($newCode) ?></div>
      <button type="button" class="btn btn-outline-brand btn-sm mt-2" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('rcode').textContent);this.textContent='تم النسخ ✓'">نسخ الرمز</button>
    </div>
    <a class="btn btn-brand w-100 mt-3" href="<?= e(url('auth/login.php')) ?>">الذهاب لتسجيل الدخول</a>
  </div>
<?php else: ?>
  <form method="post" class="p-4" autocomplete="off">
    <?= csrf_field() ?>
    <p class="text-muted small mb-3">اكتب اسم المستخدم و<b>رمز الاسترداد</b> (16 حرف اتسلّمتهولك وقت إنشاء الحساب أو من «حسابي»)، وبعدها كلمة مرور جديدة.</p>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <div class="mb-3"><label class="form-label">اسم المستخدم</label><input class="form-control" name="username" value="<?= e($username) ?>" required dir="ltr" autofocus autocomplete="username"></div>
    <div class="mb-3"><label class="form-label">رمز الاسترداد</label><input class="form-control" name="code" required dir="ltr" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off" style="letter-spacing:.12em;text-transform:uppercase"></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="form-label">كلمة مرور جديدة (8+)</label><input class="form-control" type="password" name="new_password" required minlength="8" dir="ltr" autocomplete="new-password"></div>
      <div class="col-6"><label class="form-label">تأكيدها</label><input class="form-control" type="password" name="new_password2" required minlength="8" dir="ltr" autocomplete="new-password"></div>
    </div>
    <button class="btn btn-brand w-100 py-2">تغيير كلمة المرور</button>
    <div class="alert alert-light border mt-3 mb-0 small"><i class="bi bi-info-circle"></i> ماعندكش رمز الاسترداد؟ اطلب من <b>مدير النظام</b> يعيّن لك كلمة مرور جديدة من قسم «المستخدمين».</div>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/_auth_layout_bottom.php';
