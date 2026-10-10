<?php
$pageTitle = 'فواتير المشتريات / Purchase Invoices';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'accountant'], 'manage_treasury');

$typeFilter = $_GET['invoice_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

$tableReady = true;
$invoices = [];
try {
    $sql = "
        SELECT pi.*, u.name_ar as user_name
        FROM purchase_invoices pi
        LEFT JOIN users u ON pi.created_by = u.id
        WHERE 1=1
    ";
    $params = [];
    if ($typeFilter !== '') {
        $sql .= " AND pi.invoice_type = ?";
        $params[] = $typeFilter;
    }
    if ($dateFrom !== '') {
        $sql .= " AND pi.purchase_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $sql .= " AND pi.purchase_date <= ?";
        $params[] = $dateTo;
    }
    $sql .= " ORDER BY pi.purchase_date DESC, pi.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (Throwable $e) {
    $tableReady = false;
}

$itemsByInvoice = [];
if ($tableReady && !empty($invoices)) {
    $ids = array_column($invoices, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtItems = $pdo->prepare("SELECT * FROM purchase_invoice_items WHERE purchase_invoice_id IN ($placeholders) ORDER BY id ASC");
    $stmtItems->execute($ids);
    foreach ($stmtItems->fetchAll() as $item) {
        $itemsByInvoice[$item['purchase_invoice_id']][] = $item;
    }
}

$totalGoods = 0.0;
$totalService = 0.0;
foreach ($invoices as $inv) {
    if ($inv['invoice_type'] === 'goods') {
        $totalGoods += floatval($inv['total_amount']);
    } else {
        $totalService += floatval($inv['total_amount']);
    }
}
$grandTotal = $totalGoods + $totalService;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-receipt-cutoff me-1 text-primary"></i> فواتير المشتريات <span class="text-muted fs-6">/ Purchase Invoices</span></h4>
        <p class="text-muted small mb-0">مشتريات البضاعة والمواد الخدمية اللي بتخدم المشروع — بتتسجل كمصروف يُخصم تلقائيًا من الصندوق الرئيسي</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/treasury/" class="btn btn-outline-primary rounded-pill px-3">
            <i class="bi bi-safe2 me-1"></i> الصندوق الرئيسي
        </a>
        <a href="<?= BASE_URL ?>/purchases/create.php" class="btn btn-primary rounded-pill px-3">
            <i class="bi bi-plus-lg me-1"></i> فاتورة مشتريات جديدة
        </a>
    </div>
</div>

<?php if (!$tableReady): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        جداول المشتريات لسه مش متعملة على قاعدة البيانات. شغّل ملف
        <code>config/migrate_main_fund.php</code> مرة واحدة من المتصفح على السيرفر أولاً.
    </div>
<?php else: ?>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i> تم تسجيل فاتورة المشتريات بنجاح، وتم خصمها من الصندوق الرئيسي.</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i> تم حذف الفاتورة بنجاح.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="panel h-100">
            <div class="panel-body p-3 text-center">
                <div class="text-subtle small mb-1">مشتريات البضاعة</div>
                <div class="fs-5 fw-bold num text-danger"><?= money($totalGoods) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="panel h-100">
            <div class="panel-body p-3 text-center">
                <div class="text-subtle small mb-1">مشتريات المواد والخدمات</div>
                <div class="fs-5 fw-bold num text-danger"><?= money($totalService) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-12">
        <div class="panel h-100">
            <div class="panel-body p-3 text-center">
                <div class="text-subtle small mb-1">إجمالي المشتريات</div>
                <div class="fs-5 fw-bold num text-danger"><?= money($grandTotal) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="panel mb-3 no-print">
    <div class="panel-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3 col-6">
                <label class="form-label text-caption mb-1">النوع</label>
                <select name="invoice_type" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    <option value="goods" <?= $typeFilter === 'goods' ? 'selected' : '' ?>>بضاعة</option>
                    <option value="service" <?= $typeFilter === 'service' ? 'selected' : '' ?>>مواد وخدمية</option>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label text-caption mb-1">من تاريخ</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label text-caption mb-1">إلى تاريخ</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-2 col-6">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i> فلترة</button>
            </div>
            <div class="col-md-1 col-6">
                <a href="<?= BASE_URL ?>/purchases/" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="panel mb-4">
    <div class="panel-header p-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-card-list me-1"></i> قائمة فواتير المشتريات</h5>
        <span class="badge bg-light text-dark border fw-bold"><?= count($invoices) ?> فاتورة</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 40px;"></th>
                    <th style="width: 110px;">التاريخ</th>
                    <th>النوع</th>
                    <th>المورد</th>
                    <th>رقم فاتورة المورد</th>
                    <th>المسؤول</th>
                    <th style="width: 110px;">طريقة الدفع</th>
                    <th class="text-end" style="width: 140px;">الإجمالي</th>
                    <th class="text-center no-print" style="width: 80px;">إلغاء</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-subtle">
                            لا توجد فواتير مشتريات مسجلة حتى الآن.
                            <a href="<?= BASE_URL ?>/purchases/create.php">أضف أول فاتورة</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <?php
                            $pmLabels = ['cash' => 'كاش', 'card' => 'بطاقة', 'transfer' => 'تحويل'];
                            $items = $itemsByInvoice[$inv['id']] ?? [];
                            $rowTarget = 'inv-items-' . $inv['id'];
                        ?>
                        <tr>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-secondary p-1" data-bs-toggle="collapse" data-bs-target="#<?= $rowTarget ?>" title="عرض البنود">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </td>
                            <td class="num text-subtle"><?= e($inv['purchase_date']) ?></td>
                            <td>
                                <?php if ($inv['invoice_type'] === 'goods'): ?>
                                    <span class="badge bg-light text-dark border"><i class="bi bi-box-seam me-1 text-primary"></i> بضاعة</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border"><i class="bi bi-gear me-1 text-warning"></i> مواد/خدمية</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($inv['supplier_name'] ?: '—') ?></td>
                            <td class="text-subtle small"><?= e($inv['invoice_ref'] ?: '—') ?></td>
                            <td class="small text-subtle"><?= e($inv['user_name'] ?: 'المدير') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= $pmLabels[$inv['payment_method']] ?? 'كاش' ?></span></td>
                            <td class="text-end num fw-bold text-danger fs-6">-<?= money($inv['total_amount']) ?></td>
                            <td class="text-center no-print">
                                <a href="<?= BASE_URL ?>/purchases/action.php?action=delete&id=<?= $inv['id'] ?>&csrf_token=<?= e(csrfToken()) ?>" onclick="return confirm('هل أنت متأكد من حذف هذه الفاتورة؟ سيتم إرجاع مبلغها للصندوق الرئيسي.')" class="btn btn-sm btn-outline-danger p-1" title="حذف">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <tr class="collapse" id="<?= $rowTarget ?>">
                            <td></td>
                            <td colspan="8" class="p-0">
                                <div class="p-3" style="background: var(--surface-app);">
                                    <?php if (empty($items)): ?>
                                        <span class="text-subtle small">لا توجد بنود.</span>
                                    <?php else: ?>
                                        <table class="table table-sm mb-0">
                                            <thead>
                                                <tr class="text-subtle small">
                                                    <th>البيان</th>
                                                    <th class="text-center">الكمية</th>
                                                    <th class="text-end">سعر الوحدة</th>
                                                    <th class="text-end">الإجمالي</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $it): ?>
                                                    <tr>
                                                        <td><?= e($it['description']) ?></td>
                                                        <td class="text-center num"><?= rtrim(rtrim(number_format($it['quantity'], 2), '0'), '.') ?></td>
                                                        <td class="text-end num"><?= money($it['unit_price']) ?></td>
                                                        <td class="text-end num fw-bold"><?= money($it['line_total']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                    <?php if (!empty($inv['notes'])): ?>
                                        <div class="text-subtle small mt-2"><i class="bi bi-sticky me-1"></i> <?= e($inv['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($invoices)): ?>
            <tfoot class="table-light border-top-2 fw-bold">
                <tr>
                    <td colspan="7" class="text-start">الإجمالي الكلي:</td>
                    <td class="text-end num text-danger fs-5">-<?= money($grandTotal) ?></td>
                    <td class="no-print"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
