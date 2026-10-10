<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('stock');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try { stock_operation_delete((int)($_POST['id'] ?? 0)); flash('success', 'تم حذف الحركة.'); }
    catch (Throwable $ex) { flash('error', user_error_message($ex)); }
    redirect('stock/movements.php?' . http_build_query(array_intersect_key($_GET, array_flip(['item', 'wh', 'type', 'from', 'to']))));
}
$item = (int)($_GET['item'] ?? 0); $wh = (int)($_GET['wh'] ?? 0); $type = get_str('type');
$from = parse_date($_GET['from'] ?? '') ?: ''; $to = parse_date($_GET['to'] ?? '') ?: '';
$types = ['opening' => 'رصيد أول المدة', 'purchase' => 'شراء', 'sale' => 'بيع', 'receipt' => 'إذن استلام', 'issue' => 'إذن صرف', 'adjustment' => 'تسوية', 'transfer_in' => 'تحويل وارد', 'transfer_out' => 'تحويل صادر'];
$where = '1=1'; $params = [];
if ($item) { $where .= ' AND m.item_id=?'; $params[] = $item; }
if ($wh)   { $where .= ' AND m.warehouse_id=?'; $params[] = $wh; }
if (isset($types[$type])) { $where .= ' AND m.move_type=?'; $params[] = $type; }
if ($from) { $where .= ' AND m.move_date>=?'; $params[] = $from; }
if ($to)   { $where .= ' AND m.move_date<=?'; $params[] = $to; }
$perPage = 40; $page = page_num();
$total = (int)db_val("SELECT COUNT(*) FROM stock_movements m WHERE $where", $params);
$rows = db_all("SELECT m.*, i.name AS item_name, i.unit, w.name AS wh_name, u.full_name AS user_name
                FROM stock_movements m JOIN items i ON i.id=m.item_id JOIN warehouses w ON w.id=m.warehouse_id LEFT JOIN users u ON u.id=m.created_by
                WHERE $where ORDER BY m.move_date DESC, m.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$items = db_all('SELECT id, name FROM items ORDER BY name'); $whs = db_all('SELECT id, name FROM warehouses ORDER BY name');

if (isset($_GET['export'])) {
    $all = db_all("SELECT m.*, i.name AS item_name, i.unit, w.name AS wh_name FROM stock_movements m JOIN items i ON i.id=m.item_id JOIN warehouses w ON w.id=m.warehouse_id WHERE $where ORDER BY m.move_date, m.id", $params);
    csv_download('حركة-المخزون', ['التاريخ', 'الصنف', 'المخزن', 'النوع', 'وارد', 'منصرف', 'الوحدة', 'رقم المستند', 'السبب', 'ملاحظات'],
        array_map(fn($r) => [$r['move_date'], $r['item_name'], $r['wh_name'], $types[$r['move_type']] ?? '', $r['qty'] > 0 ? $r['qty'] : '', $r['qty'] < 0 ? -$r['qty'] : '', $r['unit'], $r['doc_no'], $r['reason'], $r['notes']], $all));
}
page_start('حركة المخزون', 'stock');
?>
<div class="filters no-print"><form class="row g-2 align-items-end" method="get">
  <div class="col-md-3"><label class="form-label">الصنف</label><select class="form-select" name="item"><?= select_options($items, 'id', 'name', $item, true, '— كل الأصناف —') ?></select></div>
  <div class="col-md-2"><label class="form-label">المخزن</label><select class="form-select" name="wh"><?= select_options($whs, 'id', 'name', $wh, true, '— الكل —') ?></select></div>
  <div class="col-md-2"><label class="form-label">النوع</label><select class="form-select" name="type"><option value="">— الكل —</option><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><label class="form-label">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-1 d-flex gap-1"><button class="btn btn-brand flex-fill"><i class="bi bi-search"></i></button><a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i></a></div>
</form></div>
<div class="cardx"><div class="head"><i class="bi bi-arrow-left-right"></i> سجل حركة المخزون <span class="text-muted small fw-normal">(<?= $total ?>)</span>
  <a class="btn btn-gold btn-sm ms-auto" href="operation.php"><i class="bi bi-plus-lg"></i> عملية مخزنية</a></div>
<div class="table-responsive"><table class="table table-hover align-middle">
  <thead><tr><th>التاريخ</th><th>الصنف</th><th>المخزن</th><th>النوع</th><th class="num">وارد</th><th class="num">منصرف</th><th>رقم المستند</th><th>السبب / ملاحظات</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $deletable = in_array($r['ref_type'], ['manual', 'transfer'], true); ?>
    <tr><td><?= fdate($r['move_date']) ?></td><td class="fw-bold"><?= e($r['item_name']) ?></td><td><?= e($r['wh_name']) ?></td><td><?= badge($types[$r['move_type']] ?? $r['move_type'], $r['qty'] > 0 ? 'success' : 'warning') ?></td>
      <td class="num amount-pos"><?= $r['qty'] > 0 ? qty($r['qty']) . ' ' . e($r['unit']) : '' ?></td><td class="num amount-neg"><?= $r['qty'] < 0 ? qty(-$r['qty']) . ' ' . e($r['unit']) : '' ?></td>
      <td><?php
        if (in_array($r['ref_type'], ['purchase', 'sale'], true)) { echo '<a href="' . e(url($r['ref_type'] === 'purchase' ? 'purchases/view.php?id=' . (int)$r['ref_id'] : 'sales/view.php?id=' . (int)$r['ref_id'])) . '">' . e((string)$r['doc_no']) . '</a>'; }
        else { echo e((string)$r['doc_no']); } ?></td>
      <td class="small text-muted"><?= e(trim(($r['reason'] ? $r['reason'] . ' — ' : '') . ($r['notes'] ?? ''), ' —')) ?></td>
      <td><?php if ($deletable): ?><form method="post" data-confirm="حذف الحركة؟"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">لا توجد حركات</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?= pager($total, $perPage, $page) ?>
<?php page_end();
