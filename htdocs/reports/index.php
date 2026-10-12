<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('reports');
page_start('التقارير', 'reports');
$cards = [
    ['summary', 'bi-clipboard-data', 'ملخص الفترة', 'المبيعات والمشتريات والمقبوضات والمدفوعات والمصروفات وصافي حركة الخزينة'],
    ['aging_customers', 'bi-people', 'أعمار ديون العملاء', 'رصيد كل عميل مقسّم حسب التأخر عن الاستحقاق'],
    ['aging_suppliers', 'bi-truck', 'أعمار مستحقات الموردين', 'رصيد كل مورد مقسّم حسب التأخر عن السداد'],
    ['expenses', 'bi-receipt-cutoff', 'مصروفات الفواتير', 'مصاريف النقل والرسوم الأخرى المسجلة داخل فواتير المشتريات فقط'],
    ['treasury_expenses', 'bi-cash-stack', 'مصروفات الخزينة', 'إيجار ومرتبات ومرافق ومصروفات مدفوعة من الخزينة'],
    ['sales_by_customer', 'bi-graph-up-arrow', 'المبيعات حسب العميل', 'إجمالي مبيعات وتحصيلات كل عميل في الفترة'],
    ['purchases_by_supplier', 'bi-bag-check', 'المشتريات حسب المورد', 'إجمالي مشتريات ومدفوعات كل مورد في الفترة'],
    ['stock_value', 'bi-boxes', 'الأصناف المطلوب إعادة طلبها', 'الأصناف اللي وصلت لحد إعادة الطلب أو أقل'],
];
?>
<div class="row g-3">
<?php foreach ($cards as [$k, $icon, $t, $d]): ?>
  <div class="col-md-6 col-xl-4"><a class="text-decoration-none" href="report.php?r=<?= $k ?>"><div class="cardx h-100"><div class="body d-flex gap-3 align-items-start">
    <div class="fs-1 text-gold"><i class="bi <?= $icon ?>"></i></div><div><div class="fw-bold text-navy fs-5"><?= e($t) ?></div><div class="text-muted small"><?= e($d) ?></div></div></div></div></a></div>
<?php endforeach; ?>
  <div class="col-md-6 col-xl-4"><a class="text-decoration-none" href="<?= e(url('stock/')) ?>"><div class="cardx h-100"><div class="body d-flex gap-3"><div class="fs-1 text-gold"><i class="bi bi-box-seam"></i></div><div><div class="fw-bold text-navy fs-5">حركة وأرصدة المخازن</div><div class="text-muted small">أول المدة / الوارد / المنصرف / الرصيد (شيت المخازن)</div></div></div></div></a></div>
  <div class="col-md-6 col-xl-4"><a class="text-decoration-none" href="<?= e(url('treasury/')) ?>"><div class="cardx h-100"><div class="body d-flex gap-3"><div class="fs-1 text-gold"><i class="bi bi-safe2"></i></div><div><div class="fw-bold text-navy fs-5">الخزينة والمصروفات</div><div class="text-muted small">المقبوضات والمدفوعات مع الرصيد بعد كل حركة</div></div></div></div></a></div>
</div>
<?php page_end();
