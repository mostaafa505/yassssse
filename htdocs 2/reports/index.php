<?php
$pageTitle = 'التقرير المالي التنفيذي / Financial Report';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'accountant'], 'view_reports');

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$cashierId = intval($_GET['cashier_id'] ?? 0);
$brandInfo = getActiveBrandInfo();

// 1. Fetch Sales
// returned_amount بيتحسب من جدول returns مباشرة (مش من status الفاتورة)،
// عشان يشتغل صح مع المرتجع الجزئي: فاتورة بـ290 حصلها مرتجع 40 ثم 75 لازم
// تفضل صافي إيراداتها 175 مش صفر ومش 290 — status بس بيقولنا "فيه مرتجع
// حصل" مش "قد إيه اترجع"، فمينفعش نعتمد عليه لوحده في حساب المبالغ.
$sql = "
    SELECT s.*, u.name_ar as cashier_name,
    COALESCE((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id), 0) as returned_amount
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
";
$params = [$dateFrom, $dateTo];

if ($cashierId > 0) {
    $sql .= " AND s.cashier_id = ?";
    $params[] = $cashierId;
}
$sql .= " ORDER BY s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// صافي كل فاتورة = إجماليها الأصلي ناقص أي مبلغ اترجع منها فعليًا (جزئي أو
// كامل) — ده بيشتغل صح لكل الحالات الثلاثة (completed / partial_return /
// returned) من غير ما نستثني ولا فاتورة من الحساب
foreach ($sales as &$s) {
    $s['net_amount'] = max(0, floatval($s['total']) - floatval($s['returned_amount']));
}
unset($s);

$totalDiscount = array_sum(array_column($sales, 'discount'));

$totalReturnedAmount = array_sum(array_column($sales, 'returned_amount'));
$returnedCount = count(array_filter($sales, fn($s) => floatval($s['returned_amount']) > 0));

$netRevenue = array_sum(array_column($sales, 'net_amount'));
$grossSalesBeforeReturn = array_sum(array_column($sales, 'total'));

// الكاش/البطاقة/التحويل: المبلغ اللي اتقبض فعليًا وقت البيع بكل طريقة دفع،
// ناقص أي مبلغ اترد فعليًا بنفس الطريقة (F-08 — النظام بقى يسجل طريقة رد
// المرتجع لكل عملية إرجاع). المرتجعات القديمة (قبل هذا التحديث) أو المرتجعة
// من فاتورة بطريقة دفع "متعددة" من غير ما يتحدد رد بأي طريقة، بتتجمع في
// "غير محدد" بدل ما تتخصم من طريقة عشوائية.
$refundSql = "
    SELECT r.refund_method, SUM(r.total) as amt
    FROM returns r
    JOIN sales s ON r.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
";
$refundParams = [$dateFrom, $dateTo];
if ($cashierId > 0) {
    $refundSql .= " AND s.cashier_id = ?";
    $refundParams[] = $cashierId;
}
$refundSql .= " GROUP BY r.refund_method";
$stmtRefund = $pdo->prepare($refundSql);
$stmtRefund->execute($refundParams);
$refundsByMethod = ['cash' => 0, 'card' => 0, 'transfer' => 0, 'unknown' => 0];
foreach ($stmtRefund->fetchAll() as $row) {
    $key = in_array($row['refund_method'], ['cash', 'card', 'transfer']) ? $row['refund_method'] : 'unknown';
    $refundsByMethod[$key] += floatval($row['amt']);
}

$totalCash = array_sum(array_column($sales, 'cash_amount')) - $refundsByMethod['cash'];
$totalCard = array_sum(array_column($sales, 'card_amount')) - $refundsByMethod['card'];
$totalTransfer = array_sum(array_column($sales, 'transfer_amount')) - $refundsByMethod['transfer'];
$totalRefundedUnknownMethod = $refundsByMethod['unknown'];

// 2. Fetch Expenses in Date Range
$stmtExp = $pdo->prepare("
    SELECT ee.*, u.name_ar as user_name
    FROM exhibition_expenses ee
    LEFT JOIN users u ON ee.created_by = u.id
    WHERE ee.expense_date BETWEEN ? AND ?
    ORDER BY ee.expense_date DESC, ee.id DESC
");
$stmtExp->execute([$dateFrom, $dateTo]);
$expenses = $stmtExp->fetchAll();
$totalExpenses = array_sum(array_column($expenses, 'amount'));

// 3. Operating Net Profit: Net Sales - Total Expenses
$finalNetProfit = $netRevenue - $totalExpenses;
$isProfitable = $finalNetProfit >= 0;

$cashiers = $pdo->query("SELECT id, name_ar FROM users WHERE role IN ('cashier', 'admin')")->fetchAll();

$categoryTitles = [
    'booth_rent'         => 'إيجار الجناح / المقر',
    'staff_wages'        => 'رواتب وأجور العمال والموظفين',
    'hospitality_food'   => 'طعام وإعاشة وضيافة',
    'packaging_supplies' => 'أكياس وباكدجات وتغليف',
    'shipping_transport' => 'نقل وشحن وتوصيل',
    'setup_decoration'   => 'تجهيز وديكور',
    'marketing_ads'      => 'تسويق وإعلانات',
    'utilities_permits'  => 'رسوم وتصاريح وكهرباء',
    'other'              => 'مصاريف أخرى ونثرية',
];
?>

<style>
/* Executive Report Styling */
.executive-card {
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 16px;
    position: relative;
}
.executive-card.primary {
    border-top: 4px solid #0f172a;
}
.executive-card.danger {
    border-top: 4px solid #b91c1c;
}
.executive-card.success {
    border-top: 4px solid #15803d;
}
.report-section-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 8px;
    margin-bottom: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.statement-table th {
    background-color: #f8fafc;
    color: #1e293b;
    font-weight: 700;
    font-size: 0.85rem;
}
.statement-table td {
    font-size: 0.88rem;
    vertical-align: middle;
}
@media print {
    body {
        background: #ffffff !important;
        font-family: 'Cairo', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    }
    .no-print, .top-navbar, .sidebar, footer, .sidebar-overlay {
        display: none !important;
    }
    .main-wrapper {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .content-body {
        padding: 0 !important;
    }
    #reportPrintArea {
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }
    .executive-card {
        border: 1px solid #000000 !important;
        box-shadow: none !important;
    }
    .table {
        border: 1px solid #000000 !important;
    }
    .table th, .table td {
        border: 1px solid #000000 !important;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h1 class="title-large mb-1">التقرير المالي التنفيذي</h1>
        <p class="text-subtle mb-0">بيان شامل بالإيرادات المحصلة، المصاريف التشغيلية، وصافي الأرباح النهائي</p>
    </div>
    <div class="d-flex gap-2">
        <a href="export.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&cashier_id=<?= $cashierId ?>" class="btn btn-outline-success px-3 py-2 fw-bold">
            <i class="bi bi-file-earmark-excel me-1"></i> تصدير إكسل (Excel)
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary px-3 py-2 fw-bold">
            <i class="bi bi-printer me-1"></i> طباعة التقرير
        </button>
        <button onclick="downloadReportPDF()" class="btn btn-primary px-3 py-2 fw-bold shadow-sm">
            <i class="bi bi-file-earmark-pdf me-1"></i> تصدير PDF رسمي
        </button>
    </div>
</div>

<!-- Filters (Screen Only) -->
<div class="panel mb-4 no-print">
    <div class="panel-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label text-caption fw-bold">من تاريخ</label>
                <input type="date" name="date_from" class="form-control text-start" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label text-caption fw-bold">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control text-start" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label text-caption fw-bold">الموظف المسؤول</label>
                <select name="cashier_id" class="form-select">
                    <option value="">جميع الموظفين</option>
                    <?php foreach ($cashiers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $cashierId == $c['id'] ? 'selected' : '' ?>><?= e($c['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-filter me-1"></i> عرض التقرير</button>
            </div>
        </form>
    </div>
</div>

<!-- Report Printable Area -->
<div id="reportPrintArea" class="panel p-4 p-md-5 bg-white shadow-sm border">
    <!-- Corporate Official Header -->
    <div class="d-flex justify-content-between align-items-center border-bottom pb-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <img src="<?= e($brandInfo['logo']) ?>" alt="<?= e($brandInfo['name_en']) ?>" style="height: 58px; object-fit: contain;">
            <div class="border-end pe-3" style="border-color: #cbd5e1 !important;">
                <h3 class="fw-bold mb-1" style="color: #0f172a; letter-spacing: -0.5px;"><?= APP_NAME ?> <?= APP_NAME_EN ?></h3>
                <div class="text-secondary small fw-semibold">نظام إدارة المبيعات والمصروفات والأرباح</div>
            </div>
        </div>
        <div class="text-start text-md-end">
            <h5 class="fw-bold text-dark mb-1">التقرير المالي التنفيذي (P&L)</h5>
            <div class="text-secondary small mb-1">
                الفترة: <strong class="text-dark num"><?= $dateFrom ?></strong> إلى <strong class="text-dark num"><?= $dateTo ?></strong>
            </div>
            <div class="text-muted small">
                تاريخ الإصدار: <span class="num"><?= date('Y-m-d H:i') ?></span> | العملة: <strong class="text-dark">AED</strong>
            </div>
        </div>
    </div>

    <!-- Executive Summary 3 Cards (Clean Corporate Design - Zero Emojis) -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Sales -->
        <div class="col-md-4">
            <div class="executive-card primary">
                <div class="text-secondary small fw-bold mb-1">إجمالي الإيرادات والمبيعات المحصلة</div>
                <div class="fs-3 fw-bold num text-dark mb-1"><?= money($netRevenue) ?></div>
                <div class="text-muted small">إجمالي مبيعات الفواتير الصادرة</div>
            </div>
        </div>

        <!-- 2. Total Expenses -->
        <div class="col-md-4">
            <div class="executive-card danger">
                <div class="text-danger small fw-bold mb-1">إجمالي المصاريف التشغيلية</div>
                <div class="fs-3 fw-bold num text-danger mb-1">-<?= money($totalExpenses) ?></div>
                <div class="text-muted small">إيجار، رواتب عمال، إعاشة، باكدجات (<?= count($expenses) ?> بند)</div>
            </div>
        </div>

        <!-- 3. Final Net Profit -->
        <div class="col-md-4">
            <div class="executive-card <?= $isProfitable ? 'success' : 'danger' ?>" style="background: <?= $isProfitable ? '#f0fdf4' : '#fef2f2' ?>;">
                <div class="small fw-bold mb-1 <?= $isProfitable ? 'text-success' : 'text-danger' ?>">
                    <?= $isProfitable ? 'صافي الربح الفعلي النهائي' : 'صافي الخسارة التشغيلية' ?>
                </div>
                <div class="fs-3 fw-bold num <?= $isProfitable ? 'text-success' : 'text-danger' ?> mb-1">
                    <?= money($finalNetProfit) ?>
                </div>
                <div class="small text-muted">
                    المبلغ الصافي المتبقي لصاحب المتجر بعد كل المصاريف
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1: Statement of Financial Performance (قائمة الدخل والأرباح) -->
    <div class="mb-4">
        <div class="report-section-title">
            <span>بيان ملخص الأرباح والمصروفات (قائمة الدخل)</span>
            <span class="badge bg-light text-dark border small fw-normal">ملخص تنفيذي</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered statement-table mb-0">
                <tbody>
                    <tr>
                        <td class="fw-bold" style="width: 60%;">إجمالي المبيعات الصادرة (Gross Revenue)</td>
                        <td class="text-end num fw-bold"><?= money($grossSalesBeforeReturn) ?></td>
                        <td class="text-muted small" style="width: 25%;">إجمالي قيمة المبيعات المسجلة في النظام</td>
                    </tr>
                    <?php if ($totalReturnedAmount > 0): ?>
                    <tr class="text-danger">
                        <td>يخصم: المرتجعات والمبالغ المستردة للعملاء</td>
                        <td class="text-end num">-<?= money($totalReturnedAmount) ?></td>
                        <td class="small">عدد <?= $returnedCount ?> فاتورة مرتجعة</td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #f8fafc;">
                        <td class="fw-bold text-dark">صافي إيرادات المبيعات الفعلية (Net Revenue)</td>
                        <td class="text-end num fw-bold text-dark fs-6"><?= money($netRevenue) ?></td>
                        <td class="text-muted small">المبالغ الفعلية المحصلة من العملاء</td>
                    </tr>
                    <tr class="text-danger">
                        <td class="fw-bold">يخصم: إجمالي المصاريف التشغيلية (Operating Expenses)</td>
                        <td class="text-end num fw-bold">-<?= money($totalExpenses) ?></td>
                        <td class="small">رواتب، إيجار، طعام، باكدجات، خدمات</td>
                    </tr>
                    <tr style="background: <?= $isProfitable ? '#f0fdf4' : '#fef2f2' ?>; border-top: 2px solid #0f172a;">
                        <td class="fw-bold fs-6 <?= $isProfitable ? 'text-success' : 'text-danger' ?>">
                            صافي الربح / العائد النهائي لصاحب المتجر (Net Profit)
                        </td>
                        <td class="text-end num fw-bold fs-5 <?= $isProfitable ? 'text-success' : 'text-danger' ?>">
                            <?= money($finalNetProfit) ?>
                        </td>
                        <td class="small fw-semibold <?= $isProfitable ? 'text-success' : 'text-danger' ?>">
                            <?= $isProfitable ? 'نشاط رابح ومجدٍ مالياً' : 'تنبيه: المصاريف تتجاوز المبيعات' ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 2: Detailed Expenses (سجل المصاريف التشغيلية التفصيلي) -->
    <div class="mb-4">
        <div class="report-section-title">
            <span>تفاصيل بنود المصاريف التشغيلية المسجلة</span>
            <span class="text-danger fw-bold num fs-6">الإجمالي: <?= money($totalExpenses) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered statement-table mb-0">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">#</th>
                        <th style="width: 110px;">التاريخ</th>
                        <th style="width: 170px;">التصنيف</th>
                        <th>بيان المصروف</th>
                        <th style="width: 110px;">طريقة الدفع</th>
                        <th style="width: 130px;">رقم السند/الإيصال</th>
                        <th style="width: 130px;" class="text-end">المبلغ (AED)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-3">لا توجد مصاريف مسجلة خلال الفترة المحددة.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $idx => $e): ?>
                        <tr>
                            <td class="text-center num text-muted"><?= $idx + 1 ?></td>
                            <td class="num"><?= $e['expense_date'] ?></td>
                            <td class="fw-semibold text-dark"><?= $categoryTitles[$e['category']] ?? 'أخرى' ?></td>
                            <td>
                                <div><?= e($e['title']) ?></div>
                                <?php if ($e['notes']): ?>
                                    <small class="text-muted d-block"><?= e($e['notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= $e['payment_method'] === 'cash' ? 'نقد (كاش)' : ($e['payment_method'] === 'card' ? 'بطاقة بنكية' : 'تحويل بنكي') ?>
                                </span>
                            </td>
                            <td class="font-monospace small text-muted"><?= e($e['receipt_ref'] ?: '—') ?></td>
                            <td class="text-end num fw-bold text-danger"><?= money($e['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td colspan="6" class="text-start">إجمالي المصاريف التشغيلية:</td>
                        <td class="text-end num text-danger fs-6"><?= money($totalExpenses) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Section 3: Sales Invoices Summary (تفاصيل المبيعات والفواتير) -->
    <div class="mb-4">
        <div class="report-section-title">
            <span>سجل فواتير المبيعات الصادرة</span>
            <span class="text-dark fw-bold num fs-6">الإجمالي: <?= money($netRevenue) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered statement-table mb-0">
                <thead>
                    <tr>
                        <th style="width: 120px;">رقم الفاتورة</th>
                        <th style="width: 140px;">التاريخ والوقت</th>
                        <th>الكاشير المسؤول</th>
                        <th style="width: 120px;">طريقة الدفع</th>
                        <th style="width: 100px;">الحالة</th>
                        <th style="width: 110px;" class="text-end">المجموع</th>
                        <th style="width: 90px;" class="text-end">الخصم</th>
                        <th style="width: 130px;" class="text-end">الصافي (AED)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-3">لا توجد فواتير مبيعات مسجلة في هذه الفترة.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $s):
                            $statusBadge = match ($s['status']) {
                                'completed'      => '<span class="badge bg-success text-white">مكتملة</span>',
                                'partial_return' => '<span class="badge bg-warning text-dark">مرتجع جزئي</span>',
                                'returned'       => '<span class="badge bg-danger text-white">مرتجع بالكامل</span>',
                                default          => e($s['status']),
                            };
                            $rowClass = match ($s['status']) {
                                'returned'       => 'table-danger bg-opacity-10',
                                'partial_return' => 'table-warning bg-opacity-10',
                                default          => '',
                            };
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="num fw-bold font-monospace">#<?= e($s['invoice_number']) ?></td>
                            <td class="num text-muted"><?= date('Y-m-d H:i', strtotime($s['created_at'])) ?></td>
                            <td><?= e($s['cashier_name'] ?: 'المدير') ?></td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= strtoupper($s['payment_method']) ?></span>
                            </td>
                            <td><?= $statusBadge ?></td>
                            <td class="num text-end <?= $s['status'] === 'returned' ? 'text-decoration-line-through text-muted' : '' ?>"><?= money($s['subtotal']) ?></td>
                            <td class="num text-end text-muted"><?= $s['discount'] > 0 ? '-' . money($s['discount']) : '—' ?></td>
                            <td class="num text-end fw-bold">
                                <?php if ($s['returned_amount'] > 0): ?>
                                    <span class="text-dark"><?= money($s['net_amount']) ?></span>
                                    <div class="small text-danger fw-normal">(اترجع منها <?= money($s['returned_amount']) ?>)</div>
                                <?php else: ?>
                                    <span class="text-dark"><?= money($s['total']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td colspan="7" class="text-start">صافي المبيعات المحصلة الفعلية:</td>
                        <td class="text-end num text-dark fs-6"><?= money($netRevenue) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Official Sign-off & Signatures (Clean Corporate Footer) -->
    <div class="row g-4 pt-4 mt-2 border-top text-center text-muted small" style="border-color: #e2e8f0 !important;">
        <div class="col-4">
            <div class="fw-bold text-dark mb-4">إعداد المحاسب المالي</div>
            <div class="border-bottom mx-auto mb-1" style="width: 140px; border-color: #94a3b8 !important;"></div>
            <div>التوقيع والتاريخ</div>
        </div>
        <div class="col-4">
            <div class="fw-bold text-dark mb-4">اعتماد المدير العام</div>
            <div class="border-bottom mx-auto mb-1" style="width: 140px; border-color: #94a3b8 !important;"></div>
            <div>الختم والاعتماد</div>
        </div>
        <div class="col-4">
            <div class="fw-bold text-dark mb-4">الختم الرسمي للمؤسسة</div>
            <div class="border border-dashed rounded-circle d-inline-flex align-items-center justify-content-center mx-auto" style="width: 70px; height: 70px; border-color: #cbd5e1;">
                <span style="font-size: 0.65rem; color: #94a3b8;"><?= APP_NAME ?></span>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadReportPDF() {
    const element = document.getElementById('reportPrintArea');
    const opt = {
        margin: [10, 8, 10, 8],
        filename: 'التقرير_المالي_<?= APP_NAME ?>_' + '<?= $dateFrom ?>_إلى_<?= $dateTo ?>' + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
