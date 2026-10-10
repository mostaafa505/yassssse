<?php
$pageTitle = 'لوحة التحكم / Dashboard';
require_once dirname(__DIR__) . '/includes/header.php';

requireAuth();

$userRole = $_SESSION['user_role'] ?? 'cashier';
$period = $_GET['period'] ?? 'today';

// أدوار مختلفة تشوف مستويات مختلفة من البيانات — الكاشير ميشوفش إجمالي مبيعات
// المتجر كله ولا مبيعات باقي الكاشيرات، ومسؤول المخزن ميشوفش أي أرقام مالية،
// طبقًا لصلاحيات كل دور المحددة في متطلبات النظام.
$canSeeStoreFinancials = in_array($userRole, ['admin', 'accountant']);
$canSeeInventoryAlerts = in_array($userRole, ['admin', 'accountant', 'stock']);
$canSeeAllInvoices     = in_array($userRole, ['admin', 'accountant']);

$dateCondition = "DATE(s.created_at) = CURDATE()";
if ($period === 'yesterday') {
    $dateCondition = "DATE(s.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($period === 'week') {
    $dateCondition = "YEARWEEK(s.created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period === 'month') {
    $dateCondition = "MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE())";
} elseif ($period === 'all') {
    $dateCondition = "1=1";
}

// لو كاشير: كل أرقام المبيعات المعروضة تبقى مقصورة على فواتيره هو بس
$ownerFilter = '';
$ownerParams = [];
if (!$canSeeAllInvoices && $userRole === 'cashier') {
    $ownerFilter = " AND s.cashier_id = ?";
    $ownerParams[] = $_SESSION['user_id'];
}

// 1. Sales KPIs
// returned_amount بيتحسب من جدول returns الفعلي مش من status الفاتورة —
// عشان فاتورة حصلها مرتجع جزئي (زي بيع بـ290 اترجع منه 40 ثم 75) تفضل
// مساهمة بصافيها الحقيقي (175) في الإيرادات، مش تتستبعد بالكامل ولا تتحسب
// وكأنها لسه كاملة. status = 'completed' القديم كان بيستبعد partial_return
// من كل الحسابات دي تمامًا (لا إيراد ولا مرتجع)، فالأرقام كانت غلط.
$stmtSales = $pdo->prepare("
    SELECT
        COUNT(*) as total_invoices,
        COALESCE(SUM(s.total), 0) as gross_sales,
        COALESCE(SUM((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id)), 0) as total_returns,
        COUNT(CASE WHEN (SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id) > 0 THEN 1 END) as return_invoices,
        COALESCE(SUM(s.cash_amount), 0) as total_cash,
        COALESCE(SUM(s.card_amount), 0) as total_card,
        COALESCE(SUM(s.transfer_amount), 0) as total_transfer,
        COALESCE(SUM(s.discount), 0) as total_discounts
    FROM sales s
    WHERE $dateCondition $ownerFilter
");
$stmtSales->execute($ownerParams);
$salesKpi = $stmtSales->fetch();
$salesKpi['total_sales'] = max(0, floatval($salesKpi['gross_sales']) - floatval($salesKpi['total_returns']));

// صافي كل طريقة دفع = اللي اتقبض بيها ناقص أي مبلغ اترد بنفس الطريقة —
// المرتجعات القديمة (قبل F-08) أو اللي مالهاش طريقة رد محددة بتتجاهل هنا
// (تفضل within total_returns في الإجمالي العام فوق، بس مش متخصومة من كارت
// معين لأننا مش عارفين اتردت إزاي فعليًا)
$stmtRefundMethod = $pdo->prepare("
    SELECT r.refund_method, COALESCE(SUM(r.total), 0) as amt
    FROM returns r
    JOIN sales s ON r.sale_id = s.id
    WHERE $dateCondition $ownerFilter
    GROUP BY r.refund_method
");
$stmtRefundMethod->execute($ownerParams);
$refundsByMethod = ['cash' => 0, 'card' => 0, 'transfer' => 0];
foreach ($stmtRefundMethod->fetchAll() as $row) {
    if (in_array($row['refund_method'], ['cash', 'card', 'transfer'])) {
        $refundsByMethod[$row['refund_method']] += floatval($row['amt']);
    }
}
$salesKpi['total_cash'] = floatval($salesKpi['total_cash']) - $refundsByMethod['cash'];
$salesKpi['total_card'] = floatval($salesKpi['total_card']) - $refundsByMethod['card'];
$salesKpi['total_transfer'] = floatval($salesKpi['total_transfer']) - $refundsByMethod['transfer'];

// 2. Items sold — صافي الكمية (المباعة ناقص أي كمية اترجعت من نفس البند)،
// محسوبة لكل الفواتير بغض النظر عن حالتها عشان بند لسه مع العميل من فاتورة
// حصلها مرتجع جزئي يفضل محسوب
$stmtItems = $pdo->prepare("
    SELECT COALESCE(SUM(
        si.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)
    ), 0) as items_sold
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE $dateCondition $ownerFilter
");
$stmtItems->execute($ownerParams);
$itemsSold = $stmtItems->fetchColumn();

// 3. Low stock products count (Admin/Accountant/Stock only)
$lowStockCount = 0;
if ($canSeeInventoryAlerts) {
    $lowStockCount = $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT id, stock_qty, min_stock_alert FROM products WHERE has_variants = 0 AND stock_qty <= min_stock_alert AND is_active = 1
            UNION ALL
            SELECT pv.id, pv.stock_qty, p.min_stock_alert FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE p.is_active = 1 AND pv.stock_qty <= p.min_stock_alert
        ) as low_stock_items
    ")->fetchColumn();
}

// 4. Cashier Performance (admin/accountant only — بيانات كل الكاشيرات)
$cashiersPerf = [];
if ($canSeeStoreFinancials) {
    $stmtCash = $pdo->query("
        SELECT u.name_ar,
            COUNT(s.id) as count_invoices,
            COALESCE(SUM(s.total - COALESCE((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id), 0)), 0) as sum_sales
        FROM users u
        LEFT JOIN sales s ON s.cashier_id = u.id AND $dateCondition
        WHERE u.role IN ('cashier', 'admin')
        GROUP BY u.id
        ORDER BY sum_sales DESC
    ");
    $cashiersPerf = $stmtCash->fetchAll();
}

// 5. Recent Invoices — الكاشير يشوف فواتيره بس، الباقي يشوف كل الفواتير
$recentSalesStmt = $pdo->prepare("
    SELECT s.*, u.name_ar as cashier_name
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE 1=1 $ownerFilter
    ORDER BY s.id DESC
    LIMIT 6
");
$recentSalesStmt->execute($ownerParams);
$recentSales = $recentSalesStmt->fetchAll();
?>

    <!-- Main Content Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="title-large mb-1"><?= __('welcome') ?>، <?= e($lang === 'en' ? $userNameEn : $userNameAr) ?></h1>
            <p class="text-subtle mb-0"><?= __('overview_subtitle') ?></p>
        </div>
        <?php if ($canSeeStoreFinancials || $userRole === 'cashier'): ?>
        <div class="btn-group">
            <a href="?period=today" class="btn <?= $period === 'today' ? 'btn-dark' : 'btn-outline-secondary' ?>"><?= __('today') ?></a>
            <a href="?period=yesterday" class="btn <?= $period === 'yesterday' ? 'btn-dark' : 'btn-outline-secondary' ?>"><?= __('yesterday') ?></a>
            <a href="?period=week" class="btn <?= $period === 'week' ? 'btn-dark' : 'btn-outline-secondary' ?>"><?= __('this_week') ?></a>
            <a href="?period=month" class="btn <?= $period === 'month' ? 'btn-dark' : 'btn-outline-secondary' ?>"><?= __('this_month') ?></a>
            <a href="?period=all" class="btn <?= $period === 'all' ? 'btn-dark' : 'btn-outline-secondary' ?>"><?= __('all') ?></a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Shortcut for Cashier / Stock roles -->
    <?php if (!$canSeeStoreFinancials): ?>
    <div class="row g-3 mb-4">
        <?php if (in_array($userRole, ['admin', 'cashier'])): ?>
        <div class="col-md-6">
            <a href="<?= BASE_URL ?>/pos/" class="panel p-4 hover-lift h-100 text-white d-flex align-items-center gap-3 text-decoration-none" style="background: var(--brand-primary); border: none;">
                <i class="bi bi-calculator fs-2"></i>
                <div>
                    <strong class="d-block fs-5">نقطة البيع</strong>
                    <small style="opacity: 0.85;">ابدأ عملية بيع جديدة</small>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if ($userRole === 'stock'): ?>
        <div class="col-md-6">
            <a href="<?= BASE_URL ?>/inventory/" class="panel p-4 hover-lift h-100 text-white d-flex align-items-center gap-3 text-decoration-none" style="background: var(--brand-primary); border: none;">
                <i class="bi bi-boxes fs-2"></i>
                <div>
                    <strong class="d-block fs-5">إدارة المخزون</strong>
                    <small style="opacity: 0.85;">استلام، جرد، وتحويل بضاعة</small>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- KPI Cards Row 1 -->
    <div class="row g-4 mb-4">
        <?php if ($canSeeStoreFinancials): ?>
        <!-- Primary KPI: Total Sales (Net) — المدير والمحاسب بس -->
        <div class="col-sm-6 col-xl-3">
            <a href="<?= BASE_URL ?>/reports/" class="panel p-4 hover-lift h-100 text-white d-flex flex-column justify-content-center text-decoration-none" style="background: var(--brand-primary); border: none; transition: transform 0.2s, box-shadow 0.2s;">
                <span class="text-caption d-block mb-2 text-white" style="opacity: 0.9;">صافي المبيعات المحصلة</span>
                <div class="d-flex align-items-end gap-2">
                    <h2 class="num fw-bold mb-0 text-white" style="font-size: 2rem; letter-spacing: -0.5px;"><?= money($salesKpi['total_sales']) ?></h2>
                </div>
                <?php if ($salesKpi['total_returns'] > 0): ?>
                    <small class="text-white-50 d-block mt-1" style="font-size: 0.75rem;"><i class="bi bi-arrow-counterclockwise"></i> مخصوم منه <?= money($salesKpi['total_returns']) ?> مرتجعات</small>
                <?php endif; ?>
            </a>
        </div>
        <?php elseif ($userRole === 'cashier'): ?>
        <!-- الكاشير يشوف مبيعاته الشخصية بس، مش إجمالي المتجر -->
        <div class="col-sm-6 col-xl-3">
            <div class="panel p-4 h-100 text-white d-flex flex-column justify-content-center" style="background: var(--brand-primary); border: none;">
                <span class="text-caption d-block mb-2 text-white" style="opacity: 0.9;">مبيعاتي (الفترة المحددة)</span>
                <h2 class="num fw-bold mb-0 text-white" style="font-size: 2rem; letter-spacing: -0.5px;"><?= money($salesKpi['total_sales']) ?></h2>
            </div>
        </div>
        <?php endif; ?>

        <!-- Secondary KPIs -->
        <?php if ($canSeeAllInvoices || $userRole === 'cashier'): ?>
        <div class="col-sm-6 col-xl-3">
            <a href="<?= BASE_URL ?>/sales/" class="panel p-4 hover-lift h-100 d-flex align-items-center gap-3 text-decoration-none text-dark" style="transition: transform 0.2s, box-shadow 0.2s;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: var(--intent-success-bg); color: var(--intent-success);">
                    <i class="bi bi-receipt fs-5"></i>
                </div>
                <div>
                    <span class="text-caption d-block mb-1 text-subtle"><?= __('completed_invoices') ?></span>
                    <div class="d-flex align-items-baseline gap-1">
                        <h4 class="title-large num mb-0"><?= (int)$salesKpi['total_invoices'] ?></h4>
                        <?php if ($salesKpi['return_invoices'] > 0): ?>
                            <small class="text-danger ms-1" style="font-size: 0.75rem;">(<?= (int)$salesKpi['return_invoices'] ?> مرتجع)</small>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="panel p-4 h-100 d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">
                    <i class="bi bi-box-seam fs-5"></i>
                </div>
                <div>
                    <span class="text-caption d-block mb-1 text-subtle"><?= __('items_sold') ?></span>
                    <div class="d-flex align-items-baseline gap-1">
                        <h4 class="title-large num mb-0"><?= (int)$itemsSold ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canSeeInventoryAlerts): ?>
        <div class="col-sm-6 col-xl-3">
            <a href="<?= BASE_URL ?>/inventory/" class="panel p-4 hover-lift h-100 d-flex align-items-center gap-3 text-decoration-none text-dark" style="transition: transform 0.2s, box-shadow 0.2s;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: var(--intent-danger-bg); color: var(--intent-danger);">
                    <i class="bi bi-exclamation-triangle fs-5"></i>
                </div>
                <div>
                    <span class="text-caption d-block mb-1 text-danger"><?= __('stock_alerts') ?></span>
                    <div class="d-flex align-items-baseline gap-1">
                        <h4 class="title-large num mb-0 text-danger"><?= (int)$lowStockCount ?></h4>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($canSeeStoreFinancials): ?>
    <!-- Payment Breakdown & Special Sales (Admin/Accountant only) -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <a href="<?= BASE_URL ?>/reports/" class="panel p-4 hover-lift d-flex align-items-center gap-4 text-decoration-none text-dark" style="transition: transform 0.2s, box-shadow 0.2s;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: var(--intent-success-bg); color: var(--intent-success);">
                    <i class="bi bi-cash fs-5"></i>
                </div>
                <div>
                    <span class="text-caption d-block text-subtle"><?= __('cash_sales') ?></span>
                    <h5 class="title-medium num mb-0"><?= money($salesKpi['total_cash']) ?></h5>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= BASE_URL ?>/reports/" class="panel p-4 hover-lift d-flex align-items-center gap-4 text-decoration-none text-dark" style="transition: transform 0.2s, box-shadow 0.2s;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: #EEF2FF; color: #4F46E5;">
                    <i class="bi bi-credit-card fs-5"></i>
                </div>
                <div>
                    <span class="text-caption d-block text-subtle"><?= __('card_sales') ?></span>
                    <h5 class="title-medium num mb-0"><?= money($salesKpi['total_card']) ?></h5>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= BASE_URL ?>/reports/" class="panel p-4 hover-lift d-flex align-items-center gap-4 text-decoration-none text-dark" style="transition: transform 0.2s, box-shadow 0.2s;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: #F0FDF4; color: #16A34A;">
                    <i class="bi bi-bank fs-5"></i>
                </div>
                <div>
                    <span class="text-caption d-block text-subtle"><?= __('transfer_sales') ?></span>
                    <h5 class="title-medium num mb-0"><?= money($salesKpi['total_transfer']) ?></h5>
                </div>
            </a>
        </div>
    </div>

    <!-- Main Fund (Treasury) Balance -->
    <?php
        $treasuryBalance = getTreasuryCashBalance($pdo);
        $treasuryIsShortage = $treasuryBalance < 0;
    ?>
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <a href="<?= BASE_URL ?>/treasury/" class="panel p-4 hover-lift d-flex align-items-center gap-4 text-decoration-none text-dark" style="transition: transform 0.2s, box-shadow 0.2s;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: <?= $treasuryIsShortage ? 'var(--intent-danger-bg, #FEF2F2)' : 'var(--intent-success-bg)' ?>; color: <?= $treasuryIsShortage ? '#DC2626' : 'var(--intent-success)' ?>;">
                    <i class="bi bi-safe2 fs-5"></i>
                </div>
                <div>
                    <span class="text-caption d-block text-subtle">رصيد الصندوق الرئيسي<?= $treasuryIsShortage ? ' — ⚠️ عجز' : '' ?></span>
                    <h5 class="title-medium num mb-0" style="<?= $treasuryIsShortage ? 'color:#DC2626;' : '' ?>"><?= money($treasuryBalance) ?></h5>
                </div>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canSeeAllInvoices || $userRole === 'cashier'): ?>
    <!-- Bottom Section: Recent Sales & Cashier Performance -->
    <div class="row g-4">
        <!-- Recent Sales -->
        <div class="col-lg-7">
            <div class="panel h-100">
                <div class="panel-header border-bottom border-light">
                    <h6 class="panel-title mb-0"><?= $canSeeAllInvoices ? __('recent_invoices') : 'آخر فواتيري' ?></h6>
                    <a href="<?= BASE_URL ?>/sales/" class="btn btn-light btn-sm"><?= __('view_all') ?></a>
                </div>
                <div class="table-wrapper">
                    <div class="table-responsive border-0">
<table class="table table-hover mb-0">
                        <thead style="background: var(--surface-default);">
                            <tr>
                                <th class="text-caption text-subtle border-bottom-0"><?= __('invoice_no') ?></th>
                                <th class="text-caption text-subtle border-bottom-0"><?= __('time') ?></th>
                                <?php if ($canSeeAllInvoices): ?><th class="text-caption text-subtle border-bottom-0"><?= __('cashier') ?></th><?php endif; ?>
                                <th class="text-caption text-subtle border-bottom-0"><?= __('payment_method') ?></th>
                                <th class="text-end text-caption text-subtle border-bottom-0"><?= __('total') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSales)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-subtle">No invoices found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentSales as $rs): ?>
                                    <tr>
                                        <td><span dir="ltr" class="num text-primary fw-bold">#<?= e($rs['invoice_number']) ?></span></td>
                                        <td class="text-subtle num"><?= date('h:i A', strtotime($rs['created_at'])) ?></td>
                                        <?php if ($canSeeAllInvoices): ?><td><?= e($rs['cashier_name'] ?: 'Admin') ?></td><?php endif; ?>
                                        <td>
                                            <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">
                                                <?= e($rs['payment_method']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end num fw-bold"><?= money($rs['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
</div>
                </div>
            </div>
        </div>

        <!-- Cashiers Performance (Admin/Accountant only) -->
        <?php if (!empty($cashiersPerf)): ?>
        <div class="col-lg-5">
            <div class="panel h-100">
                <div class="panel-header border-bottom border-light">
                    <h6 class="panel-title mb-0"><?= __('staff_performance') ?></h6>
                </div>
                <div class="table-wrapper">
                    <div class="table-responsive border-0">
<table class="table table-hover mb-0">
                        <thead style="background: var(--surface-default);">
                            <tr>
                                <th class="text-caption text-subtle border-bottom-0"><?= __('employee') ?></th>
                                <th class="text-center text-caption text-subtle border-bottom-0"><?= __('invoices') ?></th>
                                <th class="text-end text-caption text-subtle border-bottom-0"><?= __('sales') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cashiersPerf as $cp): ?>
                                <tr>
                                    <td class="fw-medium"><?= e($lang === 'en' && !empty($cp['name_en']) ? $cp['name_en'] : $cp['name_ar']) ?></td>
                                    <td class="text-center num">
                                        <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">
                                            <?= (int)$cp['count_invoices'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end num fw-bold text-success"><?= money($cp['sum_sales']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
