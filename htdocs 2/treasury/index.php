<?php
$pageTitle = 'الصندوق الرئيسي / Main Cash Fund';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'accountant'], 'manage_treasury');

// ── تصنيفات المصروف العام (مش مرتبط بمعرض ولا فاتورة مشتريات) ──────────────
$expenseCategories = [
    'rent'      => ['title' => 'إيجار', 'icon' => 'bi-building', 'color' => '#6366f1'],
    'salaries'  => ['title' => 'رواتب وبدلات', 'icon' => 'bi-people', 'color' => '#0ea5e9'],
    'utilities' => ['title' => 'فواتير وخدمات (كهرباء، نت...)', 'icon' => 'bi-lightning-charge', 'color' => '#f59e0b'],
    'transport' => ['title' => 'مواصلات ونقل', 'icon' => 'bi-truck', 'color' => '#8b5cf6'],
    'maintenance' => ['title' => 'صيانة', 'icon' => 'bi-tools', 'color' => '#10b981'],
    'marketing' => ['title' => 'تسويق وإعلانات', 'icon' => 'bi-megaphone', 'color' => '#ef4444'],
    'other'     => ['title' => 'مصاريف أخرى ونثرية', 'icon' => 'bi-three-dots', 'color' => '#64748b'],
];

$typeLabels = [
    'funding'          => ['title' => 'تغذية الصندوق', 'icon' => 'bi-plus-circle', 'color' => '#16a34a'],
    'expense'          => ['title' => 'مصروف عام', 'icon' => 'bi-dash-circle', 'color' => '#dc2626'],
    'purchase_goods'   => ['title' => 'شراء بضاعة', 'icon' => 'bi-box-seam', 'color' => '#dc2626'],
    'purchase_service' => ['title' => 'شراء مواد/خدمة', 'icon' => 'bi-gear', 'color' => '#dc2626'],
];

// ── فلاتر العرض (التاريخ والنوع) ───────────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
$typeFilter = $_GET['type'] ?? '';

// ── جلب كل الحركات (من الأقدم للأحدث) عشان نحسب الرصيد الجاري بشكل صحيح ────
$transactions = [];
$tableReady = true;
try {
    $transactions = $pdo->query("
        SELECT t.*, u.name_ar as user_name
        FROM treasury_transactions t
        LEFT JOIN users u ON t.created_by = u.id
        ORDER BY t.transaction_date ASC, t.id ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $tableReady = false;
}

$runningCash = 0.0;
foreach ($transactions as &$t) {
    $signed = ($t['type'] === 'funding') ? floatval($t['amount']) : -floatval($t['amount']);
    if ($t['payment_method'] === 'cash') {
        $runningCash += $signed;
    }
    $t['running_cash_balance'] = $runningCash;
}
unset($t);

$currentBalance = $runningCash;
$isShortage = $currentBalance < 0;

// إجماليات سريعة (كل طرق الدفع، للمعلومية العامة عن حجم المصروفات)
$totalFunding = 0.0;
$totalExpensesAll = 0.0;
foreach ($transactions as $t) {
    if ($t['type'] === 'funding') {
        $totalFunding += floatval($t['amount']);
    } else {
        $totalExpensesAll += floatval($t['amount']);
    }
}

// أحدث الحركات فوق، مع تطبيق الفلاتر للعرض بس (الرصيد الجاري محسوب من كل التاريخ)
$displayList = array_reverse($transactions);
if ($dateFrom !== '') {
    $displayList = array_filter($displayList, fn($t) => $t['transaction_date'] >= $dateFrom);
}
if ($dateTo !== '') {
    $displayList = array_filter($displayList, fn($t) => $t['transaction_date'] <= $dateTo);
}
if ($typeFilter !== '') {
    $displayList = array_filter($displayList, fn($t) => $t['type'] === $typeFilter);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-safe2 me-1 text-primary"></i> الصندوق الرئيسي <span class="text-muted fs-6">/ Main Cash Fund</span></h4>
        <p class="text-muted small mb-0">سجل حركات الكاش الفعلي: تغذية الصندوق، والمصاريف العامة، ومصاريف المعارض، وفواتير المشتريات</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/purchases/" class="btn btn-outline-primary rounded-pill px-3">
            <i class="bi bi-receipt-cutoff me-1"></i> فواتير المشتريات
        </a>
        <a href="<?= BASE_URL ?>/treasury/export.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success rounded-pill px-3">
            <i class="bi bi-file-earmark-excel me-1"></i> تصدير
        </a>
    </div>
</div>

<?php if (!$tableReady): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        جداول الصندوق الرئيسي لسه مش متعملة على قاعدة البيانات. شغّل ملف
        <code>config/migrate_main_fund.php</code> مرة واحدة من المتصفح على السيرفر أولاً.
    </div>
<?php else: ?>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i> تم تسجيل الحركة بنجاح.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i> تم حذف الحركة بنجاح.</div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> حصل خطأ، من فضلك حاول مرة أخرى.</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- 1. رصيد الصندوق الحالي -->
<!-- ======================================================== -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="panel h-100" style="border-inline-start: 5px solid <?= $isShortage ? '#dc2626' : '#16a34a' ?>;">
            <div class="panel-body p-4">
                <?php if ($isShortage): ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-exclamation-triangle-fill text-danger fs-3"></i>
                        <span class="fw-bold text-danger fs-5">⚠️ الصندوق في حالة عجز (نقص)</span>
                    </div>
                    <div class="fs-2 fw-bold num text-danger mb-1">-<?= money(abs($currentBalance)) ?></div>
                    <p class="text-subtle mb-0 small">تم صرف مبلغ أكبر من الكاش الموجود فعليًا في الصندوق. راجع آخر الحركات أدناه (خصوصًا اللي عليها علامة "سبب النقص") لمعرفة التفاصيل، وقم بتغذية الصندوق لتغطية العجز.</p>
                <?php else: ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-check-circle-fill text-success fs-3"></i>
                        <span class="fw-bold text-success fs-5">✅ الصندوق سليم</span>
                    </div>
                    <div class="fs-2 fw-bold num text-success mb-1"><?= money($currentBalance) ?></div>
                    <p class="text-subtle mb-0 small">الرصيد الحالي المتوفر فعليًا كاش في الصندوق الرئيسي.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="panel h-100">
            <div class="panel-body p-3 text-center">
                <div class="text-subtle small mb-1">إجمالي التغذية</div>
                <div class="fs-5 fw-bold num text-success"><?= money($totalFunding) ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="panel h-100">
            <div class="panel-body p-3 text-center">
                <div class="text-subtle small mb-1">إجمالي المصاريف (كل طرق الدفع)</div>
                <div class="fs-5 fw-bold num text-danger"><?= money($totalExpensesAll) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- 2. فورمين سريعين: تغذية الصندوق + مصروف عام -->
<!-- ======================================================== -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="panel no-print border-top-4" style="border-top: 4px solid #16a34a;">
            <div class="panel-header p-3 border-bottom" style="background: #f0fdf4;">
                <h6 class="fw-bold mb-0 text-success"><i class="bi bi-plus-circle me-1"></i> تغذية الصندوق (إيداع كاش)</h6>
            </div>
            <div class="panel-body p-3">
                <form method="POST" action="<?= BASE_URL ?>/treasury/action.php" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_funding">
                    <div class="col-md-6 col-12">
                        <label class="form-label text-caption mb-1">البيان <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-sm" placeholder="مثال: رأس مال ابتدائي، تغذية من صاحب المحل..." required>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label text-caption mb-1">المبلغ (درهم) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm fw-bold" placeholder="0.00" required>
                    </div>
                    <div class="col-md-2 col-4">
                        <label class="form-label text-caption mb-1">التاريخ</label>
                        <input type="date" name="transaction_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-1 col-2">
                        <button type="submit" class="btn btn-success btn-sm w-100 fw-bold py-1"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel no-print border-top-4" style="border-top: 4px solid #dc2626;">
            <div class="panel-header p-3 border-bottom" style="background: #fff5f5;">
                <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-dash-circle me-1"></i> صرف مصروف عام من الصندوق</h6>
            </div>
            <div class="panel-body p-3">
                <form method="POST" action="<?= BASE_URL ?>/treasury/action.php" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_expense">
                    <div class="col-md-3 col-6">
                        <label class="form-label text-caption mb-1">التصنيف</label>
                        <select name="category" class="form-select form-select-sm">
                            <?php foreach ($expenseCategories as $ck => $cv): ?>
                                <option value="<?= $ck ?>"><?= $cv['title'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-6">
                        <label class="form-label text-caption mb-1">البيان <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-sm" placeholder="مثال: فاتورة كهرباء الشهر" required>
                    </div>
                    <div class="col-md-2 col-4">
                        <label class="form-label text-caption mb-1">المبلغ <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm fw-bold" placeholder="0.00" required>
                    </div>
                    <div class="col-md-2 col-4">
                        <label class="form-label text-caption mb-1">طريقة الدفع</label>
                        <select name="payment_method" class="form-select form-select-sm">
                            <option value="cash">كاش</option>
                            <option value="card">بطاقة</option>
                            <option value="transfer">تحويل</option>
                        </select>
                    </div>
                    <div class="col-md-1 col-4">
                        <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold py-1"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- 3. فلترة السجل -->
<!-- ======================================================== -->
<div class="panel mb-3 no-print">
    <div class="panel-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3 col-6">
                <label class="form-label text-caption mb-1">من تاريخ</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label text-caption mb-1">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label text-caption mb-1">النوع</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    <?php foreach ($typeLabels as $tk => $tv): ?>
                        <option value="<?= $tk ?>" <?= $typeFilter === $tk ? 'selected' : '' ?>><?= $tv['title'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i> فلترة</button>
            </div>
            <div class="col-md-1 col-6">
                <a href="<?= BASE_URL ?>/treasury/" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- 4. سجل حركات الصندوق -->
<!-- ======================================================== -->
<div class="panel mb-4">
    <div class="panel-header p-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-list-columns-reverse me-1"></i> سجل حركات الصندوق</h5>
        <span class="badge bg-light text-dark border fw-bold"><?= count($displayList) ?> حركة</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 110px;">التاريخ</th>
                    <th>النوع</th>
                    <th>البيان</th>
                    <th>المسؤول</th>
                    <th style="width: 110px;">طريقة الدفع</th>
                    <th class="text-end" style="width: 140px;">المبلغ</th>
                    <th class="text-end" style="width: 140px;">الرصيد بعدها (كاش)</th>
                    <th class="text-center no-print" style="width: 80px;">إلغاء</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($displayList)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-subtle">لا توجد حركات مسجلة حتى الآن.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($displayList as $t): ?>
                        <?php
                            $tKey = $t['type'];
                            $tData = $typeLabels[$tKey] ?? $typeLabels['expense'];
                            $isIn = $tKey === 'funding';
                            $rowIsShortage = $t['payment_method'] === 'cash' && floatval($t['running_cash_balance']) < 0;
                            $pmLabels = ['cash' => 'كاش', 'card' => 'بطاقة', 'transfer' => 'تحويل'];
                        ?>
                        <tr class="<?= $rowIsShortage ? 'table-danger' : '' ?>">
                            <td class="num text-subtle"><?= e($t['transaction_date']) ?></td>
                            <td>
                                <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: <?= $tData['color'] ?>;">
                                    <i class="bi <?= $tData['icon'] ?> me-1"></i> <?= $tData['title'] ?>
                                </span>
                            </td>
                            <td>
                                <strong class="text-dark"><?= e($t['title']) ?></strong>
                                <?php if ($rowIsShortage): ?>
                                    <br><span class="badge bg-danger-subtle text-danger border border-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i> سبب النقص</span>
                                <?php endif; ?>
                                <?php if (!empty($t['reference_type'])): ?>
                                    <br><small class="text-subtle">
                                        <?= $t['reference_type'] === 'exhibition_expense' ? 'مصدر: مصروف معرض (يُدار من صفحة المعارض)' : 'مصدر: فاتورة مشتريات (تُدار من صفحة المشتريات)' ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="small text-subtle"><?= e($t['user_name'] ?: 'المدير') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= $pmLabels[$t['payment_method']] ?? 'كاش' ?></span></td>
                            <td class="text-end num fw-bold <?= $isIn ? 'text-success' : 'text-danger' ?> fs-6">
                                <?= $isIn ? '+' : '-' ?><?= money($t['amount']) ?>
                            </td>
                            <td class="text-end num fw-bold <?= $t['running_cash_balance'] < 0 ? 'text-danger' : 'text-dark' ?>">
                                <?= money($t['running_cash_balance']) ?>
                            </td>
                            <td class="text-center no-print">
                                <?php if (empty($t['reference_type'])): ?>
                                    <a href="<?= BASE_URL ?>/treasury/action.php?action=delete&id=<?= $t['id'] ?>&csrf_token=<?= e(csrfToken()) ?>" onclick="return confirm('هل أنت متأكد من حذف هذه الحركة؟')" class="btn btn-sm btn-outline-danger p-1" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-subtle small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
