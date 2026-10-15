<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('stock');

$wh = (int)($_GET['wh'] ?? 0); $q = get_str('q'); $st = get_str('status');
$from = parse_date($_GET['from'] ?? '') ?: null; $to = parse_date($_GET['to'] ?? '') ?: today();
$rows = array_values(array_filter(stock_summary($wh ?: null, $from, $to, $q), fn($r) => $r['is_active']));
if ($st === 'reorder') { $rows = array_values(array_filter($rows, fn($r) => $r['stock_status'] === 'reorder')); }
if ($st === 'ok')      { $rows = array_values(array_filter($rows, fn($r) => $r['stock_status'] === 'ok')); }
$whs = db_all('SELECT id, name FROM warehouses ORDER BY is_default DESC, name');

if (isset($_GET['export'])) {
    csv_download('شيت-المخازن', ['كود الصنف', 'اسم الصنف', 'الوحدة', 'رصيد أول المدة', 'الوارد', 'المنصرف', 'الرصيد الحالي', 'حد إعادة الطلب', 'حالة المخزون'],
        array_map(fn($r) => [$r['code'], $r['name'], $r['unit'], $r['opening_qty'], $r['in_qty'], $r['out_qty'], $r['current_qty'], $r['reorder_level'], $r['stock_status'] === 'reorder' ? 'اطلب بضاعة' : 'متوفر'], $rows));
}
page_start('أرصدة المخازن', 'stock');
print_header('أرصدة المخازن' . ($wh ? ' — ' . db_val('SELECT name FROM warehouses WHERE id=?', [$wh]) : ' — كل المخازن'));
$reorderCount = count(array_filter($rows, fn($r) => $r['stock_status'] === 'reorder'));
?>
<div class="filters no-print"><form class="row g-2 align-items-end" method="get">
  <div class="col-md-3"><label class="form-label">المخزن</label><select class="form-select" name="wh"><?= select_options($whs, 'id', 'name', $wh, true, '— كل المخازن —') ?></select></div>
  <div class="col-md-2"><label class="form-label">من تاريخ</label><input class="form-control" type="date" name="from" value="<?= e((string)$from) ?>"></div>
  <div class="col-md-2"><label class="form-label">إلى تاريخ</label><input class="form-control" type="date" name="to" value="<?= e((string)$to) ?>"></div>
  <div class="col-md-2"><label class="form-label">الحالة</label><select class="form-select" name="status"><option value="">— الكل —</option><option value="reorder"<?= $st === 'reorder' ? ' selected' : '' ?>>اطلب بضاعة</option><option value="ok"<?= $st === 'ok' ? ' selected' : '' ?>>متوفر</option></select></div>
  <div class="col-md-3"><label class="form-label">بحث</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="صنف / كود"></div>
  <div class="col-12 d-flex gap-2 flex-wrap">
    <button class="btn btn-brand"><i class="bi bi-funnel"></i> عرض</button>
    <a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
    <a class="btn btn-gold ms-auto" href="operation.php"><i class="bi bi-plus-lg"></i> عملية مخزنية (استلام / صرف / تسوية / تحويل)</a>
  </div>
</form></div>
<?php if ($reorderCount): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> عدد <b><?= $reorderCount ?></b> صنف وصل لحد إعادة الطلب أو أقل.</div><?php endif; ?>
<div class="cardx"><div class="head"><i class="bi bi-boxes"></i> أرصدة الأصناف <span class="text-muted small fw-normal">(<?= count($rows) ?>)</span></div>
<div class="table-responsive"><table class="table table-hover align-middle">
  <thead><tr><th>الكود</th><th>الصنف</th><th>الوحدة</th><th class="num">رصيد أول المدة</th><th class="num">الوارد</th><th class="num">المنصرف</th><th class="num">الرصيد الحالي</th><th class="num">حد إعادة الطلب</th><th>الحالة</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td dir="ltr" class="text-end"><?= e($r['code']) ?></td><td class="fw-bold"><a class="text-decoration-none" href="movements.php?item=<?= (int)$r['id'] ?><?= $wh ? '&wh=' . $wh : '' ?>"><?= e($r['name']) ?></a></td><td><?= e($r['unit']) ?></td>
      <td class="num"><?= qty($r['opening_qty']) ?></td><td class="num amount-pos"><?= qty($r['in_qty']) ?></td><td class="num amount-neg"><?= qty($r['out_qty']) ?></td>
      <td class="num fw-bold"><?= qty($r['current_qty']) ?></td><td class="num"><?= qty($r['reorder_level']) ?></td>
      <td><?= $r['stock_status'] === 'reorder' ? badge('اطلب بضاعة', 'danger') : badge('متوفر', 'success') ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">لا توجد أصناف</td></tr><?php endif; ?>
  </tbody></table></div></div>
<p class="text-muted small mt-2 no-print">الرصيد الحالي = رصيد أول المدة + الوارد − المنصرف. أرقام «أول المدة» تشمل أي حركات قبل تاريخ «من» والأرصدة الافتتاحية. التحويلات بين المخازن تظهر عند اختيار مخزن محدد فقط.</p>
<?php page_end();
