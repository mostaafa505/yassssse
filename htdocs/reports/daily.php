<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('reports');

$day = parse_date($_GET['d'] ?? '') ?: today();
$prev = date('Y-m-d', strtotime($day . ' -1 day')); $next = date('Y-m-d', strtotime($day . ' +1 day'));

// الحسابات: رصيد أول اليوم + دخل + خرج + رصيد آخر اليوم (كاش / بنك / محفظة)
$accounts = db_all('SELECT id, name, acc_type, opening_balance FROM treasury_accounts WHERE is_active=1 ORDER BY acc_type, id');
$byType = [];
foreach ($accounts as $a) {
    $id = (int)$a['id'];
    $before = (float)$a['opening_balance'] + (float)db_val('SELECT COALESCE(SUM(receipt_amount - payment_amount),0) FROM treasury_transactions WHERE account_id=? AND txn_date < ?', [$id, $day]);
    $t = db_one("SELECT COALESCE(SUM(CASE WHEN ref_type='transfer' THEN 0 ELSE receipt_amount END),0) AS rin, COALESCE(SUM(CASE WHEN ref_type='transfer' THEN 0 ELSE payment_amount END),0) AS rout,
                        COALESCE(SUM(CASE WHEN ref_type='transfer' THEN receipt_amount ELSE 0 END),0) AS tin, COALESCE(SUM(CASE WHEN ref_type='transfer' THEN payment_amount ELSE 0 END),0) AS tout
                 FROM treasury_transactions WHERE account_id=? AND txn_date = ?", [$id, $day]);
    $row = ['name' => $a['name'], 'open' => $before, 'in' => (float)$t['rin'], 'out' => (float)$t['rout'], 'tin' => (float)$t['tin'], 'tout' => (float)$t['tout']];
    $row['close'] = $before + $row['in'] - $row['out'] + $row['tin'] - $row['tout'];
    $g = &$byType[$a['acc_type']];
    if (!isset($g)) { $g = ['rows' => [], 'open' => 0.0, 'in' => 0.0, 'out' => 0.0, 'tin' => 0.0, 'tout' => 0.0, 'close' => 0.0]; }
    $g['rows'][] = $row;
    foreach (['open', 'in', 'out', 'tin', 'tout', 'close'] as $k) { $g[$k] += $row[$k]; }
    unset($g);
}
$tot = ['open' => 0.0, 'in' => 0.0, 'out' => 0.0, 'close' => 0.0];
foreach ($byType as $g) { foreach ($tot as $k => $_) { $tot[$k] += $g[$k]; } }

// فواتير اليوم
$sales = db_one("SELECT COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(paid_amount),0) p FROM sales WHERE status='active' AND invoice_date=?", [$day]);
$purch = db_one("SELECT COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(paid_amount),0) p FROM purchases WHERE status='active' AND invoice_date=?", [$day]);
$invExp = invoice_expenses($day, $day);

// الحركات التفصيلية
$txns = db_all("SELECT t.*, a.name AS acc_name, a.acc_type FROM treasury_transactions t JOIN treasury_accounts a ON a.id=t.account_id WHERE t.txn_date=? ORDER BY t.id", [$day]);
$cats = [];
foreach ($txns as $t) {
    if ($t['ref_type'] === 'transfer') { continue; }
    $k = $t['category'];
    $cats[$k] ??= ['in' => 0.0, 'out' => 0.0];
    $cats[$k]['in'] += (float)$t['receipt_amount']; $cats[$k]['out'] += (float)$t['payment_amount'];
}

if (isset($_GET['export'])) {
    $rows = [];
    foreach ($txns as $t) { $rows[] = [$t['txn_date'], $t['acc_name'], $t['doc_no'], $t['description'], $t['category'], (float)$t['receipt_amount'], (float)$t['payment_amount']]; }
    csv_download('يومية ' . $day, ['التاريخ', 'الحساب', 'المستند', 'البيان', 'التصنيف', 'دخل', 'خرج'], $rows);
}
page_start('اليومية', 'reports', ['subtitle' => 'كل اللي دخل وطلع في اليوم']);
print_header('اليومية — ' . fdate($day));
?>
<div class="filters no-print"><form class="row g-2 align-items-end" method="get">
  <div class="col-md-3"><label class="form-label">اليوم</label><input class="form-control" type="date" name="d" value="<?= e($day) ?>"></div>
  <div class="col-auto"><button class="btn btn-brand"><i class="bi bi-funnel"></i> عرض</button></div>
  <div class="col-auto d-flex gap-2"><a class="btn btn-light" href="?d=<?= e($prev) ?>"><i class="bi bi-chevron-right"></i> اليوم اللي قبله</a><a class="btn btn-light" href="?d=<?= e($next) ?>">اليوم اللي بعده <i class="bi bi-chevron-left"></i></a><a class="btn btn-light" href="?d=<?= e(today()) ?>">النهاردة</a></div>
  <div class="col-auto d-flex gap-2 ms-auto"><a class="btn btn-outline-brand" href="?d=<?= e($day) ?>&export=1"><i class="bi bi-file-earmark-excel"></i> Excel</a><button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button></div>
</form></div>

<div class="row g-3 mb-3">
  <div class="col-md-3 col-6"><div class="kpi green"><div class="label">دخل النهاردة</div><div class="value num"><?= egp($tot['in']) ?></div><div class="sub">بدون التحويلات بين الحسابات</div><i class="bi bi-arrow-down-circle bg"></i></div></div>
  <div class="col-md-3 col-6"><div class="kpi red"><div class="label">طلع النهاردة</div><div class="value num"><?= egp($tot['out']) ?></div><i class="bi bi-arrow-up-circle bg"></i></div></div>
  <div class="col-md-3 col-6"><div class="kpi gold"><div class="label">صافي اليوم</div><div class="value num"><?= egp($tot['in'] - $tot['out']) ?></div><i class="bi bi-calculator bg"></i></div></div>
  <div class="col-md-3 col-6"><div class="kpi"><div class="label">إجمالي الرصيد آخر اليوم</div><div class="value num"><?= egp($tot['close']) ?></div><div class="sub">أول اليوم: <?= egp($tot['open']) ?></div><i class="bi bi-safe2 bg"></i></div></div>
</div>

<div class="cardx mb-3"><div class="head"><i class="bi bi-wallet2"></i> الكاش والبنك</div><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>الحساب</th><th class="num">رصيد أول اليوم</th><th class="num">دخل</th><th class="num">طلع</th><th class="num">تحويلات (دخول/خروج)</th><th class="num">رصيد آخر اليوم</th></tr></thead><tbody>
  <?php foreach ($byType as $type => $g): ?>
    <?php foreach ($g['rows'] as $r): ?><tr><td><?= e($r['name']) ?></td><td class="num"><?= egp($r['open']) ?></td><td class="num amount-pos"><?= egp($r['in']) ?></td><td class="num amount-neg"><?= egp($r['out']) ?></td><td class="num text-muted"><?= money($r['tin']) ?> / <?= money($r['tout']) ?></td><td class="num fw-bold"><?= egp($r['close']) ?></td></tr><?php endforeach; ?>
    <tr class="table-light fw-bold"><td>إجمالي <?= e(ACCOUNT_TYPES[$type] ?? $type) ?></td><td class="num"><?= egp($g['open']) ?></td><td class="num"><?= egp($g['in']) ?></td><td class="num"><?= egp($g['out']) ?></td><td class="num"><?= money($g['tin']) ?> / <?= money($g['tout']) ?></td><td class="num"><?= egp($g['close']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>

<div class="row g-3 mb-3">
  <div class="col-12"><div class="cardx h-100"><div class="head"><i class="bi bi-receipt"></i> فواتير النهاردة</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th></th><th class="num">العدد</th><th class="num">الإجمالي</th><th class="num">المدفوع/المحصّل</th></tr></thead><tbody>
    <tr><td>مبيعات</td><td class="num"><?= (int)$sales['c'] ?></td><td class="num"><?= egp($sales['t']) ?></td><td class="num"><?= egp($sales['p']) ?></td></tr>
    <tr><td>مشتريات</td><td class="num"><?= (int)$purch['c'] ?></td><td class="num"><?= egp($purch['t']) ?></td><td class="num"><?= egp($purch['p']) ?></td></tr>
    <tr><td>مصروفات الفواتير (نقل + رسوم)</td><td></td><td class="num"><?= egp($invExp['total']) ?></td><td></td></tr>
  </tbody></table></div></div></div>
  <div class="col-12"><div class="cardx h-100"><div class="head"><i class="bi bi-tags"></i> حسب التصنيف</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>التصنيف</th><th class="num">دخل</th><th class="num">طلع</th></tr></thead><tbody>
    <?php foreach ($cats as $k => $v): ?><tr><td><?= e($k) ?></td><td class="num amount-pos"><?= $v['in'] > 0 ? egp($v['in']) : '' ?></td><td class="num amount-neg"><?= $v['out'] > 0 ? egp($v['out']) : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$cats): ?><tr><td colspan="3" class="text-center text-muted py-3">مفيش حركات في اليوم ده</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
</div>

<div class="cardx"><div class="head"><i class="bi bi-list-ul"></i> تفاصيل الحركات <span class="text-muted small fw-normal">(<?= count($txns) ?>)</span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>الحساب</th><th>المستند</th><th>البيان</th><th>التصنيف</th><th class="num">دخل</th><th class="num">طلع</th></tr></thead><tbody>
  <?php foreach ($txns as $t): ?><tr><td><?= e($t['acc_name']) ?></td><td class="small"><?= e($t['doc_no']) ?></td><td><?= e($t['description']) ?></td><td><?= badge($t['category'], (float)$t['receipt_amount'] > 0 ? 'success' : 'warning') ?></td>
    <td class="num amount-pos"><?= (float)$t['receipt_amount'] > 0 ? egp($t['receipt_amount']) : '' ?></td><td class="num amount-neg"><?= (float)$t['payment_amount'] > 0 ? egp($t['payment_amount']) : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$txns): ?><tr><td colspan="6" class="text-center text-muted py-4">مفيش حركات في اليوم ده</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php page_end();
