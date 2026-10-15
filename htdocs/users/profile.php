<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_login();
$me = db_one('SELECT * FROM users WHERE id=?', [current_user()['id']]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        if (($_POST['action'] ?? '') === 'recovery') {
            if (!password_verify((string)($_POST['rc_password'] ?? ''), $me['password_hash'])) { throw new AppError('كلمة المرور غير صحيحة.'); }
            $code = set_recovery_code((int)$me['id']);
            $_SESSION['user']['has_recovery'] = true;
            show_recovery_once($code, $me['full_name']);
            audit('update', 'users', (int)$me['id'], 'إنشاء رمز استرداد جديد');
            redirect('users/profile.php');
        }
        $name = post_str('full_name', 120); $cur = (string)($_POST['current_password'] ?? ''); $new = (string)($_POST['new_password'] ?? '');
        if ($name === '') { throw new AppError('الاسم مطلوب.'); }
        $data = ['full_name' => $name];
        $newAv = save_uploaded_image('avatar', 'avatars', 320);
        if ($newAv || !empty($_POST['remove_avatar'])) { delete_upload($me['avatar'] ?? null); $data['avatar'] = $newAv; }
        if ($new !== '') {
            if (!password_verify($cur, $me['password_hash'])) { throw new AppError('كلمة المرور الحالية غير صحيحة.'); }
            if (mb_strlen($new) < 8) { throw new AppError('كلمة المرور الجديدة 8 أحرف على الأقل.'); }
            if ($new !== (string)($_POST['new_password2'] ?? '')) { throw new AppError('تأكيد كلمة المرور غير مطابق.'); }
            $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        }
        db_update('users', (int)$me['id'], $data);
        $_SESSION['user']['full_name'] = $name;
        if (array_key_exists('avatar', $data)) { $_SESSION['user']['avatar'] = $data['avatar']; }
        audit('update', 'users', (int)$me['id'], 'تعديل الحساب الشخصي' . ($new !== '' ? ' + كلمة المرور' : ''));
        flash('success', 'تم تحديث بياناتك.');
        redirect('users/profile.php');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
page_start('حسابي', '');
?>
<div class="cardx" style="max-width:560px"><div class="head"><i class="bi bi-person-circle"></i> حسابي</div>
<form method="post" class="body row g-3" autocomplete="off" enctype="multipart/form-data"><?= csrf_field() ?>
  <div class="col-12 d-flex align-items-center gap-3"><?= avatar_html($me['avatar'] ?? null, (string)$me['full_name'], 'lg') ?>
    <div class="flex-grow-1"><label class="form-label">صورتك الشخصية (اختياري)</label><input class="form-control" type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif">
      <?php if (!empty($me['avatar'])): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_avatar" value="1" id="ra"><label class="form-check-label small" for="ra">حذف الصورة</label></div><?php endif; ?></div></div>
  <div class="col-12"><label class="form-label">اسم المستخدم</label><input class="form-control" value="<?= e($me['username']) ?>" disabled dir="ltr"></div>
  <div class="col-12"><label class="form-label required">الاسم</label><input class="form-control" name="full_name" value="<?= e($me['full_name']) ?>" required maxlength="120"></div>
  <div class="col-12"><hr class="my-1"><div class="fw-bold text-navy">تغيير كلمة المرور (اختياري)</div></div>
  <div class="col-12"><label class="form-label">كلمة المرور الحالية</label><input class="form-control" type="password" name="current_password" dir="ltr" autocomplete="current-password"></div>
  <div class="col-md-6"><label class="form-label">الجديدة (8+)</label><input class="form-control" type="password" name="new_password" dir="ltr" minlength="8" autocomplete="new-password"></div>
  <div class="col-md-6"><label class="form-label">تأكيد الجديدة</label><input class="form-control" type="password" name="new_password2" dir="ltr" autocomplete="new-password"></div>
  <div class="col-12"><button class="btn btn-brand px-4">حفظ</button></div>
</form></div>
<div class="cardx mt-3" style="max-width:560px"><div class="head"><i class="bi bi-life-preserver"></i> رمز استرداد كلمة المرور</div>
<form method="post" class="body row g-3" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="recovery">
  <div class="col-12 small text-muted">رمز من 16 حرف تستخدمه من صفحة «نسيت كلمة المرور» لو نسيت الباسوورد. بيظهر مرة واحدة بس — احتفظ به في مكان آمن. إنشاء رمز جديد بيلغي القديم.
    <?= !empty($me['recovery_hash']) ? '<br>آخر إنشاء: ' . e((string)$me['recovery_set_at']) : '<br><b class="text-danger">لسه ماعملتش رمز استرداد.</b>' ?></div>
  <div class="col-md-8"><label class="form-label">كلمة المرور الحالية (للتأكيد)</label><input class="form-control" type="password" name="rc_password" dir="ltr" required autocomplete="current-password"></div>
  <div class="col-md-4 d-flex align-items-end"><button class="btn btn-gold w-100">إنشاء رمز جديد</button></div>
</form></div>
<?php page_end();
