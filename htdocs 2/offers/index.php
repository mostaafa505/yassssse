<?php
$pageTitle = 'العروض والخصومات / Offers';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin'], 'manage_offers');

$message = '';

// Toggle Status
if (isset($_GET['toggle']) && verifyCsrf($_GET['csrf_token'] ?? null)) {
    $offerId = intval($_GET['toggle']);
    $before = $pdo->prepare("SELECT is_active FROM offers WHERE id = ?");
    $before->execute([$offerId]);
    $wasActive = $before->fetchColumn();
    if ($wasActive !== false) {
        $newActive = $wasActive ? 0 : 1;
        $pdo->prepare("UPDATE offers SET is_active = ? WHERE id = ?")->execute([$newActive, $offerId]);
        logAudit($pdo, 'toggle_offer', 'offer', $offerId, ['is_active' => (int)$wasActive], ['is_active' => $newActive]);
    }
    redirect('offers/index.php');
}

// Add New Offer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $message = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $type = in_array($_POST['discount_type'] ?? '', ['percent', 'fixed', 'set_price']) ? $_POST['discount_type'] : 'percent';
    $value = floatval($_POST['discount_value'] ?? 0);
    $applyTo = in_array($_POST['apply_to'] ?? '', ['all', 'category', 'product']) ? $_POST['apply_to'] : 'all';
    $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

    $targetIds = null;
    if ($applyTo === 'category') {
        $ids = array_map('intval', $_POST['category_ids'] ?? []);
        $targetIds = !empty($ids) ? json_encode(array_values($ids)) : null;
    } elseif ($applyTo === 'product') {
        $ids = array_map('intval', $_POST['product_ids'] ?? []);
        $targetIds = !empty($ids) ? json_encode(array_values($ids)) : null;
    }
    // لو النطاق قسم/منتج ومفيش أي اختيار، ارجع لـ "الكل" بدل ما يتحفظ عرض ماينطبقش على حاجة
    if (in_array($applyTo, ['category', 'product'], true) && $targetIds === null) {
        $applyTo = 'all';
    }

    if ($nameAr && $value > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO offers (name_ar, name_en, discount_type, discount_value, apply_to, target_ids, start_date, end_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nameAr, $nameEn ?: $nameAr, $type, $value, $applyTo, $targetIds, $startDate, $endDate, $_SESSION['user_id']]);
        logAudit($pdo, 'create_offer', 'offer', $pdo->lastInsertId(), null, [
            'name_ar' => $nameAr, 'discount_type' => $type, 'value' => $value,
            'apply_to' => $applyTo, 'start_date' => $startDate, 'end_date' => $endDate,
        ]);
        $message = 'تم إضافة العرض الترويجي بنجاح!';
    }
}

$offers = $pdo->query("SELECT * FROM offers ORDER BY id DESC")->fetchAll();
$allCategories = $pdo->query("SELECT id, name_ar, name_en FROM categories ORDER BY sort ASC, name_ar ASC")->fetchAll();
$allProducts = $pdo->query("SELECT id, name_ar, name_en, sku FROM products WHERE is_active = 1 ORDER BY name_ar ASC")->fetchAll();

// أسماء الفئات/المنتجات المستهدفة، لعرضها في جدول العروض بدل أرقام الـ ID فقط
$catNamesById = array_column($allCategories, 'name_ar', 'id');
$prodNamesById = array_column($allProducts, 'name_ar', 'id');

function offerScopeLabel(array $o, array $catNamesById, array $prodNamesById): string {
    if ($o['apply_to'] === 'all') return 'جميع المنتجات';
    $ids = json_decode($o['target_ids'] ?? '[]', true) ?: [];
    $names = $o['apply_to'] === 'category'
        ? array_map(fn($id) => $catNamesById[$id] ?? "#$id", $ids)
        : array_map(fn($id) => $prodNamesById[$id] ?? "#$id", $ids);
    if (empty($names)) return $o['apply_to'] === 'category' ? 'أقسام محددة' : 'منتجات محددة';
    $label = implode('، ', array_slice($names, 0, 3));
    if (count($names) > 3) $label .= ' +' . (count($names) - 3);
    return $label;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">إدارة العروض الترويجية</h1>
        <p class="text-subtle mb-0">تفعيل وتحديد عروض التخفيضات والأسعار الترويجية للمنتجات</p>
    </div>
    <button class="btn btn-primary px-3 py-2" data-bs-toggle="modal" data-bs-target="#newOfferModal">
        <i class="bi bi-plus-circle me-1"></i> إنشاء عرض جديد
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success py-2 mb-4" role="alert"><i class="bi bi-check-circle-fill ms-2"></i><?= e($message) ?></div>
<?php endif; ?>

<!-- Offers Table -->
<div class="panel">
    <div class="table-wrapper">
        <div class="table-responsive border-0">
<table class="table table-hover mb-0">
            <thead style="background: var(--surface-default);">
                <tr>
                    <th class="text-caption text-subtle border-bottom-0">اسم العرض</th>
                    <th class="text-caption text-subtle border-bottom-0">نوع الخصم</th>
                    <th class="text-caption text-subtle border-bottom-0">القيمة</th>
                    <th class="text-caption text-subtle border-bottom-0">النطاق</th>
                    <th class="text-caption text-subtle border-bottom-0">فترة السريان</th>
                    <th class="text-caption text-subtle border-bottom-0">الحالة</th>
                    <th class="text-center text-caption text-subtle border-bottom-0">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($offers)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-subtle"><i class="bi bi-tags fs-1 d-block mb-3 text-tertiary"></i>لا توجد عروض مسجلة حتى الآن.</td></tr>
                <?php else: ?>
                    <?php foreach ($offers as $o): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark mb-1"><span dir="rtl"><?= e($o['name_ar']) ?></span></div>
                                <div class="text-caption text-subtle"><span dir="rtl"><?= e($o['name_en']) ?></span></div>
                            </td>
                            <td>
                                <?php if ($o['discount_type'] === 'percent'): ?>
                                    <span class="badge" style="background: #EFF6FF; color: #2563EB; border: 1px solid rgba(37,99,235,0.2);">نسبة مئوية %</span>
                                <?php elseif ($o['discount_type'] === 'fixed'): ?>
                                    <span class="badge" style="background: var(--intent-success-bg); color: var(--intent-success); border: 1px solid rgba(16,185,129,0.2);">مبلغ خصم ثابت</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #FDF4FF; color: #A21CAF; border: 1px solid rgba(162,28,175,0.2);">سعر محدد</span>
                                <?php endif; ?>
                            </td>
                            <td><strong class="title-medium num text-dark"><?= (float)$o['discount_value'] ?> <?= $o['discount_type'] === 'percent' ? '%' : 'AED' ?></strong></td>
                            <td>
                                <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">
                                    <?= e(offerScopeLabel($o, $catNamesById, $prodNamesById)) ?>
                                </span>
                            </td>
                            <td>
                                <?php $isExpired = $o['end_date'] && $o['end_date'] < date('Y-m-d'); ?>
                                <div class="text-caption text-subtle num" dir="ltr" style="text-align: right;">
                                    <?= $o['start_date'] ? $o['start_date'] : 'الآن' ?> &rarr; <?= $o['end_date'] ? $o['end_date'] : 'مستمر' ?>
                                    <?php if ($isExpired): ?><span class="badge status-inactive ms-1">منتهي</span><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($o['is_active'] && !$isExpired): ?>
                                    <span class="badge status-active"><i class="bi bi-check-circle me-1"></i> مفعل</span>
                                <?php else: ?>
                                    <span class="badge status-inactive">متوقف</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="<?= BASE_URL ?>/offers/?toggle=<?= $o['id'] ?>&csrf_token=<?= e(csrfToken()) ?>" class="btn btn-sm <?= $o['is_active'] ? 'btn-outline-danger' : 'btn-light' ?> px-3">
                                    <?= $o['is_active'] ? 'إيقاف' : 'تفعيل' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
</div>
    </div>
</div>

<!-- New Offer Modal -->
<div class="modal fade" id="newOfferModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius: 16px; box-shadow: var(--shadow-modal);">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h6 class="title-medium text-dark mb-0">إنشاء عرض جديد</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?= csrfField() ?>
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label class="form-label text-caption">اسم العرض (عربي) *</label>
                        <input type="text" name="name_ar" class="form-control" required placeholder="مثال: خصم موسم الصيف 20%">
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-caption">اسم العرض (English)</label>
                        <input type="text" name="name_en" class="form-control" placeholder="e.g. Summer Sale 20%">
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label text-caption">نوع الخصم</label>
                            <select name="discount_type" id="discountTypeSelect" class="form-select" onchange="toggleDiscountValueLabel()">
                                <option value="percent">نسبة مئوية (%)</option>
                                <option value="fixed">مبلغ خصم ثابت (AED)</option>
                                <option value="set_price">تحديد سعر ثابت (AED)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-caption" id="discountValueLabel">القيمة (%) *</label>
                            <input type="number" step="0.01" min="0" name="discount_value" class="form-control" required placeholder="مثال: 20">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-caption">نطاق العرض *</label>
                        <select name="apply_to" id="applyToSelect" class="form-select" onchange="toggleScopePicker()">
                            <option value="all">جميع المنتجات</option>
                            <option value="category">أقسام محددة</option>
                            <option value="product">منتجات محددة (منتج واحد أو أكثر)</option>
                        </select>
                    </div>
                    <div class="mb-4 d-none" id="categoryPickerBox">
                        <label class="form-label text-caption">اختر الأقسام المشمولة بالعرض</label>
                        <div class="border rounded-3 p-2" style="max-height: 160px; overflow-y: auto;">
                            <?php foreach ($allCategories as $c): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="category_ids[]" value="<?= $c['id'] ?>" id="cat_<?= $c['id'] ?>">
                                    <label class="form-check-label small" for="cat_<?= $c['id'] ?>"><?= e($c['name_ar']) ?> (<?= e($c['name_en']) ?>)</label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($allCategories)): ?><div class="text-subtle small">لا توجد أقسام مسجلة.</div><?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-4 d-none" id="productPickerBox">
                        <label class="form-label text-caption">اختر المنتج أو المنتجات المشمولة بالعرض</label>
                        <div class="border rounded-3 p-2" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($allProducts as $p): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="product_ids[]" value="<?= $p['id'] ?>" id="prod_<?= $p['id'] ?>">
                                    <label class="form-check-label small" for="prod_<?= $p['id'] ?>"><?= e($p['name_ar']) ?> <span class="text-subtle">(<?= e($p['sku'] ?: '—') ?>)</span></label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($allProducts)): ?><div class="text-subtle small">لا توجد منتجات مسجلة.</div><?php endif; ?>
                        </div>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-6">
                            <label class="form-label text-caption">تاريخ البداية</label>
                            <input type="date" name="start_date" class="form-control text-start">
                        </div>
                        <div class="col-6">
                            <label class="form-label text-caption">تاريخ النهاية</label>
                            <input type="date" name="end_date" class="form-control text-start">
                        </div>
                    </div>
                    <div class="text-caption text-subtle">لو سبت تاريخ البداية/النهاية فاضي، العرض هيبقى شغال من لحظة التفعيل وباستمرار لحد ما توقفه يدوياً.</div>
                </div>
                <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary px-4">حفظ العرض</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDiscountValueLabel() {
    const type = document.getElementById('discountTypeSelect').value;
    const label = document.getElementById('discountValueLabel');
    if (type === 'percent') label.textContent = 'القيمة (%) *';
    else if (type === 'fixed') label.textContent = 'قيمة الخصم (AED) *';
    else label.textContent = 'السعر الجديد (AED) *';
}
function toggleScopePicker() {
    const applyTo = document.getElementById('applyToSelect').value;
    document.getElementById('categoryPickerBox').classList.toggle('d-none', applyTo !== 'category');
    document.getElementById('productPickerBox').classList.toggle('d-none', applyTo !== 'product');
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
