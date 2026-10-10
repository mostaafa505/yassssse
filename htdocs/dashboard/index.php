<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('dashboard');

$today = today(); $monthStart = date('Y-m-01');
$fin = can('reports') || can('treasury');   // الأرقام المالية لمن له صلاحية فقط
$accounts = db_all('SELECT id, name, acc_type FROM treasury_accounts WHERE is_active=1 ORDER BY id');
$cashTotal = 0.0; foreach ($accounts as &$a) { $a['bal'] = treasury_balance((int)$a['id']); $cashTotal += $a['bal']; } unset($a);

$recv = (float)db_val('SELECT COALESCE(SUM(GREATEST(b,0)),0) FROM (SELECT SUM(invoice_amount - payment_amount) AS b FROM customer_ledger GROUP BY customer_id) t');
$pay  = (float)db_val('SELECT COALESCE(SUM(GREATEST(b,0)),0) FROM (SELECT SUM(invoice_amount - payment_amount) AS b FROM supplier_ledger GROUP BY supplier_id) t');
$overRecv = (float)db_val("SELECT COALESCE(SUM(total - paid_amount),0) FROM sales WHERE status='active' AND total - paid_amount > 0.005 AND due_date < ?", [$today]);
$overPay  = (float)db_val("SELECT COALESCE(SUM(total - paid_amount),0) FROM purchases WHERE status='active' AND total - paid_amount > 0.005 AND due_date < ?", [$today]);
$salesM = (float)db_val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status='active' AND invoice_date >= ?", [$monthStart]);
$purM   = (float)db_val("SELECT COALESCE(SUM(total),0) FROM purchases WHERE status='active' AND invoice_date >= ?", [$monthStart]);
$expIn  = "'" . implode("','", EXPENSE_CATEGORIES) . "'";
$expM   = (float)db_val("SELECT COALESCE(SUM(payment_amount),0) FROM treasury_transactions WHERE category IN ($expIn) AND txn_date >= ?", [$monthStart]);
$reorder = array_values(array_filter(stock_summary(null, null, $today), fn($r) => $r['is_active'] && $r['stock_status'] === 'reorder'));

// آخر 6 شهور
$months = []; for ($i = 5; $i >= 0; $i--) { $months[date('Y-m', strtotime("first day of -$i month"))] = ['s' => 0.0, 'p' => 0.0]; }
$first = array_key_first($months) . '-01';
foreach (db_all("SELECT DATE_FORMAT(invoice_date,'%Y-%m') m, SUM(total) t FROM sales WHERE status='active' AND invoice_date >= ? GROUP BY m", [$first]) as $r) { if (isset($months[$r['m']])) { $months[$r['m']]['s'] = (float)$r['t']; } }
foreach (db_all("SELECT DATE_FORMAT(invoice_date,'%Y-%m') m, SUM(total) t FROM purchases WHERE status='active' AND invoice_date >= ? GROUP BY m", [$first]) as $r) { if (isset($months[$r['m']])) { $months[$r['m']]['p'] = (float)$r['t']; } }
$monthNames = ['01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل', '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس', '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'];
$labels = array_map(fn($k) => $monthNames[substr($k, 5)] . ' ' . substr($k, 2, 2), array_keys($months));

$lateCust = array_slice(array_filter(party_summaries('customer'), fn($r) => $r['status_key'] === 'late'), 0, 6);
$lateSupp = array_slice(array_filter(party_summaries('supplier'), fn($r) => $r['status_key'] === 'late'), 0, 6);
$recent = can('treasury') ? db_all('SELECT t.*, a.name AS acc_name FROM treasury_transactions t JOIN treasury_accounts a ON a.id=t.account_id ORDER BY t.txn_date DESC, t.id DESC LIMIT 8') : [];

page_start('الرئيسية', 'dashboard');
?>
<?php if ($fin): ?>
<div class="row g-3 mb-3">
  <div class="col-xl-3 col-md-6"><div class="kpi gold"><div class="label">رصيد الخزينة والبنك</div><div class="value num"><?= money($cashTotal) ?></div><div class="sub"><?= CURRENCY ?> — <?= count($accounts) ?> حساب</div><i class="bi bi-safe2 bg"></i></div></div>
  <div class="col-xl-3 col-md-6"><div class="kpi green"><div class="label">مستحق لنا على العملاء</div><div class="value num"><?= money($recv) ?></div><div class="sub">منها متأخر: <b class="<?= $overRecv > 0 ? 'text-danger' : '' ?>"><?= money($overRecv) ?></b></div><i class="bi bi-people bg"></i></div></div>
  <div class="col-xl-3 col-md-6"><div class="kpi red"><div class="label">مستحق للموردين علينا</div><div class="value num"><?= money($pay) ?></div><div class="sub">منها متأخر: <b class="<?= $overPay > 0 ? 'text-danger' : '' ?>"><?= money($overPay) ?></b></div><i class="bi bi-truck bg"></i></div></div>
  <div class="col-xl-3 col-md-6"><div class="kpi"><div class="label">أصناف تحتاج إعادة طلب</div><div class="value num"><?= count($reorder) ?></div><div class="sub"><a href="<?= e(url('stock/?status=reorder')) ?>">عرض القائمة</a></div><i class="bi bi-box-seam bg"></i></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="kpi"><div class="label">مبيعات الشهر</div><div class="value num"><?= money($salesM) ?></div></div></div>
  <div class="col-md-4"><div class="kpi"><div class="label">مشتريات الشهر</div><div class="value num"><?= money($purM) ?></div></div></div>
  <div class="col-md-4"><div class="kpi"><div class="label">مصروفات الشهر</div><div class="value num"><?= money($expM) ?></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="cardx h-100"><div class="head"><i class="bi bi-bar-chart-line"></i> المبيعات والمشتريات — آخر 6 شهور</div><div class="body"><canvas id="chart" height="110"></canvas></div></div></div>
  <div class="col-lg-4"><div class="cardx h-100"><div class="head"><i class="bi bi-wallet2"></i> أرصدة الحسابات</div><ul class="list-group list-group-flush">
    <?php foreach ($accounts as $a): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($a['name']) ?></span><b class="num <?= $a['bal'] < 0 ? 'amount-neg' : '' ?>"><?= money($a['bal']) ?></b></li><?php endforeach; ?>
  </ul></div></div>
</div>
<?php else: ?>
<div class="row g-3 mb-3"><div class="col-md-4"><div class="kpi"><div class="label">أصناف تحتاج إعادة طلب</div><div class="value num"><?= count($reorder) ?></div><div class="sub"><a href="<?= e(url('stock/?status=reorder')) ?>">عرض القائمة</a></div><i class="bi bi-box-seam bg"></i></div></div></div>
<?php endif; ?>
<div class="row g-3">
  <?php if ($fin && (can('customers') || can('suppliers'))): ?>
  <div class="col-lg-6"><div class="cardx h-100"><div class="head"><i class="bi bi-exclamation-triangle text-gold"></i> حسابات متأخرة</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الحساب</th><th class="num">المتأخر</th><th class="num">أقدم تأخير</th></tr></thead><tbody>
    <?php foreach ($lateCust as $r): ?><tr><td><?= badge('عميل', 'success') ?> <a href="<?= e(url('customers/statement.php?id=' . (int)$r['id'])) ?>"><?= e($r['name']) ?></a></td><td class="num amount-neg"><?= money($r['overdue']) ?></td><td class="num"><?= (int)$r['max_days'] ?> يوم</td></tr><?php endforeach; ?>
    <?php foreach ($lateSupp as $r): ?><tr><td><?= badge('مورد', 'warning') ?> <a href="<?= e(url('suppliers/statement.php?id=' . (int)$r['id'])) ?>"><?= e($r['name']) ?></a></td><td class="num amount-neg"><?= money($r['overdue']) ?></td><td class="num"><?= (int)$r['max_days'] ?> يوم</td></tr><?php endforeach; ?>
    <?php if (!$lateCust && !$lateSupp): ?><tr><td colspan="3" class="text-center text-muted py-3">لا توجد حسابات متأخرة 👍</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
  <?php endif; ?>
  <?php if ($fin && $recent): ?>
  <div class="col-lg-6"><div class="cardx h-100"><div class="head"><i class="bi bi-clock-history"></i> آخر حركات الخزينة <a class="ms-auto small" href="<?= e(url('treasury/')) ?>">عرض الكل</a></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>التاريخ</th><th>البيان</th><th class="num">المبلغ</th></tr></thead><tbody>
    <?php foreach ($recent as $r): $in = (float)$r['receipt_amount'] > 0; ?><tr><td><?= fdate($r['txn_date']) ?></td><td><?= e($r['description']) ?><div class="small text-muted"><?= e($r['category']) ?></div></td><td class="num <?= $in ? 'amount-pos' : 'amount-neg' ?>"><?= $in ? '+' : '−' ?> <?= money($in ? $r['receipt_amount'] : $r['payment_amount']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
  <?php endif; ?>
  <?php if ($reorder): ?>
  <div class="col-12"><div class="cardx"><div class="head"><i class="bi bi-box-seam"></i> أصناف تحتاج إعادة طلب</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الصنف</th><th class="num">الرصيد الحالي</th><th class="num">حد إعادة الطلب</th></tr></thead><tbody>
    <?php foreach (array_slice($reorder, 0, 8) as $r): ?><tr><td><?= e($r['name']) ?></td><td class="num amount-neg"><?= qty($r['current_qty']) ?> <?= e($r['unit']) ?></td><td class="num"><?= qty($r['reorder_level']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
  <?php endif; ?>
</div>
<?php
page_end(!$fin ? [] : ['scripts' => ['https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js'], 'inline_js' => '<script>
(function(){ if (!window.Chart) return;
  Chart.defaults.font.family = "Cairo, sans-serif";
  new Chart(document.getElementById("chart"), { type: "bar", data: { labels: ' . json_encode($labels, JSON_UNESCAPED_UNICODE) . ',
    datasets: [ { label: "المبيعات", data: ' . json_encode(array_map(fn($m) => round($m['s'], 2), array_values($months))) . ', backgroundColor: "#c9972b", borderRadius: 6 },
                { label: "المشتريات", data: ' . json_encode(array_map(fn($m) => round($m['p'], 2), array_values($months))) . ', backgroundColor: "#17436b", borderRadius: 6 } ] },
    options: { responsive: true, plugins: { legend: { position: "bottom" } }, scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return v.toLocaleString("en-US"); } } } } } });
})();
</script>']);
