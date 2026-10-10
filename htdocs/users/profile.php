<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_login();
$me = db_one('SELECT * FROM users WHERE id=?', [current_user()['id']]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        $name = post_str('full_name', 120); $cur = (string)($_POST['current_password'] ?? ''); $new = (string)($_POST['new_password'] ?? '');
        if ($name === '') { throw new AppError('الاسم مطلوب.'); }
        $data = ['full_name' => $name];
        if ($new !== '') {
            if (!password_verify($cur, $me['password_hash'])) { throw new AppError('كلمة المرور الحالية غير صحيحة.'); }
            if (mb_strlen($new) < 8) { throw new AppError('كلمة المرور الجديدة 8 أحرف على الأقل.'); }
            if ($new !== (string)($_POST['new_password2'] ?? '')) { throw new AppError('تأكيد كلمة المرور غير مطابق.'); }
            $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        }
        db_update('users', (int)$me['id'], $data);
        $_SESSION['user']['full_name'] = $name;
        audit('update', 'users', (int)$me['id'], 'تعديل الحساب الشخصي' . ($new !== '' ? ' + كلمة المرور' : ''));
        flash('success', 'تم تحديث بياناتك.');
        redirect('users/profile.php');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
page_start('حسابي', '');
?>
<div class="cardx" style="max-width:560px"><div class="head"><i class="bi bi-person-circle"></i> حسابي</div>
<form method="post" class="body row g-3" autocomplete="off"><?= csrf_field() ?>
  <div class="col-12"><label class="form-label">اسم المستخدم</label><input class="form-control" value="<?= e($me['username']) ?>" disabled dir="ltr"></div>
  <div class="col-12"><label class="form-label required">الاسم</label><input class="form-control" name="full_name" value="<?= e($me['full_name']) ?>" required maxlength="120"></div>
  <div class="col-12"><hr class="my-1"><div class="fw-bold text-navy">تغيير كلمة المرور (اختياري)</div></div>
  <div class="col-12"><label class="form-label">كلمة المرور الحالية</label><input class="form-control" type="password" name="current_password" dir="ltr" autocomplete="current-password"></div>
  <div class="col-md-6"><label class="form-label">الجديدة (8+)</label><input class="form-control" type="password" name="new_password" dir="ltr" minlength="8" autocomplete="new-password"></div>
  <div class="col-md-6"><label class="form-label">تأكيد الجديدة</label><input class="form-control" type="password" name="new_password2" dir="ltr" autocomplete="new-password"></div>
  <div class="col-12"><button class="btn btn-brand px-4">حفظ</button></div>
</form></div>
<?php page_end();
