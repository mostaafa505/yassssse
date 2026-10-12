<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('vouchers');

$type = get_str('type'); $from = parse_date($_GET['from'] ?? '') ?: ''; $to = parse_date($_GET['to'] ?? '') ?: '';
$st = get_str('status'); $q = get_str('q');
$where = '1=1'; $params = [];
if (in_array($type, ['receipt', 'payment'], true)) { $where .= ' AND v.voucher_type=?'; $params[] = $type; }
if ($from) { $where .= ' AND v.voucher_date>=?'; $params[] = $from; }
if ($to)   { $where .= ' AND v.voucher_date<=?'; $params[] = $to; }
if (in_array($st, ['active', 'cancelled'], true)) { $where .= ' AND v.status=?'; $params[] = $st; }
if ($q !== '') { $where .= ' AND (v.doc_no LIKE ? OR c.name LIKE ? OR s.name LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$base = "FROM vouchers v LEFT JOIN customers c ON c.id=v.customer_id LEFT JOIN suppliers s ON s.id=v.supplier_id JOIN treasury_accounts a ON a.id=v.account_id WHERE $where";
$perPage = 25; $page = page_num();
$total = (int)db_val("SELECT COUNT(*) $base", $params);
$sum = db_all("SELECT v.voucher_type, SUM(v.amount) AS t $base AND v.status='active' GROUP BY v.voucher_type", $params);
$sums = array_column($sum, 't', 'voucher_type');
$rows = db_all("SELECT v.*, COALESCE(c.name, s.name) AS party_name, a.name AS acc_name $base ORDER BY v.voucher_date DESC, v.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);

if (isset($_GET['export'])) {
    $all = db_all("SELECT v.*, COALESCE(c.name, s.name) AS party_name, a.name AS acc_name $base ORDER BY v.voucher_date, v.id", $params);
    csv_download('السندات', ['التاريخ', 'رقم السند', 'النوع', 'الطرف', 'المبلغ', 'الحساب', 'طريقة الدفع', 'الحالة', 'ملاحظات'],
        array_map(fn($r) => [$r['voucher_date'], $r['doc_no'], $r['voucher_type'] === 'receipt' ? 'قبض' : 'صرف', $r['party_name'], $r['amount'], $r['acc_name'], $r['payment_method'], $r['status'] === 'active' ? 'فعّال' : 'ملغي', $r['notes']], $all));
}
page_start('سندات القبض والصرف', 'vouchers');
?>
<div class="filters no-print"><form class="row g-2 align-items-end" method="get">
  <div class="col-md-2"><label class="form-label">النوع</label><select class="form-select" name="type"><option value="">— الكل —</option><option value="receipt"<?= $type === 'receipt' ? ' selected' : '' ?>>قبض من عميل</option><option value="payment"<?= $type === 'payment' ? ' selected' : '' ?>>صرف لمورد</option></select></div>
  <div class="col-md-2"><label class="form-label">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><label class="form-label">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-2"><label class="form-label">الحالة</label><select class="form-select" name="status"><option value="">— الكل —</option><option value="active"<?= $st === 'active' ? ' selected' : '' ?>>فعّال</option><option value="cancelled"<?= $st === 'cancelled' ? ' selected' : '' ?>>ملغي</option></select></div>
  <div class="col-md-2"><label class="form-label">بحث</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="رقم / اسم"></div>
  <div class="col-md-2 d-flex gap-1"><button class="btn btn-brand flex-fill"><i class="bi bi-search"></i></button><a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i></a></div>
</form></div>
<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="kpi green"><div class="label">إجمالي المقبوض من العملاء (الفعّال)</div><div class="value num"><?= egp($sums['receipt'] ?? 0) ?></div></div></div>
  <div class="col-md-6"><div class="kpi red"><div class="label">إجمالي المصروف للموردين (الفعّال)</div><div class="value num"><?= egp($sums['payment'] ?? 0) ?></div></div></div>
</div>
<div class="cardx"><div class="head"><i class="bi bi-cash-coin"></i> السندات <span class="text-muted small fw-normal">(<?= $total ?>)</span>
  <span class="ms-auto d-flex gap-2 no-print"><a class="btn btn-gold btn-sm" href="form.php?type=receipt"><i class="bi bi-plus-lg"></i> سند قبض</a><a class="btn btn-brand btn-sm" href="form.php?type=payment"><i class="bi bi-plus-lg"></i> سند صرف</a></span></div>
<div class="table-responsive"><table class="table table-hover align-middle">
  <thead><tr><th>التاريخ</th><th>رقم السند</th><th>النوع</th><th>الطرف</th><th class="num">المبلغ</th><th>الحساب / الطريقة</th><th>الحالة</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] === 'cancelled' ? 'text-muted' : '' ?>"><td><?= fdate($r['voucher_date']) ?></td><td class="doc-no"><a class="fw-bold" href="view.php?id=<?= (int)$r['id'] ?>"><?= e($r['doc_no']) ?></a></td>
      <td><?= $r['voucher_type'] === 'receipt' ? badge('قبض', 'success') : badge('صرف', 'warning') ?></td><td><?= e((string)$r['party_name']) ?></td>
      <td class="num fw-bold <?= $r['status'] === 'cancelled' ? 'text-decoration-line-through' : '' ?>"><?= egp($r['amount']) ?></td>
      <td><?= e($r['acc_name']) ?> <span class="text-muted small">· <?= e($r['payment_method']) ?></span></td>
      <td><?= $r['status'] === 'active' ? badge('فعّال', 'success') : badge('ملغي') ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">لا توجد سندات</td></tr><?php endif; ?></tbody></table></div></div>
<?= pager($total, $perPage, $page) ?>
<?php page_end();
