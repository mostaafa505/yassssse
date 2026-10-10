<?php
$pageTitle = 'أرباح ومصاريف المعرض / Exhibition Profit & Expenses';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'accountant'], 'manage_exhibitions');

// Handle Quick Exhibition Creation directly on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_exhibition' && verifyCsrf()) {
    $title = trim($_POST['title'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate = $_POST['end_date'] ?? date('Y-m-d', strtotime('+9 days'));
    $budget = floatval($_POST['budget'] ?? 0);

    if ($title !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO exhibitions (title, location, start_date, end_date, status, budget, created_by)
            VALUES (?, ?, ?, ?, 'active', ?, ?)
        ");
        $stmt->execute([$title, $location, $startDate, $endDate, $budget, $_SESSION['user_id'] ?? null]);
        $newId = $pdo->lastInsertId();
        redirect("exhibitions/index.php?id=$newId&created=1");
    }
}

// 1. Determine active exhibition ID
$allExhibitions = $pdo->query("SELECT id, title, start_date, end_date, status FROM exhibitions ORDER BY start_date DESC, id DESC")->fetchAll();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0 && !empty($allExhibitions)) {
    // Pick the active one or the most recent
    foreach ($allExhibitions as $eItem) {
        if ($eItem['status'] === 'active') {
            $id = (int)$eItem['id'];
            break;
        }
    }
    if ($id <= 0) {
        $id = (int)$allExhibitions[0]['id'];
    }
}

// Fetch current exhibition
$ex = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM exhibitions WHERE id = ?");
    $stmt->execute([$id]);
    $ex = $stmt->fetch();
}
?>

<?php if (!$ex): ?>
    <!-- ======================================================== -->
    <!-- ZERO COMPLICATIONS: IF NO EXHIBITION EXISTS YET -->
    <!-- ======================================================== -->
    <div class="row justify-content-center my-4">
        <div class="col-lg-7">
            <div class="panel p-5 text-center shadow-sm border-0" style="border-radius: 16px; background: var(--surface-default);">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 bg-primary bg-opacity-10 text-primary" style="width: 72px; height: 72px;">
                    <i class="bi bi-calendar-plus fs-1"></i>
                </div>
                <h3 class="fw-bold mb-2">إضافة وحساب أرباح ومصاريف المعرض</h3>
                <p class="text-subtle mb-4" style="max-width: 500px; margin: 0 auto;">
                    اكتب اسم المعرض وتاريخه لبدء رصد مبيعات المنتجات وإضافة مصاريف الجناح والطعام والعمالة وحساب صافي أرباحك فوراً.
                </p>

                <form method="POST" action="" class="text-start bg-light p-4 rounded-3 border">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_exhibition">
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم المعرض أو الفعالية <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg" placeholder="مثال: معرض دبي الدولي للعطور" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">الموقع أو الجناح (اختياري)</label>
                        <input type="text" name="location" class="form-control" placeholder="مثال: قاعة 3 - جناح B14">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">تاريخ البداية</label>
                            <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">تاريخ النهاية (مثلاً 10 أيام)</label>
                            <input type="date" name="end_date" class="form-control" value="<?= date('Y-m-d', strtotime('+9 days')) ?>" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold py-3 mt-2 shadow-sm">
                        <i class="bi bi-check-circle-fill me-1"></i> ابدأ حساب أرباح ومصاريف هذا المعرض
                    </button>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ======================================================== -->
    <!-- DIRECT LIVE CALCULATOR FOR CURRENT EXHIBITION -->
    <!-- ======================================================== -->
    <?php
    $startDate = $_GET['start_date'] ?? $ex['start_date'];
    $endDate = $_GET['end_date'] ?? $ex['end_date'];
    $daysCount = (int)ceil((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
    if ($daysCount < 1) $daysCount = 1;

    $expenseCategories = [
        'booth_rent'         => ['title' => 'تأجير جناح / مساحة', 'icon' => 'bi-shop', 'color' => '#6366f1'],
        'hospitality_food'   => ['title' => 'طعام وضيافة وإعاشة', 'icon' => 'bi-cup-hot', 'color' => '#f59e0b'],
        'staff_wages'        => ['title' => 'رواتب وبدلات موظفين', 'icon' => 'bi-people', 'color' => '#0ea5e9'],
        'packaging_supplies' => ['title' => 'باكدجات وأكياس وتغليف', 'icon' => 'bi-box-seam', 'color' => '#10b981'],
        'shipping_transport' => ['title' => 'نقل وشحن وديكور', 'icon' => 'bi-truck', 'color' => '#8b5cf6'],
        'marketing_ads'      => ['title' => 'تسويق وإعلانات المعرض', 'icon' => 'bi-megaphone', 'color' => '#ef4444'],
        'other'              => ['title' => 'مصاريف أخرى ونثرية', 'icon' => 'bi-three-dots', 'color' => '#64748b'],
    ];

    // 1. Fetch Expenses
    $stmtExp = $pdo->prepare("
        SELECT ee.*, u.name_ar as user_name
        FROM exhibition_expenses ee
        LEFT JOIN users u ON ee.created_by = u.id
        WHERE ee.exhibition_id = ?
        ORDER BY ee.id DESC
    ");
    $stmtExp->execute([$id]);
    $expensesList = $stmtExp->fetchAll();

    $totalExpenses = 0;
    foreach ($expensesList as $exp) {
        $totalExpenses += (float)$exp['amount'];
    }

    // 2. Fetch Sold Items (كافة المنتجات والأشياء المباعة)
    // بنحسب الكمية والمبلغ الصافي لكل بند (المباع ناقص أي كمية/مبلغ اترجع
    // منه فعليًا) بدل ما نستبعد الفاتورة كلها لو حصلها مرتجع جزئي — قبل كده
    // فاتورة اترجع منها صنف واحد بس كانت بتخرج بالكامل من إحصائيات المعرض
    $stmtProds = $pdo->prepare("
        SELECT
            si.product_id,
            si.variant_id,
            si.product_name_ar,
            si.color_ar,
            si.size,
            COALESCE(si.cost_price, pv.cost_price, p.cost_price, 0) as unit_cost,
            AVG(si.unit_price) as avg_unit_price,
            SUM(si.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) as total_qty_sold,
            SUM(si.total - COALESCE((SELECT SUM(ri.total) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) as total_sales_amount,
            SUM((si.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price, pv.cost_price, p.cost_price, 0)) as total_cost
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        LEFT JOIN products p ON si.product_id = p.id
        LEFT JOIN product_variants pv ON si.variant_id = pv.id
        WHERE (s.exhibition_id = ? OR (s.exhibition_id IS NULL AND DATE(s.created_at) BETWEEN ? AND ?))
        GROUP BY si.product_id, si.variant_id, si.product_name_ar, si.color_ar, si.size, unit_cost
        HAVING total_qty_sold > 0
        ORDER BY total_sales_amount DESC
    ");
    $stmtProds->execute([$id, $startDate, $endDate]);
    $soldProducts = $stmtProds->fetchAll();
    foreach ($soldProducts as &$sp) {
        $sp['product_profit'] = (float)$sp['total_sales_amount'] - (float)$sp['total_cost'];
    }
    unset($sp);

    // 3. Sales & Returns Totals — نجمع كل الفواتير مهما كانت حالتها، والصافي
    // بيتحسب بطرح المرتجع الفعلي (من جدول returns) لكل فاتورة، مش باستبعاد
    // فواتير المرتجع الجزئي بالكامل من "المبيعات"
    $stmtSales = $pdo->prepare("
        SELECT
            COALESCE(SUM(total), 0) as gross_sales,
            COALESCE(SUM((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = sales.id)), 0) as returned_sales,
            COUNT(*) as invoices_count,
            COUNT(CASE WHEN (SELECT SUM(r.total) FROM returns r WHERE r.sale_id = sales.id) > 0 THEN 1 END) as returned_invoices_count
        FROM sales
        WHERE (exhibition_id = ? OR (exhibition_id IS NULL AND DATE(created_at) BETWEEN ? AND ?))
    ");
    $stmtSales->execute([$id, $startDate, $endDate]);
    $salesData = $stmtSales->fetch();

    $totalReturns = (float)$salesData['returned_sales'];
    $totalProductsSales = max(0, (float)$salesData['gross_sales'] - $totalReturns); // صافي
    $invoicesCount = (int)$salesData['invoices_count'];

    $totalCogs = 0;
    $totalQtySold = 0;
    foreach ($soldProducts as $sp) {
        $totalCogs += (float)$sp['total_cost'];
        $totalQtySold += (float)$sp['total_qty_sold'];
    }

    // 4. Final Math
    $cashProfitAfterExpenses = $totalProductsSales - $totalExpenses;
    $netRealProfit = ($totalProductsSales - $totalCogs) - $totalExpenses;
    $isProfitable = $netRealProfit > 0;
    $isLoss = $netRealProfit < 0;
    ?>

    <!-- Top Bar: Switch Exhibition + Quick Add Exhibition Button -->
    <div class="panel p-3 mb-4 no-print" style="background: var(--surface-default); border-radius: 12px;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <!-- Left: Current Exhibition Title & Selector -->
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="dropdown">
                    <button class="btn btn-dark dropdown-toggle fw-bold px-3 py-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-calendar-event me-1"></i> <?= e($ex['title']) ?>
                    </button>
                    <ul class="dropdown-menu shadow border-0" style="border-radius: 10px;">
                        <li class="dropdown-header">اختر معرضاً آخر:</li>
                        <?php foreach ($allExhibitions as $ae): ?>
                            <li>
                                <a class="dropdown-item <?= $ae['id'] == $id ? 'active' : '' ?>" href="<?= BASE_URL ?>/exhibitions/index.php?id=<?= $ae['id'] ?>">
                                    <?= e($ae['title']) ?> (<?= $ae['start_date'] ?>)
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-primary fw-bold" href="javascript:void(0)" onclick="toggleNewExhibitionForm()">
                                <i class="bi bi-plus-circle me-1"></i> فتح معرض جديد
                            </a>
                        </li>
                    </ul>
                </div>

                <span class="badge bg-light text-dark border px-2 py-2">
                    <i class="bi bi-geo-alt text-primary"></i> <?= e($ex['location'] ?: 'الجناح غير محدد') ?>
                </span>
                <span class="badge bg-light text-dark border px-2 py-2">
                    <i class="bi bi-clock-history text-secondary"></i> المدة: <?= $daysCount ?> أيام (<?= $startDate ?> إلى <?= $endDate ?>)
                </span>
            </div>

            <!-- Right: Action Buttons -->
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-outline-primary fw-bold" onclick="toggleNewExhibitionForm()">
                    <i class="bi bi-plus-lg"></i> معرض جديد
                </button>
                <a href="<?= BASE_URL ?>/exhibitions/print.php?id=<?= $id ?>" target="_blank" class="btn btn-primary fw-bold">
                    <i class="bi bi-printer me-1"></i> طباعة ريبورت الأرباح (PDF)
                </a>
                <a href="<?= BASE_URL ?>/exhibitions/export.php?id=<?= $id ?>" class="btn btn-outline-success fw-bold">
                    <i class="bi bi-file-earmark-excel me-1"></i> تصدير إكسل
                </a>
            </div>
        </div>

        <!-- Collapsible Inline Form to Add New Exhibition -->
        <div id="newExhibitionBox" class="mt-3 pt-3 border-top" style="display: none;">
            <div class="p-3 bg-light rounded-3 border">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-plus-circle me-1"></i> فتح وحساب معرض جديد فوراً:</h6>
                    <button type="button" class="btn-close" onclick="toggleNewExhibitionForm()"></button>
                </div>
                <form method="POST" action="" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_exhibition">
                    <div class="col-md-4">
                        <label class="form-label small mb-1 fw-bold">اسم المعرض <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-sm" placeholder="مثال: معرض الخريف 2026" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1 fw-bold">الموقع / الجناح</label>
                        <input type="text" name="location" class="form-control form-control-sm" placeholder="مثال: قاعة 2">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small mb-1 fw-bold">من تاريخ</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label small mb-1 fw-bold">إلى تاريخ</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+9 days')) ?>">
                    </div>
                    <div class="col-md-1 col-12">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold py-1">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Print-Only Header -->
    <div class="d-none d-print-block mb-4 pb-3 border-bottom text-center">
        <h2 class="fw-bold mb-1"><?= APP_NAME ?> <?= APP_NAME_EN ?> — ريبورت أرباح وخسائر المعرض</h2>
        <h4><?= e($ex['title']) ?> (<?= e($ex['location']) ?>)</h4>
        <p class="mb-0">فترة المعرض: من <?= $startDate ?> إلى <?= $endDate ?> (<?= $daysCount ?> أيام) | تاريخ الطباعة: <?= date('Y-m-d H:i') ?></p>
    </div>

    <!-- ======================================================== -->
    <!-- 1. LIVE MASTER PROFIT BAR (الحسبة النهائية المباشرة) -->
    <!-- ======================================================== -->
    <div class="panel p-4 mb-4" style="background: <?= $isProfitable ? '#f0fdf4' : ($isLoss ? '#fef2f2' : '#f8fafc') ?>; border: 2px solid <?= $isProfitable ? '#86efac' : ($isLoss ? '#fca5a5' : '#cbd5e1') ?>; border-radius: 14px;">
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="fs-4"><?= $isProfitable ? '<i class="bi bi-graph-up-arrow text-success"></i>' : ($isLoss ? '<i class="bi bi-graph-down-arrow text-danger"></i>' : '<i class="bi bi-dash-circle text-secondary"></i>') ?></span>
                <h4 class="fw-bold mb-0 <?= $isProfitable ? 'text-success' : ($isLoss ? 'text-danger' : 'text-dark') ?>">
                    <?= $isProfitable ? 'معرض رابح في النهاية (Profitable)' : ($isLoss ? 'معرض خاسر في النهاية (Net Loss)' : 'نقطة التعادل') ?>
                </h4>
            </div>
            <span class="badge fs-6 <?= $isProfitable ? 'bg-success' : 'bg-danger' ?>">
                صافي الربح النهائي: <?= money($netRealProfit) ?>
            </span>
        </div>

        <!-- Equation: مبيعات - مصاريف = النهاية -->
        <div class="row g-3 text-center align-items-center">
            <!-- 1. Total Products Sold -->
            <div class="col-md-3 col-6">
                <div class="p-3 bg-white rounded-3 shadow-sm border">
                    <span class="text-caption text-subtle d-block mb-1"><i class="bi bi-bag-check me-1 text-primary"></i> إجمالي مبيعات المنتجات</span>
                    <h3 class="num fw-bold text-success mb-0"><?= money($totalProductsSales) ?></h3>
                    <small class="text-subtle">(<?= $totalQtySold ?> قطعة بيعت)</small>
                </div>
            </div>

            <div class="col-1 d-none d-md-block text-center">
                <span class="fs-4 text-muted fw-bold"><i class="bi bi-dash-lg"></i></span>
            </div>

            <!-- 2. Total Expenses -->
            <div class="col-md-3 col-6">
                <div class="p-3 bg-white rounded-3 shadow-sm border">
                    <span class="text-caption text-subtle d-block mb-1"><i class="bi bi-receipt-cutoff me-1 text-danger"></i> إجمالي مصاريف المعرض</span>
                    <h3 class="num fw-bold text-danger mb-0">-<?= money($totalExpenses) ?></h3>
                    <small class="text-danger fw-bold">(<?= count($expensesList) ?> بند صرف)</small>
                </div>
            </div>

            <div class="col-1 d-none d-md-block text-center">
                <span class="fs-4 text-muted fw-bold"><i class="bi bi-dash-lg"></i></span>
            </div>

            <!-- 3. Cost of Goods -->
            <div class="col-md-2 col-6">
                <div class="p-3 bg-white rounded-3 shadow-sm border">
                    <span class="text-caption text-subtle d-block mb-1"><i class="bi bi-box-seam me-1 text-secondary"></i> تكلفة البضاعة</span>
                    <h4 class="num fw-bold text-secondary mb-0">-<?= money($totalCogs) ?></h4>
                    <small class="text-subtle">شراء/إنتاج</small>
                </div>
            </div>

            <div class="col-1 d-none d-md-block text-center">
                <span class="fs-4 text-muted fw-bold"><i class="bi bi-equals"></i></span>
            </div>

            <!-- 4. Final Net Profit -->
            <div class="col-md-12 col-lg-auto flex-grow-1">
                <div class="p-3 rounded-3 text-white shadow" style="background: <?= $isProfitable ? '#15803d' : '#b91c1c' ?>;">
                    <span class="text-caption text-white-50 d-block mb-1"><i class="bi bi-trophy me-1 text-warning"></i> صافي ربحك النهائي (النهاية كام)</span>
                    <h2 class="num fw-bold mb-0 text-white"><?= money($netRealProfit) ?></h2>
                    <small class="text-white-50">
                        كاش متبقي بعد المصاريف: <?= money($cashProfitAfterExpenses) ?>
                    </small>
                </div>
            </div>
        </div>

        <?php if ($totalReturns > 0): ?>
            <div class="alert alert-danger mb-0 mt-3 py-2 px-3 d-flex align-items-center justify-content-between" style="font-size: 0.9rem;">
                <span><i class="bi bi-arrow-counterclockwise me-1"></i> تم خصم فواتير مرتجعة من الإجمالي بقيمة:</span>
                <strong class="num">-<?= money($totalReturns) ?> (<?= (int)$salesData['returned_invoices_count'] ?> مرتجع)</strong>
            </div>
        <?php endif; ?>
    </div>

    <!-- ======================================================== -->
    <!-- 2. QUICK INLINE EXPENSES ADDER (حط المصاريف على طول) -->
    <!-- ======================================================== -->
    <div class="panel mb-4 no-print border-top-4" style="border-top: 4px solid #ef4444;">
        <div class="panel-header p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: #fff5f5;">
            <div>
                <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-plus-circle me-1"></i> إضافة مصاريف المعرض فوراً (تأجير جناح، طعام، عمالة، باكدجات...)</h5>
                <small class="text-subtle">اكتب المصروف ومبلغه وسيتم خصمه فوراً من مبيعات المعرض وتحديث النتيجة النهائية</small>
            </div>
            <span class="badge bg-danger fs-6">إجمالي المصاريف الحالية: <?= money($totalExpenses) ?></span>
        </div>

        <div class="panel-body p-3">
            <!-- Quick Click Chips -->
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="text-caption fw-bold text-subtle">بند سريع:</span>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="quickFillExpense('تأجير جناح المعرض', 'booth_rent')"><i class="bi bi-shop"></i> تأجير جناح</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="quickFillExpense('طعام وضيافة ومشروبات', 'hospitality_food')"><i class="bi bi-cup-hot"></i> طعام وضيافة</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="quickFillExpense('إيجار ورواتب موظفين', 'staff_wages')"><i class="bi bi-people"></i> رواتب موظفين</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="quickFillExpense('باكدجات وأكياس وتغليف', 'packaging_supplies')"><i class="bi bi-box-seam"></i> باكدجات وتغليف</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="quickFillExpense('نقل وشحن وديكور', 'shipping_transport')"><i class="bi bi-truck"></i> شحن ونقل</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="quickFillExpense('إعلانات وتسويق المعرض', 'marketing_ads')"><i class="bi bi-megaphone"></i> تسويق وإعلانات</button>
            </div>

            <!-- Quick Form -->
            <form method="POST" action="<?= BASE_URL ?>/exhibitions/expense_action.php" class="row g-2 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="exhibition_id" value="<?= $id ?>">

                <div class="col-md-3 col-sm-6">
                    <label class="form-label text-caption mb-1">تصنيف المصروف</label>
                    <select name="category" id="exp_category" class="form-select form-select-sm">
                        <?php foreach ($expenseCategories as $ck => $cv): ?>
                            <option value="<?= $ck ?>"><?= $cv['title'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 col-sm-6">
                    <label class="form-label text-caption mb-1">بيان المصروف <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="exp_title" class="form-control form-control-sm" placeholder="مثال: تأجير جناح 500 درهم، طعام 30 درهم..." required>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label text-caption mb-1">المبلغ (درهم) <span class="text-danger">*</span></label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" min="0.01" name="amount" id="exp_amount" class="form-control fw-bold" placeholder="0.00" required>
                        <span class="input-group-text">AED</span>
                    </div>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label text-caption mb-1">تاريخ الصرف</label>
                    <input type="date" name="expense_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label text-caption mb-1">طريقة الدفع</label>
                    <select name="payment_method" class="form-select form-select-sm">
                        <option value="cash">كاش نقدي</option>
                        <option value="card">بطاقة (شبكة)</option>
                        <option value="transfer">تحويل بنكي</option>
                    </select>
                </div>

                <div class="col-md-1 col-12">
                    <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold py-1">
                        <i class="bi bi-plus-lg"></i> إضافة
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- 3. EXPENSES LIST TABLE (جدول المصاريف المسجلة) -->
    <!-- ======================================================== -->
    <div class="panel mb-4">
        <div class="panel-header p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-card-checklist me-1 text-danger"></i> كشف مصاريف المعرض المسجلة (المخصومة)</h5>
            <span class="badge bg-light text-danger border fw-bold"><?= count($expensesList) ?> بنود مصروفة</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 120px;">التاريخ</th>
                        <th>التصنيف</th>
                        <th>بيان وتفاصيل المصروف</th>
                        <th>المسؤول</th>
                        <th style="width: 110px;">طريقة الدفع</th>
                        <th class="text-end" style="width: 160px;">المبلغ المخصوم</th>
                        <th class="text-center no-print" style="width: 80px;">إلغاء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expensesList)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-subtle">
                                لم تقم بتسجيل أي مصاريف حتى الآن. اكتب المصروف في الحقل أعلاه (مثال: تأجير جناح 500، طعام 30...).
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expensesList as $exp): ?>
                            <?php 
                                $cKey = $exp['category'] ?? 'other';
                                $cData = $expenseCategories[$cKey] ?? $expenseCategories['other'];
                            ?>
                            <tr>
                                <td class="num text-subtle"><?= $exp['expense_date'] ?></td>
                                <td>
                                    <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: <?= $cData['color'] ?>;">
                                        <i class="bi <?= $cData['icon'] ?> me-1"></i> <?= $cData['title'] ?>
                                    </span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?= e($exp['title']) ?></strong>
                                </td>
                                <td class="small text-subtle"><?= e($exp['user_name'] ?: 'المدير') ?></td>
                                <td>
                                    <?php
                                        $pmLabels = ['cash' => 'كاش نقدي', 'card' => 'بطاقة', 'transfer' => 'تحويل'];
                                        $pmIcons  = ['cash' => 'bi-cash', 'card' => 'bi-credit-card', 'transfer' => 'bi-bank'];
                                        $pmKey = $exp['payment_method'] ?? 'cash';
                                    ?>
                                    <span class="badge bg-light text-dark border"><i class="bi <?= $pmIcons[$pmKey] ?? 'bi-cash' ?> me-1"></i><?= $pmLabels[$pmKey] ?? 'كاش نقدي' ?></span>
                                </td>
                                <td class="text-end num fw-bold text-danger fs-6">-<?= money($exp['amount']) ?></td>
                                <td class="text-center no-print">
                                    <a href="<?= BASE_URL ?>/exhibitions/expense_action.php?action=delete&id=<?= $exp['id'] ?>&exhibition_id=<?= $id ?>&csrf_token=<?= e(csrfToken()) ?>" onclick="return confirm('هل أنت متأكد من حذف هذا المصروف؟ سيتم إرجاعه لحساب الأرباح.')" class="btn btn-sm btn-outline-danger p-1" title="حذف المصروف">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light border-top-2 fw-bold">
                    <tr>
                        <td colspan="5" class="text-start">مجموع كل المصاريف المخصومة:</td>
                        <td class="text-end num text-danger fs-5">-<?= money($totalExpenses) ?></td>
                        <td class="no-print"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- 4. PRODUCTS SOLD TABLE (كافة الأشياء والمنتجات المباعة) -->
    <!-- ======================================================== -->
    <div class="panel mb-4">
        <div class="panel-header p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: #f0fdf4;">
            <div>
                <h5 class="fw-bold mb-0 text-success"><i class="bi bi-box-seam me-1"></i> كافة الأشياء والمنتجات المباعة في المعرض</h5>
                <small class="text-subtle">المنتجات التي بيعت خلال فترة المعرض وكونت إجمالي المبيعات (<?= money($totalProductsSales) ?>)</small>
            </div>
            <span class="badge bg-success fs-6"><?= count($soldProducts) ?> أصناف بيعت (<?= $totalQtySold ?> قطعة)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>اسم المنتج</th>
                        <th>الخيار / المقاس</th>
                        <th class="text-center">الكمية المباعة</th>
                        <th class="text-end">سعر البيع</th>
                        <th class="text-end">إجمالي مبيعات الصنف</th>
                        <th class="text-end">سعر التكلفة</th>
                        <th class="text-end">إجمالي التكلفة</th>
                        <th class="text-end">ربح الصنف</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($soldProducts)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-subtle">
                                <i class="bi bi-cart-x fs-1 d-block mb-2 text-tertiary"></i>
                                لا توجد مبيعات مسجلة في هذا المعرض أو خلال هذه الفترة حتى الآن.
                                <br><small>عند إجراء أي عملية بيع عبر الكاشير ستظهر الأصناف ومبيعاتها هنا فوراً.</small>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($soldProducts as $p): ?>
                            <?php 
                                $pRev = (float)$p['total_sales_amount'];
                                $pCost = (float)$p['total_cost'];
                                $pProf = (float)$p['product_profit'];
                            ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?= e($p['product_name_ar']) ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($p['color_ar']) || !empty($p['size'])): ?>
                                        <span class="badge bg-light text-dark border"><?= e(trim($p['color_ar'] . ' ' . $p['size'])) ?></span>
                                    <?php else: ?>
                                        <span class="text-subtle">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center num fw-bold text-dark fs-6"><?= (float)$p['total_qty_sold'] ?></td>
                                <td class="text-end num text-muted"><?= money($p['avg_unit_price']) ?></td>
                                <td class="text-end num fw-bold text-dark fs-6"><?= money($pRev) ?></td>
                                <td class="text-end num text-secondary"><?= money($p['unit_cost']) ?></td>
                                <td class="text-end num text-danger">-<?= money($pCost) ?></td>
                                <td class="text-end num fw-bold text-success fs-6">+<?= money($pProf) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light border-top-2 fw-bold">
                    <tr>
                        <td colspan="2" class="text-start">الإجمالي العام لمبيعات المعرض:</td>
                        <td class="text-center num fs-5"><?= $totalQtySold ?> قطعة</td>
                        <td></td>
                        <td class="text-end num text-dark fs-5"><?= money($totalProductsSales) ?></td>
                        <td></td>
                        <td class="text-end num text-danger fs-5">-<?= money($totalCogs) ?></td>
                        <td class="text-end num text-success fs-5">+<?= money($totalProductsSales - $totalCogs) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- 5. INVOICES LOG (الفواتير والمرتجعات) -->
    <!-- ======================================================== -->
    <div class="panel mb-4 no-print">
        <div class="panel-header p-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-receipt me-1 text-primary"></i> فواتير مبيعات المعرض المعتمدة</h5>
            <span class="badge bg-light text-dark border"><?= $invoicesCount ?> فاتورة</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>التاريخ والوقت</th>
                        <th>الكاشير</th>
                        <th>طريقة الدفع</th>
                        <th>الحالة</th>
                        <th class="text-end">الإجمالي المحصل</th>
                        <th class="text-center" style="width: 80px;">طباعة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoicesCount)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-subtle">لا توجد فواتير مسجلة في هذا المعرض حتى الآن.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $stmtInvoices = $pdo->prepare("
                            SELECT s.*, u.name_ar as cashier_name 
                            FROM sales s 
                            LEFT JOIN users u ON s.cashier_id = u.id 
                            WHERE (s.exhibition_id = ? OR (s.exhibition_id IS NULL AND DATE(s.created_at) BETWEEN ? AND ?)) 
                            ORDER BY s.id DESC
                        ");
                        $stmtInvoices->execute([$id, $startDate, $endDate]);
                        $invoicesList = $stmtInvoices->fetchAll();
                        // مبلغ المرتجع الفعلي لكل فاتورة (من جدول returns مباشرة، مش من status)
                        $invReturnStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM returns WHERE sale_id = ?");
                        ?>
                        <?php foreach ($invoicesList as $inv):
                            $invReturnStmt->execute([$inv['id']]);
                            $invReturned = (float)$invReturnStmt->fetchColumn();
                            $invNet = max(0, (float)$inv['total'] - $invReturned);
                        ?>
                            <tr class="<?= $inv['status'] === 'returned' ? 'table-danger bg-opacity-25' : ($inv['status'] === 'partial_return' ? 'table-warning bg-opacity-25' : '') ?>">
                                <td><span dir="ltr" class="num text-primary fw-bold">#<?= e($inv['invoice_number']) ?></span></td>
                                <td class="num text-subtle"><?= date('Y-m-d H:i', strtotime($inv['created_at'])) ?></td>
                                <td><?= e($inv['cashier_name'] ?: 'Admin') ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e($inv['payment_method']) ?></span></td>
                                <td>
                                    <?php if ($inv['status'] === 'returned'): ?>
                                        <span class="badge bg-danger text-white"><i class="bi bi-arrow-counterclockwise"></i> مرتجع بالكامل</span>
                                    <?php elseif ($inv['status'] === 'partial_return'): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-arrow-counterclockwise"></i> مرتجع جزئي</span>
                                    <?php else: ?>
                                        <span class="badge bg-success text-white">مكتملة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num text-end fw-bold">
                                    <?php if ($inv['status'] === 'returned'): ?>
                                        <del class="text-muted"><?= money($inv['total']) ?></del>
                                        <br><small class="text-danger fw-bold">(-<?= money($inv['total']) ?> مخصوم)</small>
                                    <?php elseif ($invReturned > 0): ?>
                                        <span class="text-dark"><?= money($invNet) ?></span>
                                        <br><small class="text-danger fw-bold">(اترجع منها <?= money($invReturned) ?>)</small>
                                    <?php else: ?>
                                        <span class="text-dark"><?= money($inv['total']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="<?= BASE_URL ?>/sales/invoice.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-light border">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
function quickFillExpense(title, category) {
    document.getElementById('exp_title').value = title;
    document.getElementById('exp_category').value = category;
    document.getElementById('exp_amount').focus();
}

function toggleNewExhibitionForm() {
    const box = document.getElementById('newExhibitionBox');
    if (box) {
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    }
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
