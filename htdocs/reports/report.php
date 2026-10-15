<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('reports');

$r = get_str('r', 40);
$from = parse_date($_GET['from'] ?? '') ?: date('Y-m-01');
$to = parse_date($_GET['to'] ?? '') ?: today();
$today = today();
$usesPeriod = in_array($r, ['summary', 'expenses', 'treasury_expenses', 'sales_by_customer', 'purchases_by_supplier'], true);

/** كل تقرير بيرجّع: عنوان، أعمدة، صفوف، أعمدة رقمية، وإجماليات اختيارية */
$title = ''; $heads = []; $rows = []; $numCols = []; $totalCols = []; $note = '';
switch ($r) {
    case 'summary':
        $title = 'ملخص الفترة';
        $sales = (float)db_val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status='active' AND invoice_date BETWEEN ? AND ?", [$from, $to]);
        $purch = (float)db_val("SELECT COALESCE(SUM(total),0) FROM purchases WHERE status='active' AND invoice_date BETWEEN ? AND ?", [$from, $to]);
        $coll  = (float)db_val("SELECT COALESCE(SUM(receipt_amount),0) FROM treasury_transactions WHERE category IN ('تحصيل عميل','مبيعات') AND txn_date BETWEEN ? AND ?", [$from, $to]);
        $paidS = (float)db_val("SELECT COALESCE(SUM(payment_amount),0) FROM treasury_transactions WHERE category='شراء بضاعة' AND txn_date BETWEEN ? AND ?", [$from, $to]);
        $exp   = db_all("SELECT category, SUM(payment_amount) t FROM treasury_transactions WHERE category IN ('" . implode("','", EXPENSE_CATEGORIES) . "') AND txn_date BETWEEN ? AND ? GROUP BY category", [$from, $to]);
        $expT  = array_sum(array_column($exp, 't'));
        $netCash = (float)db_val('SELECT COALESCE(SUM(receipt_amount - payment_amount),0) FROM treasury_transactions WHERE txn_date BETWEEN ? AND ?', [$from, $to]);
        $heads = ['البند', 'المبلغ (' . CURRENCY . ')'];
        $ie = invoice_expenses($from, $to);
        $rows = [['إجمالي المبيعات (فواتير فعّالة)', $sales], ['إجمالي المشتريات (إجمالي فواتير الشراء)', $purch],
                 ['مصروفات الفواتير: مصاريف النقل', $ie['transport']], ['مصروفات الفواتير: رسوم أخرى', $ie['other_fees']], ['إجمالي مصروفات الفواتير', $ie['total']],
                 ['المقبوض من العملاء (تحصيلات + مبيعات نقدية)', $coll], ['المدفوع للموردين (شراء بضاعة)', $paidS]];
        foreach ($exp as $x) { $rows[] = ['مصروف خزينة: ' . $x['category'], (float)$x['t']]; }
        $rows[] = ['إجمالي مصروفات الخزينة (منفصلة عن الفواتير)', $expT]; $rows[] = ['صافي حركة الخزينة والبنك في الفترة', $netCash];
        $rows[] = ['فرق المبيعات عن المشتريات ومصروفات الفواتير (تقريبي — بدون تكلفة البضاعة المباعة)', $sales - $purch - $ie['total']];
        $numCols = [1];
        $note = 'الفرق التقريبي لا يمثل ربحاً محاسبياً دقيقاً لأنه لا يحسب تكلفة البضاعة المباعة فعلياً؛ استشر المحاسب للمركز المالي.';
        break;

    case 'aging_customers': case 'aging_suppliers':
        $type = $r === 'aging_customers' ? 'customer' : 'supplier';
        $pc = party_cfg($type); $ic = inv_cfg($type === 'customer' ? 'sale' : 'purchase');
        $title = $r === 'aging_customers' ? 'أعمار ديون العملاء' : 'أعمار مستحقات الموردين';
        $heads = [$pc['label'], 'الرصيد الإجمالي', 'لم يحن موعده', 'متأخر 1-30 يوم', 'متأخر 31-60 يوم', 'متأخر 61-90 يوم', 'متأخر +90 يوم', 'حالة الحساب'];
        $b = db_all("SELECT {$ic['party_col']} AS pid,
            SUM(CASE WHEN due_date IS NULL OR due_date >= ? THEN total - paid_amount ELSE 0 END) AS c0,
            SUM(CASE WHEN due_date < ? AND DATEDIFF(?, due_date) BETWEEN 1 AND 30 THEN total - paid_amount ELSE 0 END) AS c1,
            SUM(CASE WHEN due_date < ? AND DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN total - paid_amount ELSE 0 END) AS c2,
            SUM(CASE WHEN due_date < ? AND DATEDIFF(?, due_date) BETWEEN 61 AND 90 THEN total - paid_amount ELSE 0 END) AS c3,
            SUM(CASE WHEN due_date < ? AND DATEDIFF(?, due_date) > 90 THEN total - paid_amount ELSE 0 END) AS c4
            FROM {$ic['table']} WHERE status='active' AND total - paid_amount > 0.005 GROUP BY {$ic['party_col']}",
            array_fill(0, 9, $today));
        $bm = array_column($b, null, 'pid');
        foreach (party_summaries($type) as $p) {
            if (abs((float)$p['balance']) < 0.005 && !isset($bm[$p['id']])) { continue; }
            $x = $bm[$p['id']] ?? ['c0' => 0, 'c1' => 0, 'c2' => 0, 'c3' => 0, 'c4' => 0];
            $rows[] = [$p['name'], (float)$p['balance'], (float)$x['c0'], (float)$x['c1'], (float)$x['c2'], (float)$x['c3'], (float)$x['c4'], PARTY_STATUS_LABELS[$p['status_key']]];
        }
        $numCols = [1, 2, 3, 4, 5, 6]; $totalCols = [1, 2, 3, 4, 5, 6];
        $note = 'الرصيد الإجمالي يشمل الرصيد الافتتاحي والدفعات على الحساب والإشعارات؛ الشرائح مبنية على الفواتير المفتوحة فقط.';
        $usesPeriod = false;
        break;

    case 'expenses':
        $title = 'تقرير المصروفات (من فواتير المشتريات)';
        $heads = ['التاريخ', 'رقم الفاتورة', 'المورد', 'مصاريف النقل', 'رسوم أخرى', 'إجمالي المصروفات'];
        foreach (db_all("SELECT p.invoice_date, p.doc_no, s.name, p.transport, p.other_fees FROM purchases p JOIN suppliers s ON s.id=p.supplier_id
                         WHERE p.status='active' AND (p.transport > 0 OR p.other_fees > 0) AND p.invoice_date BETWEEN ? AND ? ORDER BY p.invoice_date, p.id", [$from, $to]) as $t) {
            $rows[] = [$t['invoice_date'], $t['doc_no'], $t['name'], (float)$t['transport'], (float)$t['other_fees'], (float)$t['transport'] + (float)$t['other_fees']];
        }
        $numCols = [3, 4, 5]; $totalCols = [3, 4, 5];
        $note = 'المصروفات هنا هي المصاريف المسجلة داخل فواتير المشتريات فقط (النقل + الرسوم الأخرى). المشتريات نفسها بتتحسب بإجمالي الفواتير في تقرير المشتريات والرئيسية.';
        break;

    case 'treasury_expenses':
        $title = 'مصروفات الخزينة (إيجار، مرتبات، نقل...)';
        $heads = ['التاريخ', 'رقم المستند', 'البيان', 'التصنيف', 'المبلغ', 'طريقة الدفع', 'المسؤول'];
        foreach (db_all("SELECT * FROM treasury_transactions WHERE category IN ('" . implode("','", EXPENSE_CATEGORIES) . "') AND txn_date BETWEEN ? AND ? ORDER BY txn_date, id", [$from, $to]) as $t) {
            $rows[] = [$t['txn_date'], $t['doc_no'], $t['description'], $t['category'], (float)$t['payment_amount'], $t['payment_method'], (string)$t['responsible']];
        }
        $numCols = [4]; $totalCols = [4];
        break;

    case 'sales_by_customer': case 'purchases_by_supplier':
        $isS = $r === 'sales_by_customer';
        $type = $isS ? 'customer' : 'supplier'; $pc = party_cfg($type); $ic = inv_cfg($isS ? 'sale' : 'purchase');
        $title = $isS ? 'المبيعات حسب العميل' : 'المشتريات حسب المورد';
        $heads = [$pc['label'], 'عدد الفواتير', 'إجمالي الفواتير', $isS ? 'المحصَّل على الفواتير' : 'المسدد على الفواتير', 'المتبقي على الفواتير', 'الرصيد الحالي للحساب'];
        $bal = array_column(array_map(fn($p) => ['id' => $p['id'], 'b' => $p['balance']], party_summaries($type)), 'b', 'id');
        foreach (db_all("SELECT p.id, p.name, COUNT(i.id) cnt, SUM(i.total) t, SUM(i.paid_amount) pd FROM {$ic['table']} i JOIN {$pc['table']} p ON p.id=i.{$ic['party_col']}
                         WHERE i.status='active' AND i.invoice_date BETWEEN ? AND ? GROUP BY p.id, p.name ORDER BY t DESC", [$from, $to]) as $x) {
            $rows[] = [$x['name'], (int)$x['cnt'], (float)$x['t'], (float)$x['pd'], (float)$x['t'] - (float)$x['pd'], (float)($bal[$x['id']] ?? 0)];
        }
        $numCols = [1, 2, 3, 4, 5]; $totalCols = [1, 2, 3, 4];
        break;

    case 'stock_value':
        $title = 'الأصناف المطلوب إعادة طلبها';
        $heads = ['كود الصنف', 'الصنف', 'الوحدة', 'الرصيد الحالي', 'حد إعادة الطلب', 'العجز عن الحد'];
        foreach (stock_summary(null, null, $today) as $s) {
            if ($s['is_active'] && $s['stock_status'] === 'reorder') { $rows[] = [$s['code'], $s['name'], $s['unit'], (float)$s['current_qty'], (float)$s['reorder_level'], (float)$s['reorder_level'] - (float)$s['current_qty']]; }
        }
        $numCols = [3, 4, 5];
        $usesPeriod = false;
        break;

    default:
        flash('error', 'تقرير غير معروف.');
        redirect('reports/');
}

if (isset($_GET['export'])) { csv_download($title, $heads, $rows); }
page_start($title, 'reports');
print_header($title . ($usesPeriod ? ' — من ' . fdate($from) . ' إلى ' . fdate($to) : ' — بتاريخ ' . fdate($today)));
$fmtCell = function ($v, bool $isNum, bool $isQty) {
    if ($isNum && is_numeric($v)) { return $isQty ? qty($v) : egp($v); }
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return fdate($v); }
    return e((string)$v);
};
$qtyReport = $r === 'stock_value';
?>
<div class="filters no-print"><form class="row g-2 align-items-end" method="get"><input type="hidden" name="r" value="<?= e($r) ?>">
  <?php if ($usesPeriod): ?>
  <div class="col-md-3"><label class="form-label">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-md-3"><label class="form-label">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-2"><button class="btn btn-brand w-100"><i class="bi bi-funnel"></i> عرض</button></div>
  <?php endif; ?>
  <div class="col-md-4 d-flex gap-2 ms-auto"><a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button><a class="btn btn-light" href="./">كل التقارير</a></div>
</form></div>
<div class="cardx"><div class="head"><i class="bi bi-bar-chart-line"></i> <?= e($title) ?> <span class="text-muted small fw-normal">(<?= count($rows) ?>)</span></div>
<div class="table-responsive"><table class="table table-hover align-middle">
  <thead><tr><?php foreach ($heads as $i => $h): ?><th class="<?= in_array($i, $numCols, true) ? 'num' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?><tr><?php foreach ($row as $i => $v): ?><td class="<?= in_array($i, $numCols, true) ? 'num' : '' ?>"><?= $fmtCell($v, in_array($i, $numCols, true), $qtyReport) ?></td><?php endforeach; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($heads) ?>" class="text-center text-muted py-4">لا توجد بيانات</td></tr><?php endif; ?>
  </tbody>
  <?php if ($totalCols && $rows): ?><tfoot><tr><?php foreach ($heads as $i => $h): ?><td class="<?= in_array($i, $numCols, true) ? 'num' : '' ?>"><?= $i === 0 ? 'الإجمالي' : (in_array($i, $totalCols, true) ? ($i === 1 && $r === 'sales_by_customer' || $i === 1 && $r === 'purchases_by_supplier' ? (string)array_sum(array_column($rows, $i)) : egp(array_sum(array_column($rows, $i)))) : '') ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?>
</table></div></div>
<?php if ($note): ?><p class="text-muted small mt-2"><?= e($note) ?></p><?php endif; ?>
<?php page_end();
