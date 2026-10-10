<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('items');

$q = get_str('q'); $st = get_str('status'); $inactive = !empty($_GET['inactive']);
$rows = stock_summary(null, null, today(), $q);
if (!$inactive) { $rows = array_values(array_filter($rows, fn($r) => $r['is_active'])); }
if ($st === 'reorder') { $rows = array_values(array_filter($rows, fn($r) => $r['stock_status'] === 'reorder')); }
if ($st === 'ok')      { $rows = array_values(array_filter($rows, fn($r) => $r['stock_status'] === 'ok')); }

if (isset($_GET['export'])) {
    csv_download('الأصناف', ['كود الصنف', 'اسم الصنف', 'الوحدة', 'الرصيد الحالي', 'حد إعادة الطلب', 'حالة المخزون'],
        array_map(fn($r) => [$r['code'], $r['name'], $r['unit'], $r['current_qty'], $r['reorder_level'], $r['stock_status'] === 'reorder' ? 'اطلب بضاعة' : 'متوفر'], $rows));
}
page_start('الأصناف', 'items');
?>
<div class="filters no-print"><form class="row g-2 align-items-end" method="get">
  <div class="col-md-4"><label class="form-label">بحث</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="اسم الصنف أو الكود"></div>
  <div class="col-md-3"><label class="form-label">حالة المخزون</label><select class="form-select" name="status"><option value="">— الكل —</option><option value="reorder"<?= $st === 'reorder' ? ' selected' : '' ?>>اطلب بضاعة</option><option value="ok"<?= $st === 'ok' ? ' selected' : '' ?>>متوفر</option></select></div>
  <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="inactive" value="1" id="ia"<?= $inactive ? ' checked' : '' ?>><label class="form-check-label" for="ia">إظهار الموقوفة</label></div></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-brand"><i class="bi bi-search"></i> بحث</button>
    <a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i></a>
    <a class="btn btn-gold ms-auto" href="form.php"><i class="bi bi-plus-lg"></i> صنف جديد</a></div>
</form></div>
<div class="cardx"><div class="head"><i class="bi bi-box-seam"></i> الأصناف <span class="text-muted small fw-normal">(<?= count($rows) ?>)</span></div>
<div class="table-responsive"><table class="table table-hover align-middle">
  <thead><tr><th>الكود</th><th>الصنف</th><th>الوحدة</th><th class="num">الرصيد الحالي (كل المخازن)</th><th class="num">حد إعادة الطلب</th><th>حالة المخزون</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
      <td dir="ltr" class="text-end"><?= e($r['code']) ?></td><td class="fw-bold"><?= e($r['name']) ?><?= $r['is_active'] ? '' : ' ' . badge('موقوف') ?></td><td><?= e($r['unit']) ?></td>
      <td class="num fw-bold"><?= qty($r['current_qty']) ?></td><td class="num"><?= qty($r['reorder_level']) ?></td>
      <td><?= $r['stock_status'] === 'reorder' ? badge('اطلب بضاعة', 'danger') : badge('متوفر', 'success') ?></td>
      <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
        <a class="btn btn-sm btn-outline-brand" href="<?= e(url('stock/movements.php?item=' . (int)$r['id'])) ?>">الحركة</a></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد أصناف</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php page_end();
