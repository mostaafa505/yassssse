<?php
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'], ['admin', 'cashier'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بإجراء عمليات البيع']);
    exit;
}

if (!verifyCsrf()) {
    echo json_encode(['success' => false, 'error' => 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['items'])) {
    echo json_encode(['success' => false, 'error' => 'السلة فارغة، يرجى إضافة منتجات قبل إتمام البيع']);
    exit;
}

$customerName = trim($input['customer_name'] ?? '');
$customerPhone = trim($input['customer_phone'] ?? '');
$paymentMethod = in_array($input['payment_method'] ?? '', ['cash', 'card', 'transfer', 'mixed']) ? $input['payment_method'] : 'cash';
$cashAmount = floatval($input['cash_amount'] ?? 0);
$cardAmount = floatval($input['card_amount'] ?? 0);
$transferAmount = floatval($input['transfer_amount'] ?? 0);
$discount = floatval($input['discount'] ?? 0);
$notes = trim($input['notes'] ?? '');
$items = $input['items'];

try {
    // الخصم كان بيتقبل من غير أي فحص صلاحية على السيرفر — الشاشة كانت بتخفي
    // حقل الخصم عن الكاشير اللي معهوش صلاحية apply_discount، بس ده كان مجرد
    // إخفاء في الواجهة، وأي حد يقدر يبعت خصم بأي قيمة عن طريق طلب مباشر.
    // نفس النمط المستخدم بالظبط لـ void_invoice في cancel.php/return.php.
    if ($discount > 0 && !in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) && !hasPermission('apply_discount')) {
        throw new Exception('غير مصرح لك بتطبيق خصم على الفاتورة.');
    }

    // نفس مبدأ الخصم بالظبط (F-12) — سعر الجملة كان مقبول من أي كاشير من غير
    // أي فحص صلاحية على السيرفر، والشاشة كانت بتخفي الخيار بس عن غير
    // المصرح لهم (إخفاء واجهة فقط، مش حماية حقيقية). لو أي بند في الفاتورة
    // طالب سعر جملة وصاحب الجلسة مش أدمن/محاسب ومعهوش صلاحية
    // apply_wholesale_price، الفاتورة كلها ترفض من غير ما يتسجل أي حاجة —
    // زي بالظبط سلوك الخصم غير المصرح به.
    $hasWholesalePermission = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('apply_wholesale_price');
    foreach ($items as $item) {
        if (($item['price_type'] ?? '') === 'wholesale' && !$hasWholesalePermission) {
            throw new Exception('غير مصرح لك بالبيع بسعر الجملة.');
        }
    }

    $pdo->beginTransaction();

    $subtotal = 0;
    $processedItems = [];
    // العروض الفعالة دلوقتي (زي شاشة البيع بالظبط) — لازم تتجاب هنا كمان
    // ومتتقراش من عمود offer_price الثابت بس، وإلا السعر اللي اتفق عليه
    // الكاشير مع العميل على الشاشة (المبني على عرض حملة فعّالة) ممكن يختلف
    // تمامًا عن السعر اللي فعليًا بيتسجل في الفاتورة (خلل حرج تم اكتشافه
    // وإصلاحه في تدقيق F-01)
    $activeOffers = getActiveOffers($pdo);

    foreach ($items as $item) {
        $productId = intval($item['product_id']);
        $variantId = !empty($item['variant_id']) ? intval($item['variant_id']) : null;
        $qty = floatval($item['qty'] ?? 1);
        $priceType = in_array($item['price_type'] ?? '', ['retail', 'offer', 'wholesale']) ? $item['price_type'] : 'retail';

        if ($qty <= 0) continue;

        // Fetch product
        $stmtP = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmtP->execute([$productId]);
        $prod = $stmtP->fetch();

        if (!$prod) {
            throw new Exception("المنتج برقم #$productId غير متوفر أو تم حذفه");
        }

        $colorAr = null; $colorEn = null; $size = null; $sku = $prod['sku'];
        $unitPrice = floatval($prod['retail_price']);
        // نسخة من تكلفة المنتج وقت البيع بالظبط (مش وقت التقرير) — عشان تعديل
        // تكلفة المنتج بعدين مايغيرش أرباح معارض حصلت فعلاً في الماضي (F-07)
        $costPriceSnapshot = $prod['cost_price'] !== null ? floatval($prod['cost_price']) : null;

        if ($variantId) {
            $stmtV = $pdo->prepare("SELECT * FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE");
            $stmtV->execute([$variantId, $productId]);
            $variant = $stmtV->fetch();

            if (!$variant) {
                throw new Exception("الخيار المحدد للمنتج ({$prod['name_ar']}) غير متوفر");
            }

            if ($variant['stock_qty'] < $qty) {
                throw new Exception("الكمية المطلوبة ({$qty}) غير متوفرة في المخزون للخيار: {$variant['color_ar']} {$variant['size']} (المتوفر: {$variant['stock_qty']})");
            }

            $colorAr = $variant['color_ar'];
            $colorEn = $variant['color_en'];
            $size = $variant['size'];
            $sku = $variant['sku'] ?: $prod['sku'];
            if ($variant['cost_price'] !== null) $costPriceSnapshot = floatval($variant['cost_price']);

            $baseRetail = $variant['retail_price'] !== null ? floatval($variant['retail_price']) : floatval($prod['retail_price']);
            if ($variant['retail_price'] !== null) $unitPrice = $baseRetail;

            if ($baseRetail <= 0) {
                throw new Exception("هذا الخيار ({$prod['name_ar']} - {$variant['color_ar']} {$variant['size']}) غير مسعّر بعد، لا يمكن بيعه.");
            }

            if ($priceType === 'offer') {
                // أقل سعر بين عرض الحملة الفعّال (offers table) والسعر الثابت
                // المحفوظ على الخيار — من غير ما نجمعهم فوق بعض أبدًا (قرار
                // العميل: أقل سعر بس، مش تراكم خصومات)
                $campaignPrice = computeOfferPrice($activeOffers, $baseRetail, $productId, $prod['category_id']);
                $staticPrice = $variant['offer_price'] !== null ? floatval($variant['offer_price']) : null;
                $candidates = array_filter([$campaignPrice, $staticPrice], fn($p) => $p !== null);
                if (!empty($candidates)) $unitPrice = min($candidates);
            } elseif ($priceType === 'wholesale' && $variant['wholesale_price'] !== null) {
                $unitPrice = floatval($variant['wholesale_price']);
            }
        } else {
            if ($prod['stock_qty'] < $qty) {
                throw new Exception("الكمية المطلوبة ({$qty}) غير متوفرة في مخزون: {$prod['name_ar']} (المتوفر: {$prod['stock_qty']})");
            }

            if (floatval($prod['retail_price']) <= 0) {
                throw new Exception("المنتج ({$prod['name_ar']}) غير مسعّر بعد، لا يمكن بيعه.");
            }

            if ($priceType === 'offer') {
                $campaignPrice = computeOfferPrice($activeOffers, floatval($prod['retail_price']), $productId, $prod['category_id']);
                $staticPrice = $prod['offer_price'] !== null ? floatval($prod['offer_price']) : null;
                $candidates = array_filter([$campaignPrice, $staticPrice], fn($p) => $p !== null);
                if (!empty($candidates)) $unitPrice = min($candidates);
            } elseif ($priceType === 'wholesale' && $prod['wholesale_price'] !== null) {
                $unitPrice = floatval($prod['wholesale_price']);
            }
        }

        $itemTotal = $qty * $unitPrice;
        $subtotal += $itemTotal;

        $processedItems[] = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'name_ar' => $prod['name_ar'],
            'name_en' => $prod['name_en'],
            'color_ar' => $colorAr,
            'color_en' => $colorEn,
            'size' => $size,
            'sku' => $sku,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'price_type' => $priceType,
            'total' => $itemTotal,
            'cost_price' => $costPriceSnapshot
        ];
    }

    $finalTotal = max(0, $subtotal - $discount);

    if ($paymentMethod === 'cash') {
        $cashAmount = $finalTotal; $cardAmount = 0; $transferAmount = 0;
    } elseif ($paymentMethod === 'card') {
        $cardAmount = $finalTotal; $cashAmount = 0; $transferAmount = 0;
    } elseif ($paymentMethod === 'transfer') {
        $transferAmount = $finalTotal; $cashAmount = 0; $cardAmount = 0;
    }

    $invoiceNumber = nextInvoiceNumber($pdo);

    // Auto-link to active exhibition if running today, or if explicitly passed
    $exhibitionId = !empty($input['exhibition_id']) ? intval($input['exhibition_id']) : null;
    if (!$exhibitionId) {
        $activeEx = $pdo->query("SELECT id FROM exhibitions WHERE status = 'active' AND CURDATE() BETWEEN start_date AND end_date LIMIT 1")->fetch();
        if ($activeEx) {
            $exhibitionId = intval($activeEx['id']);
        }
    }

    // Insert Sale
    $stmtSale = $pdo->prepare("
        INSERT INTO sales (invoice_number, exhibition_id, cashier_id, customer_name, customer_phone, payment_method, cash_amount, card_amount, transfer_amount, subtotal, discount, total, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtSale->execute([
        $invoiceNumber,
        $exhibitionId,
        $_SESSION['user_id'],
        $customerName,
        $customerPhone,
        $paymentMethod,
        $cashAmount,
        $cardAmount,
        $transferAmount,
        $subtotal,
        $discount,
        $finalTotal,
        $notes
    ]);
    $saleId = $pdo->lastInsertId();

    // Insert Items and Deduct Stock
    $stmtItem = $pdo->prepare("
        INSERT INTO sale_items (sale_id, product_id, variant_id, product_name_ar, product_name_en, color_ar, color_en, size, sku, quantity, unit_price, cost_price, price_type, total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtDeductProd = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
    $stmtDeductVar = $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty - ? WHERE id = ?");
    $stmtMovement = $pdo->prepare("
        INSERT INTO inventory_movements (product_id, variant_id, movement_type, quantity, reference_id, notes, user_id)
        VALUES (?, ?, 'sale', ?, ?, ?, ?)
    ");

    foreach ($processedItems as $pi) {
        $stmtItem->execute([
            $saleId,
            $pi['product_id'],
            $pi['variant_id'],
            $pi['name_ar'],
            $pi['name_en'],
            $pi['color_ar'],
            $pi['color_en'],
            $pi['size'],
            $pi['sku'],
            $pi['qty'],
            $pi['unit_price'],
            $pi['cost_price'],
            $pi['price_type'],
            $pi['total']
        ]);

        if ($pi['variant_id']) {
            $stmtDeductVar->execute([$pi['qty'], $pi['variant_id']]);
        } else {
            $stmtDeductProd->execute([$pi['qty'], $pi['product_id']]);
        }

        $stmtMovement->execute([
            $pi['product_id'],
            $pi['variant_id'],
            $pi['qty'],
            $saleId,
            "فاتورة مبيعات #$invoiceNumber",
            $_SESSION['user_id']
        ]);
    }

    // Audit Log
    logAudit($pdo, 'create_sale', 'sale', $saleId, null, [
        'invoice_number' => $invoiceNumber,
        'total' => $finalTotal,
        'discount' => $discount,
        'payment_method' => $paymentMethod,
        'items_count' => count($processedItems),
        'items' => array_map(function($i) {
            return $i['qty'] . 'x ' . $i['name_ar'] . ' (' . money($i['unit_price']) . ')';
        }, $processedItems)
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'invoice_number' => $invoiceNumber,
        'total' => $finalTotal
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
