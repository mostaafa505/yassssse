<?php
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'Invalid request']));
}

requireAuth();

if (!verifyCsrf()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.']));
}

// أدمن ومحاسب دايمًا مسموح لهم، وأي حد تاني (زي الكاشير) لو المدير اديه
// صلاحية "إلغاء فواتير البيع" المخصصة من شاشة المستخدمين
$canVoid = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('void_invoice');
if (!$canVoid) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'غير مصرح لك بإلغاء الفواتير']));
}

$saleId = intval($_POST['sale_id'] ?? 0);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();

    if (!$sale || $sale['status'] !== 'completed') {
        throw new Exception("الفاتورة غير صالحة للإلغاء أو تم إلغاؤها مسبقاً.");
    }

    // الكاشير (مش أدمن/محاسب) يقدر بس يلغي فواتيره هو، حتى لو معاه الصلاحية
    if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) {
        throw new Exception("تقدر تلغي فواتيرك انت بس.");
    }

    // إلغاء الفاتورة كاملة = إرجاع كل بنودها بالكامل، بنفس المسار الموحّد
    // اللي بيستخدمه sales/return.php بالظبط (processReturn في config.php)،
    // عشان يترجّع المخزون + يتسجل صف في returns/return_items/inventory_movements
    // بنفس الطريقة تمامًا — قبل كده كانت الدالة دي بترجع المخزون بس من غير
    // ما تسجل صف في returns، فالتقارير المالية كانت بتفوّت قيمة الفواتير
    // المُلغاة تمامًا من حساب "إجمالي المرتجعات".
    $itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $itemsStmt->execute([$saleId]);
    $items = $itemsStmt->fetchAll();

    $reason = trim($_POST['reason'] ?? '') ?: 'إلغاء الفاتورة بالكامل';
    $itemsForReturn = array_map(fn($item) => [
        'sale_item_id'    => $item['id'],
        'product_id'      => $item['product_id'],
        'variant_id'      => $item['variant_id'],
        'product_name_ar' => $item['product_name_ar'],
        'unit_price'      => $item['unit_price'],
        'qty'             => $item['quantity'],
    ], $items);

    // إلغاء كامل مفيهوش شاشة مخصصة تسأل عن طريقة الرد زي sales/return.php،
    // فبنفترض إن المبلغ بيترد بنفس طريقة الدفع الأصلية للفاتورة (الافتراض
    // الأقرب للواقع) — إلا لو الفاتورة كانت "متعددة طرق دفع" (mixed)، وقتها
    // مفيش طريقة واحدة واضحة نفترضها، فبتسجل بدون طريقة محددة (يحتاج مراجعة
    // يدوية من الأدمن عند التسوية)
    $refundMethod = in_array($sale['payment_method'], ['cash', 'card', 'transfer']) ? $sale['payment_method'] : null;

    $result = processReturn($pdo, $saleId, $sale['invoice_number'], $itemsForReturn, $reason, $_SESSION['user_id'], 'إلغاء فاتورة كاملة', $refundMethod);

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
        'cancelled_by' => $_SESSION['user_name_ar'] ?? 'المدير'
    ]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
