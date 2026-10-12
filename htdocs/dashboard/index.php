<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('dashboard');

$today = today(); $monthStart = date('Y-m-01');
$fin = can('reports') || can('treasury');   // الأرقام المالية لمن له صلاحية فقط
$showSales = can('sales') || can('reports');

// ── أكتر منتج بيتباع ───────────────────────────────────────────────────
$p = get_str('p', 10); if (!in_array($p, ['month', 'year', 'all'], true)) { $p = 'month'; }
$periods = ['month' => ['هذا الشهر', $monthStart, $today], 'year' => ['هذه السنة', date('Y-01-01'), $today], 'all' => ['كل الفترة', null, null]];
$top = $showSales ? top_selling_items($periods[$p][1], $periods[$p][2], 5) : [];
$topMax = $top ? max(1e-9, (float)$top[0]['qty_sold']) : 1;

if ($fin) {
    $accounts = db_all('SELECT id, name, acc_type FROM treasury_accounts WHERE is_active=1 ORDER BY id');
    $cashTotal = 0.0; foreach ($accounts as &$a) { $a['bal'] = treasury_balance((int)$a['id']); $cashTotal += $a['bal']; } unset($a);
    $recv = (float)db_val('SELECT COALESCE(SUM(GREATEST(b,0)),0) FROM (SELECT SUM(invoice_amount - payment_amount) AS b FROM customer_ledger GROUP BY customer_id) t');
    $pay  = (float)db_val('SELECT COALESCE(SUM(GREATEST(b,0)),0) FROM (SELECT SUM(invoice_amount - payment_amount) AS b FROM supplier_ledger GROUP BY supplier_id) t');
    $overRecv = (float)db_val("SELECT COALESCE(SUM(total - paid_amount),0) FROM sales WHERE status='active' AND total - paid_amount > 0.005 AND due_date < ?", [$today]);
    $overPay  = (float)db_val("SELECT COALESCE(SUM(total - paid_amount),0) FROM purchases WHERE status='active' AND total - paid_amount > 0.005 AND due_date < ?", [$today]);
    $salesM = (float)db_val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status='active' AND invoice_date >= ?", [$monthStart]);
    $purM   = (float)db_val("SELECT COALESCE(SUM(total),0) FROM purchases WHERE status='active' AND invoice_date >= ?", [$monthStart]);
    $invExp = invoice_expenses($monthStart, $today);   // مصروفات الفواتير فقط (نقل + رسوم أخرى)
    $expM   = $invExp['total'];
    $expIn  = "'" . implode("','", EXPENSE_CATEGORIES) . "'";
    $treasExpM = (float)db_val("SELECT COALESCE(SUM(payment_amount),0) FROM treasury_transactions WHERE category IN ($expIn) AND txn_date >= ?", [$monthStart]);
    $lateInv = overdue_invoices(8, can('customers') || can('sales'), can('suppliers') || can('purchases'));

    $months = []; for ($i = 5; $i >= 0; $i--) { $months[date('Y-m', strtotime("first day of -$i month"))] = ['s' => 0.0, 'p' => 0.0]; }
    $first = array_key_first($months) . '-01';
    foreach (db_all("SELECT DATE_FORMAT(invoice_date,'%Y-%m') m, SUM(total) t FROM sales WHERE status='active' AND invoice_date >= ? GROUP BY m", [$first]) as $r) { if (isset($months[$r['m']])) { $months[$r['m']]['s'] = (float)$r['t']; } }
    foreach (db_all("SELECT DATE_FORMAT(invoice_date,'%Y-%m') m, SUM(total) t FROM purchases WHERE status='active' AND invoice_date >= ? GROUP BY m", [$first]) as $r) { if (isset($months[$r['m']])) { $months[$r['m']]['p'] = (float)$r['t']; } }
    $monthNames = ['01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل', '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس', '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'];
    $labels = array_map(fn($k) => $monthNames[substr($k, 5)] . ' ' . substr($k, 2, 2), array_keys($months));

    $recent = can('treasury') ? db_all('SELECT t.*, a.name AS acc_name FROM treasury_transactions t JOIN treasury_accounts a ON a.id=t.account_id ORDER BY t.txn_date DESC, t.id DESC LIMIT 7') : [];
}
$reorder = can('stock') || $fin ? array_values(array_filter(stock_summary(null, null, $today), fn($r) => $r['is_active'] && $r['stock_status'] === 'reorder')) : [];

page_start('الرئيسية', 'dashboard', ['subtitle' => 'نظرة سريعة على الشركة اليوم']);
?>
<?php if ($fin): ?>
<div class="row g-3 mb-3">
  <div class="col-xl-3 col-md-6"><div class="kpi gold"><div class="label">رصيد الخزينة والبنك</div><div class="value num" data-count="<?= round($cashTotal, 2) ?>"><?= egp($cashTotal) ?></div><div class="sub"><?= CURRENCY ?> — <?= count($accounts) ?> حساب</div><i class="bi bi-safe2 bg"></i></div></div>
  <div class="col-xl-3 col-md-6"><div class="kpi green"><div class="label">المستحق لنا على العملاء</div><div class="value num" data-count="<?= round($recv, 2) ?>"><?= egp($recv) ?></div><div class="sub">منها متأخر: <b class="<?= $overRecv > 0 ? 'text-danger' : '' ?>"><?= egp($overRecv) ?></b></div><i class="bi bi-people bg"></i></div></div>
  <div class="col-xl-3 col-md-6"><div class="kpi red"><div class="label">المستحق للموردين علينا</div><div class="value num" data-count="<?= round($pay, 2) ?>"><?= egp($pay) ?></div><div class="sub">منها متأخر: <b class="<?= $overPay > 0 ? 'text-danger' : '' ?>"><?= egp($overPay) ?></b></div><i class="bi bi-truck bg"></i></div></div>
  <div class="col-xl-3 col-md-6"><div class="kpi"><div class="label">أصناف تحتاج إعادة طلب</div><div class="value num" data-count="<?= count($reorder) ?>" data-int="1"><?= count($reorder) ?></div><div class="sub"><a href="<?= e(url('stock/?status=reorder')) ?>">عرض القائمة</a></div><i class="bi bi-box-seam bg"></i></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="kpi green"><div class="label">مبيعات الشهر</div><div class="value num" data-count="<?= round($salesM, 2) ?>"><?= egp($salesM) ?></div><i class="bi bi-graph-up-arrow bg"></i></div></div>
  <div class="col-md-4"><div class="kpi"><div class="label">مشتريات الشهر (إجمالي الفواتير)</div><div class="value num" data-count="<?= round($purM, 2) ?>"><?= egp($purM) ?></div><i class="bi bi-bag-check bg"></i></div></div>
  <div class="col-md-4"><div class="kpi gold"><div class="label">مصروفات الفواتير هذا الشهر</div><div class="value num" data-count="<?= round($expM, 2) ?>"><?= egp($expM) ?></div><div class="sub">نقل: <b><?= egp($invExp['transport']) ?></b> · رسوم أخرى: <b><?= egp($invExp['other_fees']) ?></b></div><div class="sub">مصروفات الخزينة (إيجار/مرتبات/…): <b><?= egp($treasExpM) ?></b></div><i class="bi bi-receipt-cutoff bg"></i></div></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php if ($showSales): ?>
  <div class="col-lg-5"><div class="cardx h-100"><div class="head"><i class="bi bi-trophy"></i> أكتر منتج بيتباع
      <span class="seg ms-auto"><?php foreach ($periods as $k => [$lbl]): ?><a class="<?= $p === $k ? 'on' : '' ?>" href="?p=<?= $k ?>"><?= e($lbl) ?></a><?php endforeach; ?></span></div>
    <div class="body">
    <?php if ($top): ?>
      <div class="top-hero"><div class="trophy"><i class="bi bi-trophy-fill"></i></div>
        <div><div class="small text-muted fw-bold">المركز الأول — <?= e($periods[$p][0]) ?></div><div class="nm"><?= e($top[0]['name']) ?></div>
        <div class="small text-muted"><b class="text-navy num d-inline-block"><?= qty($top[0]['qty_sold']) ?></b> <?= e($top[0]['unit']) ?> · إيراد <b class="text-navy num d-inline-block"><?= egp($top[0]['revenue']) ?></b> <?= CURRENCY ?> · <?= (int)$top[0]['invoices'] ?> فاتورة</div></div></div>
      <?php foreach ($top as $i => $t): ?>
        <div class="rank-row"><span class="no"><?= $i + 1 ?></span>
          <div><div class="fw-bold"><?= e($t['name']) ?></div><div class="bar"><i style="width:<?= max(4, round((float)$t['qty_sold'] / $topMax * 100)) ?>%"></i></div></div>
          <div class="text-end small"><b class="num d-inline-block"><?= qty($t['qty_sold']) ?></b> <?= e($t['unit']) ?><div class="text-muted num"><?= egp($t['revenue']) ?></div></div></div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="text-center text-muted py-5"><i class="bi bi-bar-chart fs-1 d-block mb-2 text-gold"></i>لسه مفيش مبيعات في الفترة دي</div>
    <?php endif; ?>
    </div></div></div>
  <?php endif; ?>
  <?php if ($fin): ?>
  <div class="col-lg-<?= $showSales ? '7' : '8' ?>"><div class="cardx h-100"><div class="head"><i class="bi bi-bar-chart-line"></i> المبيعات والمشتريات — آخر 6 شهور</div><div class="body"><canvas id="chart" height="<?= $showSales ? '130' : '110' ?>"></canvas></div></div></div>
  <?php if (!$showSales): ?><div class="col-lg-4"><div class="cardx h-100"><div class="head"><i class="bi bi-wallet2"></i> أرصدة الحسابات</div><ul class="list-group list-group-flush">
    <?php foreach ($accounts as $a): ?><li class="list-group-item bg-transparent d-flex justify-content-between"><span><?= e($a['name']) ?></span><b class="num <?= $a['bal'] < 0 ? 'amount-neg' : '' ?>"><?= egp($a['bal']) ?></b></li><?php endforeach; ?></ul></div></div><?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($fin): ?>
<div class="row g-3 mb-3">
  <?php if ($showSales): ?><div class="col-lg-4"><div class="cardx h-100"><div class="head"><i class="bi bi-wallet2"></i> أرصدة الحسابات</div><ul class="list-group list-group-flush">
    <?php foreach ($accounts as $a): ?><li class="list-group-item bg-transparent d-flex justify-content-between"><span><?= e($a['name']) ?></span><b class="num <?= $a['bal'] < 0 ? 'amount-neg' : '' ?>"><?= egp($a['bal']) ?></b></li><?php endforeach; ?></ul></div></div><?php endif; ?>
  <div class="col-lg-<?= $showSales ? '8' : '12' ?>"><div class="cardx h-100"><div class="head"><i class="bi bi-alarm"></i> فواتير متأخرة عن الاستحقاق
    <span class="ms-auto d-flex gap-2 flex-wrap">
      <?php if ($overRecv > 0.005): ?><span class="badge-soft success">لنا: <b><?= egp($overRecv) ?></b></span><?php endif; ?>
      <?php if ($overPay > 0.005): ?><span class="badge-soft danger">علينا: <b><?= egp($overPay) ?></b></span><?php endif; ?></span></div>
    <?php if (!$lateInv): ?><div class="p-4 text-center text-muted"><i class="bi bi-check2-circle fs-2 text-success d-block"></i>مفيش أي فاتورة متأخرة 👍</div>
    <?php else: ?><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>الفاتورة</th><th>الطرف</th><th>الاستحقاق</th><th class="num">التأخير</th><th class="num">المتبقي</th><th></th></tr></thead><tbody>
    <?php foreach ($lateInv as $r): $isS = $r['kind'] === 'sales'; $sev = $r['days_late'] > 30 ? 'danger' : ($r['days_late'] > 7 ? 'warning' : 'info'); ?>
      <tr><td><?= badge($isS ? 'بيع' : 'شراء', $isS ? 'success' : 'warning') ?> <a href="<?= e(url($r['kind'] . '/view.php?id=' . (int)$r['id'])) ?>" class="num d-inline-block"><?= e($r['doc_no']) ?></a></td>
        <td><a href="<?= e(url(($isS ? 'customers' : 'suppliers') . '/statement.php?id=' . (int)$r['party_id'])) ?>"><?= e($r['party_name']) ?></a></td>
        <td class="small"><?= fdate($r['due_date']) ?></td>
        <td class="num"><?= badge($r['days_late'] . ' يوم', $sev) ?></td>
        <td class="num amount-neg fw-bold"><?= egp($r['remaining']) ?></td>
        <td class="text-end ps-3"><?php if (can('vouchers')): ?><a class="btn btn-sm btn-gold text-nowrap" href="<?= e(url('vouchers/form.php?type=' . ($isS ? 'receipt' : 'payment') . '&party=' . (int)$r['party_id'])) ?>"><i class="bi bi-cash-coin"></i> <?= $isS ? 'تحصيل' : 'سداد' ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <div class="px-3 py-2 small text-muted d-flex gap-3"><?php if (can('sales')): ?><a href="<?= e(url('sales/?status=late')) ?>">كل فواتير البيع المتأخرة</a><?php endif; ?><?php if (can('purchases')): ?><a href="<?= e(url('purchases/?status=late')) ?>">كل فواتير الشراء المتأخرة</a><?php endif; ?></div>
    <?php endif; ?>
  </div></div>
</div>
<?php if ($recent): ?>
<div class="cardx mb-3"><div class="head"><i class="bi bi-clock-history"></i> آخر حركات الخزينة <a class="ms-auto small" href="<?= e(url('treasury/')) ?>">عرض الكل</a></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>التاريخ</th><th>البيان</th><th>التصنيف</th><th class="num">المبلغ</th></tr></thead><tbody>
  <?php foreach ($recent as $r): $in = (float)$r['receipt_amount'] > 0; ?><tr><td><?= fdate($r['txn_date']) ?></td><td><?= e($r['description']) ?></td><td><?= badge($r['category'], $in ? 'success' : 'warning') ?></td><td class="num <?= $in ? 'amount-pos' : 'amount-neg' ?>"><?= $in ? '+' : '−' ?> <?= egp($in ? $r['receipt_amount'] : $r['payment_amount']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php elseif ($reorder): ?>
<div class="kpi mb-3" style="max-width:340px"><div class="label">أصناف تحتاج إعادة طلب</div><div class="value num"><?= count($reorder) ?></div><div class="sub"><a href="<?= e(url('stock/?status=reorder')) ?>">عرض القائمة</a></div><i class="bi bi-box-seam bg"></i></div>
<?php endif; ?>

<?php if ($reorder): ?>
<div class="cardx"><div class="head"><i class="bi bi-box-seam"></i> أصناف تحتاج إعادة طلب</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>الصنف</th><th class="num">الرصيد الحالي</th><th class="num">حد إعادة الطلب</th></tr></thead><tbody>
  <?php foreach (array_slice($reorder, 0, 8) as $r): ?><tr><td><?= e($r['name']) ?></td><td class="num amount-neg"><?= qty($r['current_qty']) ?> <?= e($r['unit']) ?></td><td class="num"><?= qty($r['reorder_level']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php
page_end(!$fin ? [] : ['scripts' => ['https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js'], 'inline_js' => '<script>
(function(){ if (!window.Chart) return;
  Chart.defaults.font.family = "Plex, Tahoma, sans-serif"; Chart.defaults.color = "#55728c";
  var ctx = document.getElementById("chart").getContext("2d");
  var g1 = ctx.createLinearGradient(0,0,0,300); g1.addColorStop(0,"#f5cf72"); g1.addColorStop(1,"#E8A81C");
  var g2 = ctx.createLinearGradient(0,0,0,300); g2.addColorStop(0,"#5aaee9"); g2.addColorStop(1,"#1d6fae");
  new Chart(ctx, { type: "bar", data: { labels: ' . json_encode($labels, JSON_UNESCAPED_UNICODE) . ',
    datasets: [ { label: "المبيعات", data: ' . json_encode(array_map(fn($m) => round($m['s'], 2), array_values($months))) . ', backgroundColor: g1, borderRadius: 10, borderSkipped: false, maxBarThickness: 34 },
                { label: "المشتريات", data: ' . json_encode(array_map(fn($m) => round($m['p'], 2), array_values($months))) . ', backgroundColor: g2, borderRadius: 10, borderSkipped: false, maxBarThickness: 34 } ] },
    options: { responsive: true, plugins: { legend: { position: "bottom", labels: { usePointStyle: true, pointStyle: "rectRounded", boxWidth: 10 } } },
      scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: "rgba(29,111,174,.1)" }, border: { display: false }, ticks: { callback: function(v){ return v.toLocaleString("en-US"); } } } } } });
})();
</script>']);
