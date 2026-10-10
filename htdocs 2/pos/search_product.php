<?php
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$activeOffers = getActiveOffers($pdo);

$barcode = trim($_GET['barcode'] ?? '');
if ($barcode !== '') {
    // 1. Direct match on main product
    $stmtP = $pdo->prepare("
        SELECT p.*, c.name_ar as category_name_ar, c.name_en as category_name_en
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE (p.barcode = ? OR p.sku = ?) AND p.is_active = 1
        LIMIT 1
    ");
    $stmtP->execute([$barcode, $barcode]);
    $p = $stmtP->fetch();

    if ($p) {
        $catId = $p['category_id'] !== null ? (int)$p['category_id'] : null;
        $variants = [];
        if ($p['has_variants']) {
            $stmtV = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1");
            $stmtV->execute([$p['id']]);
            $variants = $stmtV->fetchAll();
            // منتج بخيارات: يظهر بس لو فيه خيار واحد على الأقل عنده سعر فعلي
            $variants = array_values(array_filter($variants, function($v) use ($p) {
                $eff = $v['retail_price'] ? floatval($v['retail_price']) : floatval($p['retail_price']);
                return $eff > 0;
            }));
            if (empty($variants)) {
                echo json_encode(['success' => false, 'error' => 'هذا المنتج غير مسعّر بعد، لا يمكن بيعه']);
                exit;
            }
        } elseif (floatval($p['retail_price']) <= 0) {
            echo json_encode(['success' => false, 'error' => 'هذا المنتج غير مسعّر بعد، لا يمكن بيعه']);
            exit;
        }
        $pOffer = computeOfferPrice($activeOffers, floatval($p['retail_price']), (int)$p['id'], $catId)
            ?? ($p['offer_price'] ? floatval($p['offer_price']) : null);
        $prodData = [
            'id' => $p['id'],
            'category_id' => $catId,
            'name_ar' => $p['name_ar'],
            'name_en' => $p['name_en'],
            'sku' => $p['sku'],
            'barcode' => $p['barcode'],
            'image' => $p['image'] ? UPLOAD_URL . 'products/' . $p['image'] : null,
            'retail_price' => floatval($p['retail_price']),
            'offer_price' => $pOffer,
            'stock_qty' => floatval($p['stock_qty']),
            'has_variants' => (bool)$p['has_variants'],
            'variants' => array_map(function($v) use ($p, $activeOffers, $catId) {
                $vRetail = $v['retail_price'] ? floatval($v['retail_price']) : floatval($p['retail_price']);
                $vOffer = computeOfferPrice($activeOffers, $vRetail, (int)$p['id'], $catId)
                    ?? ($v['offer_price'] ? floatval($v['offer_price']) : ($p['offer_price'] ? floatval($p['offer_price']) : null));
                return [
                    'id' => $v['id'],
                    'color_ar' => $v['color_ar'],
                    'color_en' => $v['color_en'],
                    'size' => $v['size'],
                    'barcode' => $v['barcode'],
                    'sku' => $v['sku'],
                    'retail_price' => $vRetail,
                    'offer_price' => $vOffer,
                    'stock_qty' => floatval($v['stock_qty'])
                ];
            }, $variants)
        ];
        echo json_encode(['success' => true, 'match' => 'product', 'product' => $prodData]);
        exit;
    }

    // 2. Direct match on variant
    $stmtV = $pdo->prepare("
        SELECT pv.*, p.name_ar, p.name_en, p.image, p.category_id as p_category_id, p.retail_price as p_retail, p.offer_price as p_offer
        FROM product_variants pv
        JOIN products p ON pv.product_id = p.id
        WHERE (pv.barcode = ? OR pv.sku = ?) AND pv.is_active = 1 AND p.is_active = 1
        LIMIT 1
    ");
    $stmtV->execute([$barcode, $barcode]);
    $v = $stmtV->fetch();

    if ($v) {
        $catId = $v['p_category_id'] !== null ? (int)$v['p_category_id'] : null;
        $vRetail = $v['retail_price'] ? floatval($v['retail_price']) : floatval($v['p_retail']);
        if ($vRetail <= 0) {
            echo json_encode(['success' => false, 'error' => 'هذا المنتج غير مسعّر بعد، لا يمكن بيعه']);
            exit;
        }
        $vOffer = computeOfferPrice($activeOffers, $vRetail, (int)$v['product_id'], $catId)
            ?? ($v['offer_price'] ? floatval($v['offer_price']) : ($v['p_offer'] ? floatval($v['p_offer']) : null));
        $prodData = [
            'id' => $v['product_id'],
            'category_id' => $catId,
            'name_ar' => $v['name_ar'],
            'name_en' => $v['name_en'],
            'image' => $v['image'] ? UPLOAD_URL . 'products/' . $v['image'] : null,
            'has_variants' => true
        ];
        $varData = [
            'id' => $v['id'],
            'color_ar' => $v['color_ar'],
            'color_en' => $v['color_en'],
            'size' => $v['size'],
            'barcode' => $v['barcode'],
            'sku' => $v['sku'],
            'retail_price' => $vRetail,
            'offer_price' => $vOffer,
            'stock_qty' => floatval($v['stock_qty'])
        ];
        echo json_encode(['success' => true, 'match' => 'variant', 'product' => $prodData, 'variant' => $varData]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'الباركود غير مسجل']);
    exit;
}

$query = trim($_GET['q'] ?? '');
$catFilter = intval($_GET['cat'] ?? 0);

if ($query === '') {
    // Return all active products (no search query)
    $whereCat = $catFilter > 0 ? "AND p.category_id = $catFilter" : '';
    $stmt = $pdo->query("
        SELECT p.*, c.name_ar as category_name_ar, c.name_en as category_name_en
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = 1 $whereCat
        ORDER BY c.name_ar ASC, p.name_ar ASC
        LIMIT 500
    ");
    $products = $stmt->fetchAll();
} else {
    $searchTerm = "%$query%";
    $catWhere = $catFilter > 0 ? "AND p.category_id = $catFilter" : '';
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.*, c.name_ar as category_name_ar, c.name_en as category_name_en
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_variants pv ON pv.product_id = p.id
        WHERE p.is_active = 1 $catWhere AND (
            p.name_ar LIKE ? OR 
            p.name_en LIKE ? OR 
            p.sku = ? OR 
            p.barcode = ? OR 
            pv.sku = ? OR 
            pv.barcode = ?
        )
        ORDER BY p.name_ar ASC
        LIMIT 200
    ");
    $stmt->execute([$searchTerm, $searchTerm, $query, $query, $query, $query]);
    $products = $stmt->fetchAll();
}

$results = [];

foreach ($products as $p) {
    $catId = $p['category_id'] !== null ? (int)$p['category_id'] : null;
    $variants = [];
    if ($p['has_variants']) {
        $stmtV = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1");
        $stmtV->execute([$p['id']]);
        $variants = $stmtV->fetchAll();
        // منتج بخيارات: اعرض بس الخيارات المسعّرة فعليًا، وتجاهل المنتج كله
        // لو مفيهوش ولا خيار له سعر (منتج غير مسعّر ميظهرش في نقطة البيع)
        $variants = array_values(array_filter($variants, function($v) use ($p) {
            $eff = $v['retail_price'] ? floatval($v['retail_price']) : floatval($p['retail_price']);
            return $eff > 0;
        }));
        if (empty($variants)) continue;
    } elseif (floatval($p['retail_price']) <= 0) {
        // منتج بدون خيارات وسعره صفر (لسه ملهوش سعر) — ميظهرش في نقطة البيع
        continue;
    }

    $pOffer = computeOfferPrice($activeOffers, floatval($p['retail_price']), (int)$p['id'], $catId)
        ?? ($p['offer_price'] ? floatval($p['offer_price']) : null);

    $results[] = [
        'id' => $p['id'],
        'category_id' => $catId,
        'name_ar' => $p['name_ar'],
        'name_en' => $p['name_en'],
        'sku' => $p['sku'],
        'barcode' => $p['barcode'],
        'image' => $p['image'] ? UPLOAD_URL . 'products/' . $p['image'] : null,
        'retail_price' => floatval($p['retail_price']),
        'offer_price' => $pOffer,
        'wholesale_price' => $p['wholesale_price'] ? floatval($p['wholesale_price']) : null,
        'stock_qty' => floatval($p['stock_qty']),
        'has_variants' => (bool)$p['has_variants'],
        'variants' => array_map(function($v) use ($p, $activeOffers, $catId) {
            $vRetail = $v['retail_price'] ? floatval($v['retail_price']) : floatval($p['retail_price']);
            $vOffer = computeOfferPrice($activeOffers, $vRetail, (int)$p['id'], $catId)
                ?? ($v['offer_price'] ? floatval($v['offer_price']) : ($p['offer_price'] ? floatval($p['offer_price']) : null));
            return [
                'id' => $v['id'],
                'color_ar' => $v['color_ar'],
                'color_en' => $v['color_en'],
                'size' => $v['size'],
                'barcode' => $v['barcode'],
                'sku' => $v['sku'],
                'retail_price' => $vRetail,
                'offer_price' => $vOffer,
                'wholesale_price' => $v['wholesale_price'] ? floatval($v['wholesale_price']) : ($p['wholesale_price'] ? floatval($p['wholesale_price']) : null),
                'stock_qty' => floatval($v['stock_qty'])
            ];
        }, $variants)
    ];
}

echo json_encode(['success' => true, 'products' => $results]);
