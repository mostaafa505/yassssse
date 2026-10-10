<?php
$pageTitle = 'جرد المخزون / Stocktaking';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'stock'], 'manage_inventory');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['counts']) && !verifyCsrf()) {
    $message = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['counts'])) {
    try {
        $pdo->beginTransaction();

        $adjustedCount = 0;
        foreach ($_POST['counts'] as $item) {
            $prodId = intval($item['product_id']);
            $varId = !empty($item['variant_id']) ? intval($item['variant_id']) : null;
            $physicalCount = floatval($item['actual_qty'] ?? 0);
            // "system_qty" هنا هو رصيد الشاشة وقت ما الجرد اتفتح — مش بنثق بيه
            // كقيمة مطلقة (ممكن يكون قديم لو الجرد استغرق وقت)، بس بنستخدمه
            // كنقطة مرجعية بس عشان نحسب "الفرق اللي العدّاد لقاه فعليًا" (تلف/
            // سرقة/خطأ عدّ سابق)، مستقل عن أي بيع أو مرتجع حصل في نفس الوقت.
            $systemQtyAtSheetTime = floatval($item['system_qty'] ?? 0);
            $countedDelta = $physicalCount - $systemQtyAtSheetTime;

            if ($countedDelta == 0) continue;

            // نقفل الصف ونجيب الرصيد الحي دلوقتي (مش اللي كان وقت فتح الصفحة)،
            // وبعدين نطبق فرق العدّ عليه كتعديل نسبي — مش استبدال مطلق. كده لو
            // حصل بيع أو مرتجع حقيقي أثناء الجرد، أثره يفضل موجود، والجرد
            // بيصحح بس الفرق اللي هو فعلاً لقاه (تلف/سرقة)، مش بيمسح حركة
            // البيع والشرا اللي حصلت في نفس الفترة.
            if ($varId) {
                $stmtLock = $pdo->prepare("SELECT stock_qty FROM product_variants WHERE id = ? FOR UPDATE");
                $stmtLock->execute([$varId]);
                $liveQty = $stmtLock->fetchColumn();
                if ($liveQty === false) continue; // الخيار اتمسح من وقت ما الجرد اتفتح
                $newQty = floatval($liveQty) + $countedDelta;
                $pdo->prepare("UPDATE product_variants SET stock_qty = ? WHERE id = ?")->execute([$newQty, $varId]);
            } else {
                $stmtLock = $pdo->prepare("SELECT stock_qty FROM products WHERE id = ? FOR UPDATE");
                $stmtLock->execute([$prodId]);
                $liveQty = $stmtLock->fetchColumn();
                if ($liveQty === false) continue; // المنتج اتمسح من وقت ما الجرد اتفتح
                $newQty = floatval($liveQty) + $countedDelta;
                $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?")->execute([$newQty, $prodId]);
            }

            $pdo->prepare("
                INSERT INTO inventory_movements (product_id, variant_id, movement_type, quantity, notes, user_id)
                VALUES (?, ?, 'adjustment', ?, ?, ?)
            ")->execute([
                $prodId, $varId, abs($countedDelta),
                "تسوية جردية (الفارق اللي لقاه العدّ: " . ($countedDelta > 0 ? "+$countedDelta" : "$countedDelta") . ", الرصيد الحي وقت الحفظ: {$liveQty}، الرصيد الجديد: {$newQty})",
                $_SESSION['user_id']
            ]);
            $adjustedCount++;
        }

        logAudit($pdo, 'stocktake', 'inventory', 0, null, ['items_counted' => count($_POST['counts']), 'items_adjusted' => $adjustedCount]);

        $pdo->commit();
        $message = 'تم حفظ الجرد وتسوية فروقات المخزون بنجاح!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'خطأ أثناء حفظ الجرد: ' . $e->getMessage();
    }
}

// Fetch all items
$products = $pdo->query("SELECT * FROM products WHERE is_active = 1 ORDER BY name_ar ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">جرد المخزون الفعلي <span class="text-muted fs-6">/ Physical Stocktake</span></h4>
        <p class="text-muted small mb-0">مطابقة الجرد الفعلي للمحل مع أرصدة النظام وتسوية الفروقات آلياً</p>
    </div>
    <a href="<?= BASE_URL ?>/inventory/" class="btn btn-outline-secondary rounded-pill px-3">
        <i class="bi bi-arrow-right me-1"></i> العودة للمخزون
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-info py-2 mb-4" role="alert"><i class="bi bi-info-circle-fill ms-2"></i><?= e($message) ?></div>
<?php endif; ?>

<form method="POST" action="">
    <?= csrfField() ?>
    <div class="panel  mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>المنتج</th>
                        <th>الخيار (اللون/المقاس)</th>
                        <th>الرصيد الدفتري بالنظام</th>
                        <th style="width: 180px;">الرصيد الفعلي بعد العدّ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $idx = 0;
                    foreach ($products as $p): 
                        if ($p['has_variants']):
                            $stmtV = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ?");
                            $stmtV->execute([$p['id']]);
                            $vars = $stmtV->fetchAll();
                            foreach ($vars as $v):
                    ?>
                                <tr>
                                    <td><strong><?= e($p['name_ar']) ?></strong></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($v['color_ar']) ?> <?= e($v['size']) ?></span></td>
                                    <td><strong class="text-primary"><?= $v['stock_qty'] ?></strong></td>
                                    <td>
                                        <input type="hidden" name="counts[<?= $idx ?>][product_id]" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="counts[<?= $idx ?>][variant_id]" value="<?= $v['id'] ?>">
                                        <input type="hidden" name="counts[<?= $idx ?>][system_qty]" value="<?= $v['stock_qty'] ?>">
                                        <input type="number" step="1" name="counts[<?= $idx ?>][actual_qty]" class="form-control form-control-sm text-center fw-bold" value="<?= $v['stock_qty'] ?>">
                                    </td>
                                </tr>
                    <?php 
                                $idx++;
                            endforeach;
                        else:
                    ?>
                            <tr>
                                <td><strong><?= e($p['name_ar']) ?></strong></td>
                                <td><span class="text-muted small">بدون خيارات</span></td>
                                <td><strong class="text-primary"><?= $p['stock_qty'] ?></strong></td>
                                <td>
                                    <input type="hidden" name="counts[<?= $idx ?>][product_id]" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="counts[<?= $idx ?>][variant_id]" value="">
                                    <input type="hidden" name="counts[<?= $idx ?>][system_qty]" value="<?= $p['stock_qty'] ?>">
                                    <input type="number" step="1" name="counts[<?= $idx ?>][actual_qty]" class="form-control form-control-sm text-center fw-bold" value="<?= $p['stock_qty'] ?>">
                                </td>
                            </tr>
                    <?php 
                            $idx++;
                        endif;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-5">
        <button type="submit" class="btn btn-success px-5 py-2 fw-bold" onclick="return confirm('هل أنت متأكد من حفظ الجرد وتسوية الفروقات؟')">
            <i class="bi bi-check2-all me-1"></i> اعتماد وتسوية الجرد
        </button>
    </div>
</form>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
