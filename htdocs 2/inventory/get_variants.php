<?php
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$productId = intval($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['success' => false, 'error' => 'رقم منتج غير صالح']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, color_ar, color_en, size, stock_qty FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$productId]);
$variants = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'variants' => array_map(function($v) {
        return [
            'id' => $v['id'],
            'color_ar' => $v['color_ar'],
            'color_en' => $v['color_en'],
            'size' => $v['size'],
            'stock_qty' => floatval($v['stock_qty'])
        ];
    }, $variants)
]);
