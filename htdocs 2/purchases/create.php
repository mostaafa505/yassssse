<?php
$pageTitle = 'فاتورة مشتريات جديدة / New Purchase Invoice';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'accountant'], 'manage_treasury');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoiceType = in_array($_POST['invoice_type'] ?? '', ['goods', 'service'], true) ? $_POST['invoice_type'] : 'goods';
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $invoiceRef = trim($_POST['invoice_ref'] ?? '');
    $purchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
    $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash', 'card', 'transfer'], true) ? $_POST['payment_method'] : 'cash';
    $notes = trim($_POST['notes'] ?? '');

    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitPrices = $_POST['unit_price'] ?? [];

    $rows = [];
    foreach ($descriptions as $i => $desc) {
        $desc = trim($desc);
        $qty = floatval($quantities[$i] ?? 0);
        $unitPrice = floatval($unitPrices[$i] ?? 0);

        if ($desc === '' && $qty <= 0 && $unitPrice <= 0) continue; // صف فاضي تمامًا، تجاهله

        if ($desc === '' || $qty <= 0) {
            $error = 'كل بند مضاف لازم يكون له بيان وكمية صالحة أكبر من صفر.';
            $rows = [];
            break;
        }

        $rows[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $unitPrice, 'line_total' => round($qty * $unitPrice, 2)];
    }

    if (!$error && empty($rows)) {
        $error = 'يرجى إضافة بند واحد على الأقل بكمية صالحة.';
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            $totalAmount = array_sum(array_column($rows, 'line_total'));

            $stmt = $pdo->prepare("
                INSERT INTO purchase_invoices (invoice_type, supplier_name, invoice_ref, total_amount, payment_method, purchase_date, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $invoiceType,
                $supplierName ?: null,
                $invoiceRef ?: null,
                $totalAmount,
                $paymentMethod,
                $purchaseDate,
                $notes ?: null,
                $_SESSION['user_id'] ?? null
            ]);
            $invoiceId = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO purchase_invoice_items (purchase_invoice_id, description, quantity, unit_price, line_total)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($rows as $row) {
                $stmtItem->execute([$invoiceId, $row['description'], $row['quantity'], $row['unit_price'], $row['line_total']]);
            }

            $treasuryType = $invoiceType === 'goods' ? 'purchase_goods' : 'purchase_service';
            $treasuryTitle = 'فاتورة مشتريات (' . ($invoiceType === 'goods' ? 'بضاعة' : 'مواد/خدمات') . '): ' . ($supplierName ?: 'بدون مورد محدد');

            $stmtTreasury = $pdo->prepare("
                INSERT INTO treasury_transactions (type, title, amount, payment_method, reference_type, reference_id, transaction_date, notes, created_by)
                VALUES (?, ?, ?, ?, 'purchase_invoice', ?, ?, ?, ?)
            ");
            $stmtTreasury->execute([
                $treasuryType, $treasuryTitle, $totalAmount, $paymentMethod, $invoiceId, $purchaseDate, $notes ?: null, $_SESSION['user_id'] ?? null
            ]);

            logAudit($pdo, 'add_purchase_invoice', 'purchase_invoice', $invoiceId, null, [
                'invoice_type' => $invoiceType,
                'supplier_name' => $supplierName,
                'total_amount' => $totalAmount,
                'items_count' => count($rows)
            ]);

            $pdo->commit();
            redirect('purchases/index.php?created=1');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'خطأ أثناء تسجيل فاتورة المشتريات: ' . $e->getMessage();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">فاتورة مشتريات جديدة <span class="text-muted fs-6">/ New Purchase Invoice</span></h4>
                <p class="text-muted small mb-0">تسجيل فاتورة شراء بضاعة أو مواد وخدمات تشغيلية — يتم خصمها تلقائيًا من الصندوق الرئيسي</p>
            </div>
            <a href="<?= BASE_URL ?>/purchases/" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-arrow-right me-1"></i> العودة للمشتريات
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-4" role="alert"><i class="bi bi-exclamation-triangle-fill ms-2"></i><?= e($error) ?></div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-body p-4">
                <form method="POST" action="" id="purchaseForm">
                    <?= csrfField() ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">نوع الفاتورة <span class="text-danger">*</span></label>
                            <select name="invoice_type" class="form-select" required>
                                <option value="goods">بضاعة / مخزون</option>
                                <option value="service">مواد ومستلزمات خدمية</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label fw-bold">اسم المورد</label>
                            <input type="text" name="supplier_name" class="form-control" placeholder="اختياري">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label fw-bold">رقم فاتورة المورد</label>
                            <input type="text" name="invoice_ref" class="form-control" placeholder="اختياري">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label fw-bold">تاريخ الشراء</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-2 col-12">
                            <label class="form-label fw-bold">طريقة الدفع</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash">كاش نقدي</option>
                                <option value="card">بطاقة (شبكة)</option>
                                <option value="transfer">تحويل بنكي</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table align-middle" id="purchaseItemsTable">
                            <thead>
                                <tr>
                                    <th style="min-width: 280px;">بيان الصنف / الخدمة *</th>
                                    <th style="width: 120px;">الكمية *</th>
                                    <th style="width: 160px;">سعر الوحدة</th>
                                    <th style="width: 160px;">الإجمالي</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="purchaseItemsBody"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-start fw-bold">الإجمالي الكلي للفاتورة:</td>
                                    <td class="fw-bold num text-danger fs-6" id="purchaseGrandTotal">0.00 AED</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <button type="button" class="btn btn-outline-primary btn-sm fw-bold mb-4" onclick="addPurchaseRow()">
                        <i class="bi bi-plus-circle me-1"></i> إضافة بند آخر لنفس الفاتورة
                    </button>

                    <div class="mb-4">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="أي ملاحظات إضافية عن الفاتورة..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 py-2 fw-bold">
                        <i class="bi bi-receipt-cutoff me-1"></i> حفظ فاتورة المشتريات
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<template id="purchaseRowTemplate">
    <tr class="purchase-row">
        <td><input type="text" name="description[]" class="form-control purchase-desc" placeholder="مثال: أكياس تغليف، اشتراك إنترنت..." required></td>
        <td><input type="number" step="0.01" min="0.01" name="quantity[]" class="form-control purchase-qty" value="1" required oninput="recalcPurchaseRow(this)"></td>
        <td>
            <div class="input-group">
                <span class="input-group-text">AED</span>
                <input type="number" step="0.01" min="0" name="unit_price[]" class="form-control purchase-price" placeholder="0.00" oninput="recalcPurchaseRow(this)">
            </div>
        </td>
        <td class="num fw-bold purchase-line-total">0.00</td>
        <td>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removePurchaseRow(this)"><i class="bi bi-trash"></i></button>
        </td>
    </tr>
</template>

<script>
function addPurchaseRow() {
    const tpl = document.getElementById('purchaseRowTemplate');
    const clone = tpl.content.cloneNode(true);
    document.getElementById('purchaseItemsBody').appendChild(clone);
}

function removePurchaseRow(btn) {
    const tbody = document.getElementById('purchaseItemsBody');
    if (tbody.querySelectorAll('.purchase-row').length <= 1) return;
    btn.closest('tr').remove();
    recalcPurchaseGrandTotal();
}

function recalcPurchaseRow(el) {
    const row = el.closest('tr');
    const qty = parseFloat(row.querySelector('.purchase-qty').value) || 0;
    const price = parseFloat(row.querySelector('.purchase-price').value) || 0;
    const lineTotal = qty * price;
    row.querySelector('.purchase-line-total').textContent = lineTotal.toFixed(2);
    recalcPurchaseGrandTotal();
}

function recalcPurchaseGrandTotal() {
    let total = 0;
    document.querySelectorAll('#purchaseItemsBody .purchase-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.purchase-qty').value) || 0;
        const price = parseFloat(row.querySelector('.purchase-price').value) || 0;
        total += qty * price;
    });
    document.getElementById('purchaseGrandTotal').textContent = total.toFixed(2) + ' AED';
}

document.addEventListener('DOMContentLoaded', addPurchaseRow);
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
