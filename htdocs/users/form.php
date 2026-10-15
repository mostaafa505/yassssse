<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('users');

$id = (int)($_GET['id'] ?? 0);
$u = $id ? db_one('SELECT * FROM users WHERE id=?', [$id]) : null;
if ($id && !$u) { flash('error', 'المستخدم غير موجود.'); redirect('users/'); }
$isAdmin = (current_user()['role'] ?? '') === 'admin';
if ($u && $u['role'] === 'admin' && !$isAdmin) { flash('error', 'حساب المدير مايتعدلش إلا من المدير نفسه.'); redirect('users/'); }
$f = $u ?: ['username' => '', 'full_name' => '', 'role' => 'accountant', 'permissions' => null, 'is_active' => 1];
$curMods = $f['permissions'] ? (json_decode($f['permissions'], true) ?: []) : role_modules($f['role']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        db_tx(function () use ($id, $u, $isAdmin) {
            $username = post_str('username', 60); $name = post_str('full_name', 120);
            $role = $_POST['role'] ?? ''; $pass = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) { throw new AppError('اسم المستخدم: حروف إنجليزية وأرقام فقط (3 أحرف على الأقل).'); }
            if ($name === '') { throw new AppError('الاسم مطلوب.'); }
            if (!isset(ROLES[$role])) { throw new AppError('الدور غير صحيح.'); }
            if ($role === 'admin' && !$isAdmin) { throw new AppError('تعيين دور «مدير النظام» للمدير فقط.'); }
            if (db_val('SELECT id FROM users WHERE username=? AND id<>?', [$username, $id])) { throw new AppError('اسم المستخدم مستخدم.'); }
            if (!$id && mb_strlen($pass) < 8) { throw new AppError('كلمة المرور 8 أحرف على الأقل.'); }
            if ($pass !== '' && mb_strlen($pass) < 8) { throw new AppError('كلمة المرور 8 أحرف على الأقل.'); }
            $active = empty($_POST['is_active']) ? 0 : 1;
            $perms = null;
            if ($role !== 'admin' && !empty($_POST['custom'])) {
                $perms = json_encode(array_values(array_intersect(array_keys(MODULES), (array)($_POST['modules'] ?? []))));
                if (!$isAdmin) { $perms = json_encode(array_values(array_filter(json_decode($perms, true), 'can'))); }   // مايقدرش يدّي صلاحية هو نفسه مالوش
            }
            if ($id) {
                $isLastAdmin = $u['role'] === 'admin' && $u['is_active'] && (int)db_val("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id<>?", [$id]) === 0;
                if ($isLastAdmin && ($role !== 'admin' || !$active)) { throw new AppError('لا يمكن تعطيل أو تغيير آخر مدير نشط في النظام.'); }
                if ($id === (int)current_user()['id'] && !$active) { throw new AppError('لا يمكنك إيقاف حسابك بنفسك.'); }
            }
            $newAv = save_uploaded_image('avatar', 'avatars', 320);
            $data = ['username' => $username, 'full_name' => $name, 'role' => $role, 'permissions' => $perms, 'is_active' => $active];
            if ($pass !== '') { $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT); }
            if ($id && ($newAv || !empty($_POST['remove_avatar']))) { delete_upload($u['avatar'] ?? null); $data['avatar'] = $newAv; }
            elseif (!$id && $newAv) { $data['avatar'] = $newAv; }
            $newCode = null;
            if ($id) {
                db_update('users', $id, $data);
                if (!empty($_POST['new_recovery'])) { $newCode = set_recovery_code($id); }
                audit('update', 'users', $id, "$username ($role)" . ($pass !== '' ? ' + تغيير كلمة المرور' : '') . ($newCode ? ' + رمز استرداد جديد' : ''));
            } else {
                $nid = db_insert('users', $data + ['created_at' => now()]);
                $newCode = set_recovery_code($nid);   // كل مستخدم جديد بياخد رمز استرداد
                audit('create', 'users', $nid, "$username ($role)");
            }
            if ($newCode) { show_recovery_once($newCode, $name); }
        });
        flash('success', 'تم حفظ المستخدم.');
        redirect('users/');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); $f = array_merge($f, $_POST); $curMods = (array)($_POST['modules'] ?? []); }
}
page_start($id ? 'تعديل مستخدم' : 'مستخدم جديد', 'users');
?>
<div class="cardx" style="max-width:860px"><div class="head"><i class="bi bi-person-gear"></i> بيانات المستخدم</div>
<form method="post" class="body" autocomplete="off" enctype="multipart/form-data"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-12 d-flex align-items-center gap-3"><?= avatar_html($f['avatar'] ?? null, (string)$f['full_name'], 'lg') ?>
      <div class="flex-grow-1"><label class="form-label">الصورة (اختياري)</label><input class="form-control" type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif">
        <?php if (!empty($f['avatar']) && $id): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_avatar" value="1" id="ra"><label class="form-check-label small" for="ra">حذف الصورة</label></div><?php endif; ?></div></div>
    <div class="col-md-6"><label class="form-label required">الاسم</label><input class="form-control" name="full_name" value="<?= e((string)$f['full_name']) ?>" required maxlength="120"></div>
    <div class="col-md-6"><label class="form-label required">اسم المستخدم (إنجليزي)</label><input class="form-control" name="username" value="<?= e((string)$f['username']) ?>" required dir="ltr" maxlength="60" autocomplete="off"></div>
    <div class="col-md-6"><label class="form-label <?= $id ? '' : 'required' ?>">كلمة المرور <?= $id ? '(اتركها فارغة بدون تغيير)' : '' ?></label><input class="form-control" type="password" name="password" dir="ltr" minlength="8" autocomplete="new-password" <?= $id ? '' : 'required' ?>></div>
    <div class="col-md-6"><label class="form-label required">الدور</label><select class="form-select" name="role" id="roleSel"><?php foreach (ROLES as $k => $r): if ($k === 'admin' && !$isAdmin) { continue; } ?><option value="<?= $k ?>"<?= $f['role'] === $k ? ' selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12" id="permBox"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="custom" value="1" id="custom"<?= $f['permissions'] ? ' checked' : '' ?>><label class="form-check-label fw-bold" for="custom">تخصيص الصلاحيات يدوياً (بدل صلاحيات الدور)</label></div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="allMods"><label class="form-check-label fw-bold text-navy" for="allMods">تحديد كل الصلاحيات</label></div>
      <div class="row g-2" id="modsList"><?php foreach (MODULES as $k => $l): ?><div class="col-md-4 col-6"><div class="form-check"><input class="form-check-input mod" type="checkbox" name="modules[]" value="<?= $k ?>" id="m_<?= $k ?>"<?= in_array($k, $curMods, true) ? ' checked' : '' ?>><label class="form-check-label" for="m_<?= $k ?>"><?= e($l) ?></label></div></div><?php endforeach; ?></div>
      <div class="form-text">دور «مدير حسابات» بياخد كل الصلاحيات بما فيها إدارة المستخدمين، ومايقدرش يعدّل حساب المدير الرئيسي.</div></div>
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act"<?= !empty($f['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="act">الحساب نشط</label></div></div>
    <?php if ($id): ?><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="new_recovery" value="1" id="nr"><label class="form-check-label" for="nr">إنشاء رمز استرداد جديد لهذا المستخدم (بيظهر مرة واحدة بعد الحفظ)</label></div></div>
    <?php else: ?><div class="col-12"><div class="alert alert-info py-2 mb-0 small">هيتولّد للمستخدم <b>رمز استرداد</b> بعد الحفظ، بيظهر مرة واحدة — سلّمه له علشان يقدر يسترجع كلمة المرور لو نساها.</div></div><?php endif; ?>
  </div>
  <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="./">إلغاء</a></div>
</form></div>
<script>
(function(){var roles=<?= json_encode(array_combine(array_keys(ROLES), array_map('role_modules', array_keys(ROLES))), JSON_UNESCAPED_UNICODE) ?>;
var rs=document.getElementById('roleSel'),cu=document.getElementById('custom'),pb=document.getElementById('permBox');
function ui(){pb.style.display=rs.value==='admin'?'none':'';var dis=!cu.checked;document.querySelectorAll('.mod').forEach(function(c){c.disabled=dis;});}
function preset(){if(cu.checked)return;document.querySelectorAll('.mod').forEach(function(c){c.checked=(roles[rs.value]||[]).indexOf(c.value)>-1;});}
var all=document.getElementById('allMods');
function syncAll(){var m=document.querySelectorAll('.mod'),n=0;m.forEach(function(c){if(c.checked)n++;});all.checked=n===m.length;all.indeterminate=n>0&&n<m.length;}
all.addEventListener('change',function(){if(!cu.checked){cu.checked=true;ui();}document.querySelectorAll('.mod').forEach(function(c){c.checked=all.checked;});syncAll();});
document.querySelectorAll('.mod').forEach(function(c){c.addEventListener('change',syncAll);});
rs.addEventListener('change',function(){preset();ui();syncAll();});cu.addEventListener('change',function(){ui();preset();syncAll();});preset();ui();syncAll();})();
</script>
<?php page_end();
