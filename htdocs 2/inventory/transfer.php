<?php
$pageTitle = 'تحويل بين المخزن والمعرض / Transfer Stock';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'stock'], 'manage_inventory');

$products = $pdo->query("SELECT * FROM products WHERE is_active = 1 ORDER BY name_ar ASC")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = intval($_POST['product_id'] ?? 0);
    $variantId = !empty($_POST['variant_id']) ? intval($_POST['variant_id']) : null;
    $quantity = floatval($_POST['quantity'] ?? 0);
    $fromLocation = in_array($_POST['from_location'] ?? '', ['warehouse', 'showroom']) ? $_POST['from_location'] : 'warehouse';
    $toLocation = in_array($_POST['to_location'] ?? '', ['warehouse', 'showroom']) ? $_POST['to_location'] : 'showroom';
    $notes = trim($_POST['notes'] ?? '');

    if ($productId <= 0 || $quantity <= 0) {
        $error = 'يرجى اختيار المنتج وتحديد كمية صالحة أكبر من صفر.';
    } elseif ($fromLocation === $toLocation) {
        $error = 'المكان المصدر والمكان الهدف لازم يكونوا مختلفين.';
    } else {
        try {
            $pdo->beginTransaction();

            // ملاحظة: التحويل بين المخزن والمعرض بيوثّق مكان تواجد البضاعة فعلياً
            // (لتنظيم الحركة اليومية بين المخزن وصالة العرض) ولا يغيّر إجمالي الكمية
            // المتاحة للمنتج، لأن قاعدة البيانات بتتبع رصيد إجمالي واحد لكل منتج/خيار
            // (مش أرصدة منفصلة لكل مكان).
            $stmtMov = $pdo->prepare("
                INSERT INTO inventory_movements (product_id, variant_id, movement_type, quantity, from_location, to_location, reference_id, notes, user_id)
                VALUES (?, ?, 'transfer_out', ?, ?, ?, NULL, ?, ?)
            ");
            $stmtMov->execute([$productId, $variantId, $quantity, $fromLocation, $toLocation, $notes ?: 'تحويل بضاعة', $_SESSION['user_id']]);
            $movementId = $pdo->lastInsertId();

            logAudit($pdo, 'transfer_stock', 'inventory', $productId, null, [
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'from_location' => $fromLocation,
                'to_location' => $toLocation,
                'notes' => $notes,
            ]);

            $pdo->commit();
            $fromLabel = $fromLocation === 'warehouse' ? 'المخزن' : 'المعرض';
            $toLabel = $toLocation === 'warehouse' ? 'المخزن' : 'المعرض';
            $success = "تم تسجيل تحويل {$quantity} قطعة من {$fromLabel} إلى {$toLabel} بنجاح!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'خطأ أثناء تسجيل التحويل: ' . $e->getMessage();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">تحويل بضاعة بين المخزن والمعرض <span class="text-muted fs-6">/ Transfer Stock</span></h4>
                <p class="text-muted small mb-0">توثيق نقل كمية من مكان لمكان (مخزن ↔ معرض) في سجل حركات المخزون</p>
            </div>
            <a href="<?= BASE_URL ?>/inventory/" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-arrow-right me-1"></i> العودة للمخزون
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-4" role="alert"><i class="bi bi-exclamation-triangle-fill ms-2"></i><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-2 mb-4" role="alert"><i class="bi bi-check-circle-fill ms-2"></i><?= e($success) ?></div>
        <?php endif; ?>

        <div class="panel ">
            <div class="panel-body p-4">
                <form method="POST" action="">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold">اختر المنتج *</label>
                        <select name="product_id" id="productSelect" class="form-select" required onchange="loadVariantsForProduct(this.value)">
                            <option value="">اختر المنتج...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" data-variants="<?= $p['has_variants'] ? '1' : '0' ?>">
                                    <?= e($p['name_ar']) ?> (<?= e($p['name_en']) ?>) — SKU: <?= e($p['sku'] ?: '—') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Variant Select (if product has variants) -->
                    <div class="mb-3" id="variantGroup" style="display: none;">
                        <label class="form-label fw-bold">الخيار / اللون والمقاس *</label>
                        <select name="variant_id" id="variantSelect" class="form-select">
                            <!-- Populated via AJAX / JS -->
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">من (المصدر) *</label>
                            <select name="from_location" id="fromLocation" class="form-select" onchange="syncToLocation()">
                                <option value="warehouse">المخزن</option>
                                <option value="showroom">المعرض</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">إلى (الهدف) *</label>
                            <select name="to_location" id="toLocation" class="form-select">
                                <option value="showroom">المعرض</option>
                                <option value="warehouse">المخزن</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الكمية المحوّلة (قطع) *</label>
                            <input type="number" step="1" name="quantity" class="form-control" required min="1" placeholder="مثال: 10">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">ملاحظات (اختياري)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="مثال: تجهيز المعرض ليوم افتتاح المعرض..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 py-2 fw-bold">
                        <i class="bi bi-arrow-left-right me-1"></i> تأكيد التحويل
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function loadVariantsForProduct(productId) {
    const variantGroup = document.getElementById('variantGroup');
    const variantSelect = document.getElementById('variantSelect');

    if (!productId) {
        variantGroup.style.display = 'none';
        return;
    }

    fetch(`<?= BASE_URL ?>/inventory/get_variants.php?product_id=${productId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.variants.length > 0) {
                variantSelect.innerHTML = '<option value="">اختر اللون والمقاس...</option>';
                data.variants.forEach(v => {
                    variantSelect.innerHTML += `<option value="${v.id}">${v.color_ar || ''} ${v.size || ''} (الرصيد الحالي: ${v.stock_qty})</option>`;
                });
                variantGroup.style.display = 'block';
                variantSelect.required = true;
            } else {
                variantGroup.style.display = 'none';
                variantSelect.required = false;
            }
        });
}

function syncToLocation() {
    const from = document.getElementById('fromLocation').value;
    const to = document.getElementById('toLocation');
    to.value = from === 'warehouse' ? 'showroom' : 'warehouse';
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
