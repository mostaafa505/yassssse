<?php
$pageTitle = 'استلام بضاعة ومخزون جديد / Receive Stock';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'stock'], 'manage_inventory');

$products = $pdo->query("SELECT * FROM products WHERE is_active = 1 ORDER BY name_ar ASC")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productIds = $_POST['product_id'] ?? [];
    $variantIds = $_POST['variant_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $costPrices = $_POST['cost_price'] ?? [];
    $notes = trim($_POST['notes'] ?? '');

    // ── جهّز بنود الفاتورة الصالحة بس (صف فاضي أو بدون منتج/كمية يتجاهل) ────────
    $rows = [];
    foreach ($productIds as $i => $pid) {
        $productId = intval($pid);
        $variantId = !empty($variantIds[$i]) ? intval($variantIds[$i]) : null;
        $quantity = floatval($quantities[$i] ?? 0);
        $costPrice = !empty($costPrices[$i]) ? floatval($costPrices[$i]) : null;

        if ($productId <= 0 && $quantity <= 0) continue; // صف فاضي تمامًا، تجاهله

        if ($productId <= 0 || $quantity <= 0) {
            $error = 'كل صف مضاف لازم يكون فيه منتج مختار وكمية صالحة أكبر من صفر.';
            $rows = [];
            break;
        }

        $rows[] = compact('productId', 'variantId', 'quantity', 'costPrice');
    }

    if (!$error && empty($rows)) {
        $error = 'يرجى إضافة منتج واحد على الأقل بكمية صالحة.';
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            $stmtMov = $pdo->prepare("
                INSERT INTO inventory_movements (product_id, variant_id, movement_type, quantity, cost_price, notes, user_id)
                VALUES (?, ?, 'in', ?, ?, ?, ?)
            ");

            $receivedSummary = [];
            $totalQty = 0;

            foreach ($rows as $row) {
                // متوسط التكلفة المرجح: لو المستخدم دخل تكلفة للدفعة الجديدة، بنحسب
                // متوسط مرجح بين المخزون الحالي (بتكلفته المسجلة) والدفعة الجديدة
                // (بتكلفتها) بدل ما نستبدل تكلفة المنتج بالكامل — قرار العميل صريح:
                // متوسط مرجح، مش استبدال. لو المنتج مكنش ليه تكلفة مسجلة قبل كده
                // (NULL)، مفيش حاجة نعمل لها متوسط معاها، فبنسجل تكلفة الدفعة الجديدة
                // كما هي.
                if ($row['variantId']) {
                    $stmtCheck = $pdo->prepare("SELECT pv.id, pv.color_ar, pv.color_en, pv.size, pv.stock_qty, pv.cost_price, p.name_ar FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = ? AND pv.product_id = ? FOR UPDATE");
                    $stmtCheck->execute([$row['variantId'], $row['productId']]);
                    $found = $stmtCheck->fetch();
                    if (!$found) {
                        throw new Exception("الخيار المحدد لمنتج غير موجود أو تم حذفه.");
                    }
                    if ($row['costPrice'] !== null) {
                        $newCost = computeWeightedAverageCost(floatval($found['stock_qty']), $found['cost_price'] !== null ? floatval($found['cost_price']) : null, $row['quantity'], $row['costPrice']);
                        $stmt = $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + ?, cost_price = ? WHERE id = ?");
                        $stmt->execute([$row['quantity'], $newCost, $row['variantId']]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + ? WHERE id = ?");
                        $stmt->execute([$row['quantity'], $row['variantId']]);
                    }
                    $label = $found['name_ar'] . ' (' . trim(($found['color_ar'] ?? '') . ' ' . ($found['size'] ?? '')) . ')';
                } else {
                    $stmtCheck = $pdo->prepare("SELECT id, name_ar, stock_qty, cost_price FROM products WHERE id = ? AND is_active = 1 FOR UPDATE");
                    $stmtCheck->execute([$row['productId']]);
                    $found = $stmtCheck->fetch();
                    if (!$found) {
                        throw new Exception("منتج برقم #{$row['productId']} غير موجود أو غير نشط.");
                    }
                    if ($row['costPrice'] !== null) {
                        $newCost = computeWeightedAverageCost(floatval($found['stock_qty']), $found['cost_price'] !== null ? floatval($found['cost_price']) : null, $row['quantity'], $row['costPrice']);
                        $stmt = $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ?, cost_price = ? WHERE id = ?");
                        $stmt->execute([$row['quantity'], $newCost, $row['productId']]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
                        $stmt->execute([$row['quantity'], $row['productId']]);
                    }
                    $label = $found['name_ar'];
                }

                $stmtMov->execute([
                    $row['productId'],
                    $row['variantId'],
                    $row['quantity'],
                    $row['costPrice'],
                    $notes ?: 'استلام توريد جديد',
                    $_SESSION['user_id']
                ]);

                $receivedSummary[] = $row['quantity'] . 'x ' . $label;
                $totalQty += $row['quantity'];
            }

            logAudit($pdo, 'receive_stock_batch', 'inventory', $rows[0]['productId'], null, [
                'items_count' => count($rows),
                'total_qty' => $totalQty,
                'notes' => $notes,
                'items' => $receivedSummary
            ]);

            $pdo->commit();
            $success = 'تم استلام الفاتورة بنجاح: ' . count($rows) . ' منتج، بإجمالي ' . $totalQty . ' قطعة.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'خطأ أثناء تسجيل المخزون: ' . $e->getMessage();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">استلام بضاعة ومخزون جديد <span class="text-muted fs-6">/ Receive Stock</span></h4>
                <p class="text-muted small mb-0">تسجيل دخول منتج واحد أو أكثر في نفس فاتورة الاستلام</p>
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

        <div class="panel">
            <div class="panel-body p-4">
                <form method="POST" action="" id="receiveForm">
                    <?= csrfField() ?>
                    <div class="table-responsive mb-3">
                        <table class="table align-middle" id="receiveItemsTable">
                            <thead>
                                <tr>
                                    <th style="min-width: 260px;">المنتج *</th>
                                    <th style="min-width: 200px;">الخيار / اللون والمقاس</th>
                                    <th style="width: 130px;">الكمية *</th>
                                    <th style="width: 150px;">سعر التكلفة للقطعة</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="receiveItemsBody">
                                <!-- صفوف الأسطر تتولد هنا بالجافاسكريبت -->
                            </tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-outline-primary btn-sm fw-bold mb-4" onclick="addReceiveRow()">
                        <i class="bi bi-plus-circle me-1"></i> إضافة منتج آخر لنفس الفاتورة
                    </button>

                    <div class="mb-4">
                        <label class="form-label fw-bold">ملاحظات / اسم المورد أو رقم الشحنة (تطبق على كل بنود الفاتورة)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="اكتب أي ملاحظات تخص هذه الدفعة..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 py-2 fw-bold">
                        <i class="bi bi-box-arrow-in-down me-1"></i> تأكيد استلام الفاتورة كاملة
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<template id="receiveRowTemplate">
    <tr class="receive-row">
        <td>
            <select name="product_id[]" class="form-select receive-product-select" required onchange="loadVariantsForRow(this)">
                <option value="">اختر المنتج...</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-has-variants="<?= $p['has_variants'] ? '1' : '0' ?>">
                        <?= e($p['name_ar']) ?> (<?= e($p['name_en']) ?>) — SKU: <?= e($p['sku'] ?: '—') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="variant_id[]" class="form-select receive-variant-select" style="display: none;">
                <option value="">—</option>
            </select>
        </td>
        <td><input type="number" step="1" min="1" name="quantity[]" class="form-control" required placeholder="مثال: 50"></td>
        <td>
            <div class="input-group">
                <span class="input-group-text">AED</span>
                <input type="number" step="0.01" name="cost_price[]" class="form-control" placeholder="0.00">
            </div>
        </td>
        <td>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeReceiveRow(this)"><i class="bi bi-trash"></i></button>
        </td>
    </tr>
</template>

<script>
function addReceiveRow() {
    const tpl = document.getElementById('receiveRowTemplate');
    const clone = tpl.content.cloneNode(true);
    document.getElementById('receiveItemsBody').appendChild(clone);
}

function removeReceiveRow(btn) {
    const tbody = document.getElementById('receiveItemsBody');
    if (tbody.querySelectorAll('.receive-row').length <= 1) return; // خلي سطر واحد على الأقل دايمًا
    btn.closest('tr').remove();
}

function loadVariantsForRow(selectEl) {
    const row = selectEl.closest('tr');
    const variantSelect = row.querySelector('.receive-variant-select');
    const productId = selectEl.value;
    const hasVariants = selectEl.selectedOptions[0] && selectEl.selectedOptions[0].dataset.hasVariants === '1';

    if (!productId || !hasVariants) {
        variantSelect.style.display = 'none';
        variantSelect.innerHTML = '<option value="">—</option>';
        variantSelect.required = false;
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
                variantSelect.style.display = 'block';
                variantSelect.required = true;
            } else {
                variantSelect.style.display = 'none';
                variantSelect.required = false;
            }
        });
}

// أول سطر بيتولد تلقائي عند فتح الصفحة
document.addEventListener('DOMContentLoaded', addReceiveRow);
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
