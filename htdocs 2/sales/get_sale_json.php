<?php
/**
 * get_sale_json.php — بيانات فاتورة (مع بنودها) كـ JSON.
 * الهدف: تخزينها في كاش الـ Service Worker وقت ما الكاشير يفتح شاشة الفواتير
 * وهو أونلاين، عشان تبقى متاحة له بعد كده حتى لو قفل النت — من غير كده
 * مفيش طريقة تعمل "إرجاع" أوفلاين لأنك محتاج تعرف بنود الفاتورة أصلاً.
 */
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$saleId = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT s.*, u.name_ar as cashier_name_ar FROM sales s LEFT JOIN users u ON s.cashier_id = u.id WHERE s.id = ?");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    echo json_encode(['success' => false, 'error' => 'الفاتورة غير موجودة']);
    exit;
}

// نفس قيود الصلاحية بتاعة الإرجاع/الإلغاء: أدمن ومحاسب دايمًا، وأي حد تاني
// لازم يكون عنده صلاحية void_invoice ويكون هو صاحب الفاتورة
$canVoid = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('void_invoice');
if (!$canVoid || (!in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بالوصول لبيانات الفاتورة دي']);
    exit;
}

$itemsStmt = $pdo->prepare("
    SELECT si.id as sale_item_id, si.product_id, si.variant_id, si.product_name_ar, si.color_ar, si.size,
    si.quantity, si.unit_price,
    COALESCE((
        SELECT SUM(ri.quantity) FROM return_items ri JOIN returns r ON ri.return_id = r.id WHERE ri.sale_item_id = si.id
    ), 0) as already_returned
    FROM sale_items si WHERE si.sale_id = ?
");
$itemsStmt->execute([$saleId]);
$items = $itemsStmt->fetchAll();

echo json_encode([
    'success' => true,
    'sale' => [
        'id' => (int)$sale['id'],
        'invoice_number' => $sale['invoice_number'],
        'status' => $sale['status'],
        'total' => (float)$sale['total'],
        'cashier_id' => $sale['cashier_id'] ? (int)$sale['cashier_id'] : null,
        'cashier_name_ar' => $sale['cashier_name_ar'],
    ],
    'items' => array_map(function($it) {
        return [
            'sale_item_id' => (int)$it['sale_item_id'],
            'product_id' => $it['product_id'] ? (int)$it['product_id'] : null,
            'variant_id' => $it['variant_id'] ? (int)$it['variant_id'] : null,
            'product_name_ar' => $it['product_name_ar'],
            'color_ar' => $it['color_ar'],
            'size' => $it['size'],
            'quantity' => (float)$it['quantity'],
            'unit_price' => (float)$it['unit_price'],
            'already_returned' => (float)$it['already_returned'],
        ];
    }, $items),
], JSON_UNESCAPED_UNICODE);
