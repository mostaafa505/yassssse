<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('treasury');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        if (($_POST['action'] ?? '') === 'delete') { treasury_manual_delete((int)($_POST['id'] ?? 0)); flash('success', 'تم حذف الحركة.'); }
        elseif (($_POST['action'] ?? '') === 'delete_transfer') { treasury_transfer_delete((int)($_POST['ref_id'] ?? 0)); flash('success', 'تم حذف التحويل.'); }
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
    redirect('treasury/?' . http_build_query(array_intersect_key($_GET, array_flip(['acc', 'from', 'to', 'cat', 'q']))));
}

$accounts = db_all('SELECT * FROM treasury_accounts ORDER BY is_active DESC, id');
$accId = (int)($_GET['acc'] ?? 0);   // 0 = كل الحسابات
$from = parse_date($_GET['from'] ?? '') ?: date('Y-m-01');
$to = parse_date($_GET['to'] ?? '') ?: today();
$cat = get_str('cat'); $q = get_str('q');

$accWhere = $accId ? ' AND t.account_id = ' . $accId : '';
$accOpen = $accId ? (float)db_val('SELECT opening_balance FROM treasury_accounts WHERE id=?', [$accId]) : (float)db_val('SELECT COALESCE(SUM(opening_balance),0) FROM treasury_accounts');
$beforeNet = (float)db_val("SELECT COALESCE(SUM(t.receipt_amount - t.payment_amount),0) FROM treasury_transactions t WHERE t.txn_date < ? $accWhere", [$from]);
$opening = $accOpen + $beforeNet;
$all = db_all("SELECT t.*, a.name AS acc_name FROM treasury_transactions t JOIN treasury_accounts a ON a.id=t.account_id
               WHERE t.txn_date BETWEEN ? AND ? $accWhere ORDER BY t.txn_date, t.id", [$from, $to]);
$run = $opening; $sumRec = 0.0; $sumPay = 0.0;
foreach ($all as &$r) {
    $r['start_bal'] = $run;
    $run = round($run + (float)$r['receipt_amount'] - (float)$r['payment_amount'], 2);
    $r['end_bal'] = $run;
    $sumRec += (float)$r['receipt_amount']; $sumPay += (float)$r['payment_amount'];
}
unset($r);
$rows = array_values(array_filter($all, function ($r) use ($cat, $q) {
    if ($cat !== '' && $r['category'] !== $cat) { return false; }
    if ($q !== '' && mb_stripos($r['description'] . ' ' . $r['doc_no'] . ' ' . $r['responsible'], $q) === false) { return false; }
    return true;
}));
$closing = $run;

if (isset($_GET['export'])) {
    csv_download('الخزينة-والمصروفات', ['التاريخ', 'رقم الإيصال / المستند', 'البيان', 'التصنيف', 'مقبوضات', 'مدفوعات', 'رصيد أول الحركة', 'رصيد آخر الحركة', 'الحساب', 'طريقة الدفع', 'المسؤول', 'ملاحظات'],
        array_map(fn($r) => [$r['txn_date'], $r['doc_no'], $r['description'], $r['category'], $r['receipt_amount'] ?: '', $r['payment_amount'] ?: '', $r['start_bal'], $r['end_bal'], $r['acc_name'], $r['payment_method'], $r['responsible'], $r['notes']], $rows));
}

$perPage = 50; $page = page_num(); $total = count($rows);
$pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);
$linkFor = function (array $r): string {
    $d = e($r['doc_no']);
    return match ($r['ref_type']) {
        'purchase' => '<a href="' . e(url('purchases/view.php?id=' . (int)$r['ref_id'])) . '">' . $d . '</a>',
        'sale' => '<a href="' . e(url('sales/view.php?id=' . (int)$r['ref_id'])) . '">' . $d . '</a>',
        'voucher' => '<a href="' . e(url('vouchers/view.php?id=' . (int)$r['ref_id'])) . '">' . $d . '</a>',
        default => $d,
    };
};
page_start('الخزينة والمصروفات', 'treasury');
print_header('حركة الخزينة من ' . fdate($from) . ' إلى ' . fdate($to) . ($accId ? ' — ' . db_val('SELECT name FROM treasury_accounts WHERE id=?', [$accId]) : ' — كل الحسابات'));
?>
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6"><div class="kpi"><div class="label">رصيد أول الفترة</div><div class="value num"><?= egp($opening) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="kpi green"><div class="label">المقبوضات</div><div class="value num"><?= egp($sumRec) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="kpi red"><div class="label">المدفوعات</div><div class="value num"><?= egp($sumPay) ?></div></div></div>
  <div class="col-md-3 col-6"><div class="kpi gold"><div class="label">رصيد آخر الفترة</div><div class="value num"><?= egp($closing) ?></div></div></div>
</div>
<div class="filters no-print"><form class="row g-2 align-items-end" method="get">
  <div class="col-md-3"><label class="form-label">الحساب</label><select class="form-select" name="acc"><option value="0">— كل الحسابات —</option><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $accId === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?> (<?= money(treasury_balance((int)$a['id'])) . ' ' . CURRENCY ?>)</option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><label class="form-label">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-2"><label class="form-label">التصنيف</label><select class="form-select" name="cat"><option value="">— الكل —</option><?php foreach (TREASURY_CATEGORIES as $c): ?><option<?= $cat === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">بحث</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="بيان / رقم / مسؤول"></div>
  <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-brand"><i class="bi bi-funnel"></i> عرض</button>
    <a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
    <span class="ms-auto d-flex gap-2 flex-wrap">
      <a class="btn btn-gold" href="form.php?dir=receipt"><i class="bi bi-plus-lg"></i> مقبوضات</a>
      <a class="btn btn-brand" href="form.php?dir=payment"><i class="bi bi-dash-lg"></i> مدفوعات / مصروف</a>
      <a class="btn btn-outline-brand" href="transfer.php"><i class="bi bi-bank"></i> إيداع / سحب بنكي</a>
      <a class="btn btn-outline-secondary" href="accounts.php"><i class="bi bi-gear"></i> الحسابات</a></span></div>
</form></div>
<div class="cardx"><div class="head"><i class="bi bi-safe2"></i> حركة الخزينة <span class="text-muted small fw-normal">(<?= $total ?>)</span></div>
<div class="table-responsive"><table class="table table-hover align-middle wide">
  <thead><tr><th>التاريخ</th><th>رقم المستند</th><th>البيان</th><th>التصنيف</th><th class="num">مقبوضات</th><th class="num">مدفوعات</th><th class="num">رصيد أول الحركة</th><th class="num">رصيد آخر الحركة</th><th>الحساب / الطريقة</th><th>المسؤول</th><th class="no-print"></th></tr></thead>
  <tbody>
  <?php foreach ($pageRows as $r): ?>
    <tr><td><?= fdate($r['txn_date']) ?></td><td class="doc-no"><?= $linkFor($r) ?></td><td class="desc"><?= e($r['description']) ?><?= $r['notes'] ? '<div class="small text-muted">' . e($r['notes']) . '</div>' : '' ?></td>
      <td><?= badge($r['category'], (float)$r['receipt_amount'] > 0 ? 'success' : 'warning') ?></td>
      <td class="num amount-pos"><?= (float)$r['receipt_amount'] ? egp($r['receipt_amount']) : '' ?></td><td class="num amount-neg"><?= (float)$r['payment_amount'] ? egp($r['payment_amount']) : '' ?></td>
      <td class="num text-muted"><?= egp($r['start_bal']) ?></td><td class="num fw-bold <?= $r['end_bal'] < 0 ? 'amount-neg' : '' ?>"><?= egp($r['end_bal']) ?></td>
      <td class="small"><?= e($r['acc_name']) ?><div class="text-muted"><?= e($r['payment_method']) ?></div></td><td class="small"><?= e((string)$r['responsible']) ?></td>
      <td class="no-print text-nowrap">
        <?php if (!$r['ref_type']): ?>
          <a class="btn btn-sm btn-link p-0" href="form.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" data-confirm="حذف الحركة؟"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form>
        <?php elseif ($r['ref_type'] === 'transfer' && (int)$r['ref_id'] === (int)$r['id']): ?>
          <form method="post" class="d-inline" data-confirm="حذف التحويل (الحركتين)؟"><?= csrf_field() ?><input type="hidden" name="action" value="delete_transfer"><input type="hidden" name="ref_id" value="<?= (int)$r['ref_id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; if (!$pageRows): ?><tr><td colspan="11" class="text-center text-muted py-4">لا توجد حركات في الفترة</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr><td colspan="4">إجمالي الفترة</td><td class="num"><?= egp($sumRec) ?></td><td class="num"><?= egp($sumPay) ?></td><td colspan="5"></td></tr></tfoot>
</table></div></div>
<?= pager($total, $perPage, $page) ?>
<p class="small text-muted mt-2 no-print">الأرصدة بتتحسب تلقائياً من حركات الحساب بالترتيب الزمني — مفيش رصيد بيتكتب يدوياً. مقبوضات المبيعات والتحصيل ومدفوعات الموردين بتتسجل تلقائياً من الفواتير والسندات.</p>
<?php page_end();
