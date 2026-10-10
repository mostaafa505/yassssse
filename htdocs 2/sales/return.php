<?php
$pageTitle = 'إرجاع فاتورة / Process Return';
require_once dirname(__DIR__) . '/includes/header.php';

requireAuth();

// أدمن ومحاسب دايمًا مسموح لهم، وأي حد تاني (زي الكاشير) لو المدير اديه
// صلاحية "إلغاء فواتير البيع" المخصصة من شاشة المستخدمين
$canVoid = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('void_invoice');
if (!$canVoid) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;"><h2>غير مصرح</h2><p>ليس لديك صلاحية إرجاع الفواتير.</p><a href="' . BASE_URL . '/sales/">العودة للفواتير</a></div>');
}

$saleId = intval($_GET['id'] ?? $_POST['sale_id'] ?? 0);
$stmt = $pdo->prepare("SELECT s.*, u.name_ar as cashier_name_ar FROM sales s LEFT JOIN users u ON s.cashier_id = u.id WHERE s.id = ?");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    redirect('sales/index.php');
}

// الكاشير (مش أدمن/محاسب) يقدر بس يرجّع فواتيره هو، حتى لو معاه الصلاحية
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;text-align:center;padding:60px;"><h2>غير مصرح</h2><p>تقدر ترجّع فواتيرك انت بس.</p><a href="' . BASE_URL . '/sales/">العودة للفواتير</a></div>');
}

$error = '';
$success = '';

// بنود الفاتورة + الكمية المرتجعة من كل بند قبل كده (لو حصل مرتجع جزئي سابق)
function getSaleItemsWithReturned(PDO $pdo, int $saleId): array {
    $stmt = $pdo->prepare("
        SELECT si.*, COALESCE((
            SELECT SUM(ri.quantity) FROM return_items ri
            JOIN returns r ON ri.return_id = r.id
            WHERE ri.sale_item_id = si.id
        ), 0) as already_returned
        FROM sale_items si
        WHERE si.sale_id = ?
    ");
    $stmt->execute([$saleId]);
    return $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($sale['status'] === 'returned') {
        $error = 'الفاتورة دي كلها مرتجعة بالفعل، مفيش حاجة تانية ترجعها.';
    } else {
        $reason = trim($_POST['reason'] ?? '');
        $qtys = $_POST['return_qty'] ?? []; // sale_item_id => qty
        $refundMethod = in_array($_POST['refund_method'] ?? '', ['cash', 'card', 'transfer']) ? $_POST['refund_method'] : null;

        if ($reason === '') {
            $error = 'يرجى كتابة سبب الإرجاع.';
        } elseif ($refundMethod === null) {
            $error = 'يرجى تحديد طريقة رد المبلغ للعميل.';
        } else {
            $items = getSaleItemsWithReturned($pdo, $saleId);
            $toReturn = [];
            foreach ($items as $it) {
                $reqQty = floatval($qtys[$it['id']] ?? 0);
                if ($reqQty <= 0) continue;
                $maxReturnable = floatval($it['quantity']) - floatval($it['already_returned']);
                if ($reqQty > $maxReturnable) {
                    $error = "الكمية المطلوب إرجاعها من \"{$it['product_name_ar']}\" أكبر من المتاح للإرجاع ({$maxReturnable}).";
                    break;
                }
                $toReturn[] = ['item' => $it, 'qty' => $reqQty];
            }

            if (!$error && empty($toReturn)) {
                $error = 'يرجى تحديد كمية صنف واحد على الأقل لإرجاعه.';
            }

            if (!$error) {
                try {
                    $pdo->beginTransaction();

                    $itemsForReturn = array_map(fn($r) => [
                        'sale_item_id'   => $r['item']['id'],
                        'product_id'     => $r['item']['product_id'],
                        'variant_id'     => $r['item']['variant_id'],
                        'product_name_ar'=> $r['item']['product_name_ar'],
                        'unit_price'     => $r['item']['unit_price'],
                        'qty'            => $r['qty'],
                    ], $toReturn);

                    $result = processReturn($pdo, $saleId, $sale['invoice_number'], $itemsForReturn, $reason, $_SESSION['user_id'], 'مرتجع من فاتورة', $refundMethod);

                    logAudit($pdo, 'process_return', 'sale', $saleId, [
                        'invoice_number' => $sale['invoice_number'],
                        'status' => $sale['status'],
                    ], [
                        'invoice_number' => $sale['invoice_number'],
                        'status' => $result['new_status'],
                        'reason' => $reason,
                        'refund_amount' => $result['refund_total'],
                        'refund_method' => $result['refund_method'],
                        'returned_items' => $result['returned_summary'],
                        'processed_by' => $_SESSION['user_name_ar'] ?? '',
                    ]);

                    $pdo->commit();
                    $fullyReturned = $result['new_status'] === 'returned';
                    $success = "تم تسجيل المرتجع بنجاح بقيمة " . money($result['refund_total']) . ". حالة الفاتورة الآن: " . ($fullyReturned ? 'مرتجعة بالكامل' : 'مرتجع جزئي');
                    $sale['status'] = $result['new_status'];
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'خطأ أثناء تسجيل المرتجع: ' . $e->getMessage();
                }
            }
        }
    }
}

$items = getSaleItemsWithReturned($pdo, $saleId);
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">إرجاع فاتورة <span class="text-muted fs-6">#<?= e($sale['invoice_number']) ?></span></h4>
                <p class="text-muted small mb-0">حدد الأصناف والكميات المطلوب إرجاعها وسبب الإرجاع — يتم إرجاع المخزون تلقائياً</p>
            </div>
            <a href="<?= BASE_URL ?>/sales/" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-arrow-right me-1"></i> العودة للفواتير
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-4" role="alert"><i class="bi bi-exclamation-triangle-fill ms-2"></i><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success py-2 mb-4" role="alert"><i class="bi bi-check-circle-fill ms-2"></i><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($sale['status'] === 'returned'): ?>
            <div class="alert alert-secondary py-2 mb-4">هذه الفاتورة مرتجعة بالكامل بالفعل.</div>
        <?php else: ?>
        <div class="panel mb-4">
            <div class="panel-body p-4">
                <form method="POST" action="">
                    <?= csrfField() ?>
                    <input type="hidden" name="sale_id" value="<?= $saleId ?>">
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>الصنف</th>
                                    <th>الكمية المباعة</th>
                                    <th>مرتجع سابقاً</th>
                                    <th>سعر الوحدة</th>
                                    <th style="width:140px;">الكمية المرتجعة الآن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <?php $remaining = floatval($it['quantity']) - floatval($it['already_returned']); ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($it['product_name_ar']) ?></strong>
                                            <?php if ($it['color_ar'] || $it['size']): ?>
                                                <div class="small text-muted"><?= e($it['color_ar']) ?> <?= e($it['size']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="num"><?= (float)$it['quantity'] ?></td>
                                        <td class="num"><?= (float)$it['already_returned'] ?></td>
                                        <td class="num"><?= money($it['unit_price']) ?></td>
                                        <td>
                                            <?php if ($remaining > 0): ?>
                                                <input type="number" step="1" min="0" max="<?= $remaining ?>" name="return_qty[<?= $it['id'] ?>]" class="form-control form-control-sm" placeholder="0">
                                            <?php else: ?>
                                                <span class="badge status-inactive">اترجع بالكامل</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">سبب الإرجاع *</label>
                            <textarea name="reason" class="form-control" rows="2" required placeholder="مثال: مقاس غير مناسب / عيب تصنيع / رغبة العميل..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">طريقة رد المبلغ للعميل *</label>
                            <select name="refund_method" class="form-select" required>
                                <option value="">اختر...</option>
                                <option value="cash" <?= $sale['payment_method'] === 'cash' ? 'selected' : '' ?>>نقد (كاش)</option>
                                <option value="card" <?= $sale['payment_method'] === 'card' ? 'selected' : '' ?>>بطاقة بنكية</option>
                                <option value="transfer" <?= $sale['payment_method'] === 'transfer' ? 'selected' : '' ?>>تحويل بنكي</option>
                            </select>
                            <div class="form-text">الافتراضي هو نفس طريقة دفع الفاتورة الأصلية، وتقدر تغيّره لو رد المبلغ اتم بطريقة مختلفة.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger px-5 py-2 fw-bold">
                        <i class="bi bi-arrow-return-left me-1"></i> تأكيد الإرجاع
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
