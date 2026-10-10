<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('users');

$id = (int)($_GET['id'] ?? 0);
$u = $id ? db_one('SELECT * FROM users WHERE id=?', [$id]) : null;
if ($id && !$u) { flash('error', 'المستخدم غير موجود.'); redirect('users/'); }
$f = $u ?: ['username' => '', 'full_name' => '', 'role' => 'accountant', 'permissions' => null, 'is_active' => 1];
$curMods = $f['permissions'] ? (json_decode($f['permissions'], true) ?: []) : role_modules($f['role']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        db_tx(function () use ($id, $u) {
            $username = post_str('username', 60); $name = post_str('full_name', 120);
            $role = $_POST['role'] ?? ''; $pass = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) { throw new AppError('اسم المستخدم: حروف إنجليزية وأرقام فقط (3 أحرف على الأقل).'); }
            if ($name === '') { throw new AppError('الاسم مطلوب.'); }
            if (!isset(ROLES[$role])) { throw new AppError('الدور غير صحيح.'); }
            if (db_val('SELECT id FROM users WHERE username=? AND id<>?', [$username, $id])) { throw new AppError('اسم المستخدم مستخدم.'); }
            if (!$id && mb_strlen($pass) < 8) { throw new AppError('كلمة المرور 8 أحرف على الأقل.'); }
            if ($pass !== '' && mb_strlen($pass) < 8) { throw new AppError('كلمة المرور 8 أحرف على الأقل.'); }
            $active = empty($_POST['is_active']) ? 0 : 1;
            $perms = null;
            if ($role !== 'admin' && !empty($_POST['custom'])) {
                $perms = json_encode(array_values(array_intersect(array_keys(MODULES), (array)($_POST['modules'] ?? []))));
            }
            if ($id) {
                $isLastAdmin = $u['role'] === 'admin' && $u['is_active'] && (int)db_val("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id<>?", [$id]) === 0;
                if ($isLastAdmin && ($role !== 'admin' || !$active)) { throw new AppError('لا يمكن تعطيل أو تغيير آخر مدير نشط في النظام.'); }
                if ($id === (int)current_user()['id'] && !$active) { throw new AppError('لا يمكنك إيقاف حسابك بنفسك.'); }
            }
            $data = ['username' => $username, 'full_name' => $name, 'role' => $role, 'permissions' => $perms, 'is_active' => $active];
            if ($pass !== '') { $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT); }
            if ($id) { db_update('users', $id, $data); audit('update', 'users', $id, "$username ($role)" . ($pass !== '' ? ' + تغيير كلمة المرور' : '')); }
            else { $nid = db_insert('users', $data + ['created_at' => now()]); audit('create', 'users', $nid, "$username ($role)"); }
        });
        flash('success', 'تم حفظ المستخدم.');
        redirect('users/');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); $f = array_merge($f, $_POST); $curMods = (array)($_POST['modules'] ?? []); }
}
page_start($id ? 'تعديل مستخدم' : 'مستخدم جديد', 'users');
?>
<div class="cardx" style="max-width:860px"><div class="head"><i class="bi bi-person-gear"></i> بيانات المستخدم</div>
<form method="post" class="body" autocomplete="off"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required">الاسم</label><input class="form-control" name="full_name" value="<?= e((string)$f['full_name']) ?>" required maxlength="120"></div>
    <div class="col-md-6"><label class="form-label required">اسم المستخدم (إنجليزي)</label><input class="form-control" name="username" value="<?= e((string)$f['username']) ?>" required dir="ltr" maxlength="60" autocomplete="off"></div>
    <div class="col-md-6"><label class="form-label <?= $id ? '' : 'required' ?>">كلمة المرور <?= $id ? '(اتركها فارغة بدون تغيير)' : '' ?></label><input class="form-control" type="password" name="password" dir="ltr" minlength="8" autocomplete="new-password" <?= $id ? '' : 'required' ?>></div>
    <div class="col-md-6"><label class="form-label required">الدور</label><select class="form-select" name="role" id="roleSel"><?php foreach (ROLES as $k => $r): ?><option value="<?= $k ?>"<?= $f['role'] === $k ? ' selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?></select></div>
    <div class="col-12" id="permBox"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="custom" value="1" id="custom"<?= $f['permissions'] ? ' checked' : '' ?>><label class="form-check-label fw-bold" for="custom">تخصيص الصلاحيات يدوياً (بدل صلاحيات الدور)</label></div>
      <div class="row g-2" id="modsList"><?php foreach (MODULES as $k => $l): ?><div class="col-md-4 col-6"><div class="form-check"><input class="form-check-input mod" type="checkbox" name="modules[]" value="<?= $k ?>" id="m_<?= $k ?>"<?= in_array($k, $curMods, true) ? ' checked' : '' ?>><label class="form-check-label" for="m_<?= $k ?>"><?= e($l) ?></label></div></div><?php endforeach; ?></div>
      <div class="form-text">إدارة المستخدمين والإعدادات للمدير فقط ولا تُمنح.</div></div>
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act"<?= !empty($f['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="act">الحساب نشط</label></div></div>
  </div>
  <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="./">إلغاء</a></div>
</form></div>
<script>
(function(){var roles=<?= json_encode(array_combine(array_keys(ROLES), array_map('role_modules', array_keys(ROLES))), JSON_UNESCAPED_UNICODE) ?>;
var rs=document.getElementById('roleSel'),cu=document.getElementById('custom'),pb=document.getElementById('permBox');
function ui(){pb.style.display=rs.value==='admin'?'none':'';var dis=!cu.checked;document.querySelectorAll('.mod').forEach(function(c){c.disabled=dis;});}
function preset(){if(cu.checked)return;document.querySelectorAll('.mod').forEach(function(c){c.checked=(roles[rs.value]||[]).indexOf(c.value)>-1;});}
rs.addEventListener('change',function(){preset();ui();});cu.addEventListener('change',function(){ui();preset();});preset();ui();})();
</script>
<?php page_end();
