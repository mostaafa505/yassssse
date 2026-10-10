<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('users');
$rows = db_all('SELECT * FROM users ORDER BY is_active DESC, id');
page_start('المستخدمين والصلاحيات', 'users');
?>
<div class="cardx"><div class="head"><i class="bi bi-person-gear"></i> المستخدمين <a class="btn btn-gold btn-sm ms-auto" href="form.php"><i class="bi bi-plus-lg"></i> مستخدم جديد</a></div>
<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>الاسم</th><th>اسم المستخدم</th><th>الدور</th><th>الصلاحيات</th><th>آخر دخول</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $u): $mods = $u['permissions'] ? (json_decode($u['permissions'], true) ?: []) : role_modules($u['role']); ?>
  <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>"><td class="fw-bold"><?= e($u['full_name']) ?></td><td dir="ltr" class="text-end"><?= e($u['username']) ?></td><td><?= e(ROLES[$u['role']]['label'] ?? $u['role']) ?></td>
    <td class="small text-muted" style="max-width:320px"><?= $u['role'] === 'admin' ? 'كل الصلاحيات' : e(implode('، ', array_map(fn($m) => MODULES[$m] ?? $m, $mods))) ?><?= $u['permissions'] && $u['role'] !== 'admin' ? ' ' . badge('مخصصة', 'info') : '' ?></td>
    <td class="small"><?= $u['last_login'] ? e($u['last_login']) : '—' ?></td><td><?= $u['is_active'] ? badge('نشط', 'success') : badge('موقوف') ?></td>
    <td><a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int)$u['id'] ?>"><i class="bi bi-pencil"></i></a></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php page_end();
