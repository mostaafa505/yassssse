<?php
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'معرف غير صالح']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    echo json_encode(['success' => false, 'error' => 'المنتج غير موجود']);
    exit;
}

$pdo->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$id]);

logAudit($pdo, 'delete', 'product', $id, $product, null);

echo json_encode(['success' => true]);
