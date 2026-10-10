<?php
/**
 * sync_offline_actions.php — يستقبل طلبات إلغاء/إرجاع الفواتير اللي اتعملت
 * أوفلاين (من IndexedDB) ويطبّقها على السيرفر. نفس منطق sales/cancel.php
 * و sales/return.php بالظبط، بس بيشتغل على دفعة (batch) بدل طلب واحد،
 * ومحمي من التكرار عن طريق إعادة التحقق من حالة الفاتورة الحالية في قاعدة
 * البيانات في كل مرة (مش علم/فلاج مخزّن) — فلو نفس الطلب اتبعت مرتين
 * (تعارض مزامنة، إعادة محاولة)، المحاولة التانية هترفض تلقائيًا لأن الكمية
 * المتاحة للإرجاع أو حالة الفاتورة هتكون اتغيرت بالفعل من المحاولة الأولى.
 */
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'], ['admin', 'cashier', 'accountant'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

if (!verifyCsrf()) {
    // نفس مبدأ sync_offline.php — رفض بدون throw عشان الطلبات المعلقة تفضل
    // محفوظة وتتحاول تاني، مش تتمسح
    echo json_encode(['success' => false, 'error' => 'رمز الحماية غير صالح، سيُعاد المحاولة لاحقاً']);
    exit;
}

$canVoid = in_array($_SESSION['user_role'], ['admin', 'accountant']) || hasPermission('void_invoice');

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['actions'])) {
    echo json_encode(['success' => true, 'synced_ids' => [], 'failed' => 0]);
    exit;
}

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

$syncedIds = [];
$failed = 0;
$failedReasons = [];

foreach ($input['actions'] as $action) {
    $localId = $action['local_id'] ?? 0;
    $saleId = intval($action['sale_id'] ?? 0);
    $type = in_array($action['type'] ?? '', ['cancel', 'return']) ? $action['type'] : null;

    try {
        if (!$canVoid || !$type || $saleId <= 0) {
            throw new Exception('غير مصرح أو بيانات ناقصة');
        }

        $stmtSale = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
        $stmtSale->execute([$saleId]);
        $sale = $stmtSale->fetch();
        if (!$sale) {
            throw new Exception('الفاتورة غير موجودة');
        }

        // كاشير (مش أدمن/محاسب) يقدر يلغي/يرجّع فواتيره هو بس
        if (!in_array($_SESSION['user_role'], ['admin', 'accountant']) && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) {
            throw new Exception('تقدر تلغي/ترجّع فواتيرك انت بس');
        }

        if ($type === 'cancel') {
            if ($sale['status'] !== 'completed') {
                // اتلغت بالفعل من محاولة سابقة (أو مش قابلة للإلغاء) — اعتبرها متزامنة
                $syncedIds[] = $localId;
                continue;
            }

            $pdo->beginTransaction();

            // نفس المسار الموحّد بالظبط اللي بيستخدمه sales/cancel.php
            // وsales/return.php (processReturn في config.php)
            $itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $itemsStmt->execute([$saleId]);
            $items = $itemsStmt->fetchAll();

            $reason = trim($action['reason'] ?? '') ?: 'إلغاء الفاتورة بالكامل — أوفلاين';
            $itemsForReturn = array_map(fn($item) => [
                'sale_item_id'    => $item['id'],
                'product_id'      => $item['product_id'],
                'variant_id'      => $item['variant_id'],
                'product_name_ar' => $item['product_name_ar'],
                'unit_price'      => $item['unit_price'],
                'qty'             => $item['quantity'],
            ], $items);

            // نفس منطق sales/cancel.php: طريقة الرد الافتراضية هي طريقة الدفع
            // الأصلية للفاتورة، إلا لو كانت "متعددة" فمفيش طريقة واضحة
            $refundMethod = in_array($action['refund_method'] ?? '', ['cash', 'card', 'transfer'])
                ? $action['refund_method']
                : (in_array($sale['payment_method'], ['cash', 'card', 'transfer']) ? $sale['payment_method'] : null);

            $result = processReturn($pdo, $saleId, $sale['invoice_number'], $itemsForReturn, $reason, $_SESSION['user_id'], 'إلغاء فاتورة كاملة أوفلاين', $refundMethod);

            logAudit($pdo, 'cancel_sale', 'sale', $saleId, [
                'invoice_number' => $sale['invoice_number'],
                'status' => 'completed',
                'total' => $sale['total']
            ], [
                'invoice_number' => $sale['invoice_number'],
                'status' => $result['new_status'],
                'refund_amount' => $result['refund_total'],
                'refund_method' => $result['refund_method'],
                'items_count' => count($items),
                'returned_items' => $result['returned_summary'],
                'cancelled_by' => $_SESSION['user_name_ar'] ?? '',
                'synced_from_offline' => true,
            ]);

            $pdo->commit();
            $syncedIds[] = $localId;
        } else {
            // type === 'return' (مرتجع جزئي)
            if ($sale['status'] === 'returned') {
                $syncedIds[] = $localId;
                continue;
            }

            $reason = trim($action['reason'] ?? '');
            $qtys = $action['items'] ?? []; // [{sale_item_id, qty}]
            if ($reason === '' || empty($qtys)) {
                throw new Exception('بيانات المرتجع ناقصة');
            }

            $allItems = getSaleItemsWithReturned($pdo, $saleId);
            $itemsById = [];
            foreach ($allItems as $it) { $itemsById[$it['id']] = $it; }

            $toReturn = [];
            foreach ($qtys as $q) {
                $siId = intval($q['sale_item_id'] ?? 0);
                $reqQty = floatval($q['qty'] ?? 0);
                if ($reqQty <= 0 || !isset($itemsById[$siId])) continue;
                $it = $itemsById[$siId];
                $maxReturnable = floatval($it['quantity']) - floatval($it['already_returned']);
                if ($reqQty > $maxReturnable) {
                    // اتغيرت الكمية المتاحة (اتعمل جزء منها قبل كده) — رجّع اللي متاح بس
                    $reqQty = $maxReturnable;
                }
                if ($reqQty > 0) $toReturn[] = ['item' => $it, 'qty' => $reqQty];
            }

            if (empty($toReturn)) {
                // مفيش حاجة متاحة ترجع (اترجعت كلها في محاولة سابقة) — اعتبرها متزامنة
                $syncedIds[] = $localId;
                continue;
            }

            $pdo->beginTransaction();

            $itemsForReturn = array_map(fn($r) => [
                'sale_item_id'    => $r['item']['id'],
                'product_id'      => $r['item']['product_id'],
                'variant_id'      => $r['item']['variant_id'],
                'product_name_ar' => $r['item']['product_name_ar'],
                'unit_price'      => $r['item']['unit_price'],
                'qty'             => $r['qty'],
            ], $toReturn);

            $refundMethod = in_array($action['refund_method'] ?? '', ['cash', 'card', 'transfer'])
                ? $action['refund_method']
                : (in_array($sale['payment_method'], ['cash', 'card', 'transfer']) ? $sale['payment_method'] : null);

            $result = processReturn($pdo, $saleId, $sale['invoice_number'], $itemsForReturn, $reason, $_SESSION['user_id'], 'مرتجع أوفلاين من فاتورة', $refundMethod);

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
                'synced_from_offline' => true,
            ]);

            $pdo->commit();
            $syncedIds[] = $localId;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed++;
        $failedReasons[] = $e->getMessage();
    }
}

echo json_encode([
    'success' => true,
    'synced_ids' => $syncedIds,
    'failed' => $failed,
    'failed_reasons' => $failedReasons,
]);
