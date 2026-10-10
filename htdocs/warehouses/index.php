<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('stock');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        db_tx(function () {
            $id = (int)($_POST['id'] ?? 0);
            $name = post_str('name', 120);
            if ($name === '') { throw new AppError('اسم المخزن مطلوب.'); }
            if (db_val('SELECT id FROM warehouses WHERE name=? AND id<>?', [$name, $id])) { throw new AppError('اسم المخزن مستخدم.'); }
            $data = ['name' => $name, 'location' => post_str('location', 200) ?: null, 'is_active' => empty($_POST['is_active']) ? 0 : 1];
            if ($id) {
                if (!$data['is_active']) {
                    if (db_val('SELECT COUNT(*) FROM warehouses WHERE is_active=1 AND id<>?', [$id]) < 1) { throw new AppError('لازم يفضل مخزن نشط واحد على الأقل.'); }
                    $bal = db_val('SELECT COALESCE(SUM(ABS(qty)),0) FROM (SELECT item_id, SUM(qty) AS qty FROM stock_movements WHERE warehouse_id=? GROUP BY item_id HAVING ABS(SUM(qty))>0.0005) t', [$id]);
                    if ((float)$bal > 0) { throw new AppError('لا يمكن إيقاف مخزن فيه رصيد. حوّل البضاعة منه أولاً.'); }
                }
                db_update('warehouses', $id, $data);
            } else { $id = db_insert('warehouses', $data + ['is_default' => 0]); }
            if (!empty($_POST['is_default']) && $data['is_active']) { db_exec('UPDATE warehouses SET is_default = (id = ?)', [$id]); }
            audit('save', 'warehouses', $id, $name);
        });
        flash('success', 'تم حفظ المخزن.');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
    redirect('warehouses/');
}
$rows = db_all('SELECT w.*, (SELECT COUNT(DISTINCT item_id) FROM stock_movements WHERE warehouse_id=w.id) AS items_cnt FROM warehouses w ORDER BY w.is_default DESC, w.name');
$edit = isset($_GET['id']) ? db_one('SELECT * FROM warehouses WHERE id=?', [(int)$_GET['id']]) : null;
page_start('إدارة المخازن', 'stock');
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="cardx"><div class="head"><i class="bi bi-building"></i> المخازن</div>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>الاسم</th><th>الموقع</th><th class="num">أصناف بها حركة</th><th>الحالة</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td class="fw-bold"><?= e($r['name']) ?> <?= $r['is_default'] ? badge('الافتراضي', 'info') : '' ?></td><td><?= e((string)$r['location']) ?></td><td class="num"><?= (int)$r['items_cnt'] ?></td>
      <td><?= $r['is_active'] ? badge('نشط', 'success') : badge('موقوف') ?></td><td><a class="btn btn-sm btn-outline-secondary" href="?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
      <a class="btn btn-sm btn-outline-brand" href="<?= e(url('stock/?wh=' . (int)$r['id'])) ?>">الأرصدة</a></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <div class="col-lg-5"><div class="cardx"><div class="head"><?= $edit ? 'تعديل مخزن' : 'مخزن جديد' ?></div>
    <form method="post" class="body row g-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="col-12"><label class="form-label required">اسم المخزن</label><input class="form-control" name="name" required maxlength="120" value="<?= e((string)($edit['name'] ?? '')) ?>"></div>
      <div class="col-12"><label class="form-label">الموقع / العنوان</label><input class="form-control" name="location" maxlength="200" value="<?= e((string)($edit['location'] ?? '')) ?>"></div>
      <div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="wa"<?= !$edit || $edit['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="wa">نشط</label></div></div>
      <div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="wd"<?= !empty($edit['is_default']) ? ' checked' : '' ?>><label class="form-check-label" for="wd">المخزن الافتراضي</label></div></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-brand">حفظ</button><?php if ($edit): ?><a class="btn btn-light" href="./">جديد</a><?php endif; ?></div>
    </form></div></div>
</div>
<?php page_end();
