<?php
/**
 * sync_offline.php — يستقبل الفواتير المعلقة من الـ Service Worker ويرفعها
 */
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['user_role'], ['admin', 'cashier'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

if (!verifyCsrf()) {
    // مهم: false بدون throw، عشان لو التوكن لسه مبعتش لعامل الخدمة (مثلاً أول
    // مزامنة خلفية بعد تثبيت الصفحة لأول مرة)، الفواتير المعلقة تفضل محفوظة
    // في IndexedDB وتتحاول تاني بعدين، مش تتمسح أو تضيع
    echo json_encode(['success' => false, 'error' => 'رمز الحماية غير صالح، سيُعاد المحاولة لاحقاً']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['sales'])) {
    echo json_encode(['success' => true, 'synced_ids' => [], 'failed' => 0]);
    exit;
}

$syncedIds     = [];
$failed        = 0;
$failedReasons = [];

foreach ($input['sales'] as $sale) {
    $localId   = $sale['local_id'] ?? 0;
    $clientUid = trim($sale['client_uid'] ?? '');

    try {
        // ── حماية من التكرار: لو الفاتورة دي اتزامنت قبل كده (retry / اتصال قُطع
        //    بعد نجاح الحفظ بس قبل ما الرد يوصل للجهاز) متتضافش تاني ولا يتخصم
        //    المخزون مرتين ───────────────────────────────────────────────────
        if ($clientUid !== '') {
            $stmtCheck = $pdo->prepare("SELECT id FROM sales WHERE client_uid = ? LIMIT 1");
            $stmtCheck->execute([$clientUid]);
            if ($stmtCheck->fetch()) {
                $syncedIds[] = $localId;
                continue;
            }
        }

        $pdo->beginTransaction();

        $items          = $sale['items'] ?? [];
        $paymentMethod  = in_array($sale['payment_method'] ?? '', ['cash','card','transfer','mixed']) ? $sale['payment_method'] : 'cash';
        $discount       = floatval($sale['discount'] ?? 0);
        $customerName   = trim($sale['customer_name'] ?? '');
        $customerPhone  = trim($sale['customer_phone'] ?? '');
        $notes          = trim($sale['notes'] ?? '');
        $offlineCreated = trim($sale['created_at'] ?? '');

        // الفاتورة دي اتباعت أوفلاين فعلاً والعميل دفع المبلغ (بعد الخصم لو
        // كان في خصم) بالفعل وقت البيع الحقيقي — فمش زي save_sale.php اللي
        // بيرفض الخصم غير المصرّح به قبل ما أي حاجة تتسجل، هنا رفض المزامنة
        // معناه ضياع فاتورة مدفوعة فعليًا (نفس مبدأ المخزون بالظبط اللي
        // موضح فوق). فبدل الرفض، بنسجل الفاتورة زي ما هي (عشان الأرقام تفضل
        // متطابقة مع الكاش الفعلي اللي اتقبض) بس بنعلّم عليها بوضوح في سجل
        // العمليات إن الخصم ده اتطبق من مستخدم مالوش صلاحية apply_discount،
        // عشان الأدمن يراجعها بعدين.
        $unauthorizedDiscount = $discount > 0
            && !in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant'])
            && !hasPermission('apply_discount');

        // نفس مبدأ الخصم غير المصرح به (F-12) وبنفس السبب بالظبط: الفلوس اتقبضت
        // فعلاً أوفلاين، فرفض المزامنة معناه ضياع فاتورة مدفوعة. بنعلّم بس
        // على أي بند اتباع بسعر جملة من كاشير معهوش صلاحية apply_wholesale_price،
        // عشان الأدمن يراجعها.
        $hasWholesalePermission = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('apply_wholesale_price');
        $unauthorizedWholesaleItems = [];

        $subtotal = 0;
        $processedItems = [];
        $wentNegative = false;
        $zeroPriceItems = []; // بنود اتباعت بسعر صفر/بدون سعر — تتسجل هنا للمراجعة بس، مش سبب لرفض المزامنة

        // ملاحظة مهمة جداً: الفاتورة دي اتباعت فعلياً أوفلاين — الكاشير قبض
        // فلوس العميل وطبع له الفاتورة بالفعل، فمينفعش نرفض الفاتورة دي وقت
        // المزامنة بحجة "المخزون مش كافي"، لأن ده معناه ضياع فاتورة مدفوعة
        // فعلاً من غير أي طريقة للرجوع لها. فبدل ما نرفض، نسجل الفاتورة
        // بالكمية الكاملة اللي اتباعت ونخصم من المخزون حتى لو المخزون
        // هيبقى سالب — ده أهون بكتير من ضياع عملية بيع كاملة، والمخزون
        // السالب سهل يتراجع ويتصحح يدوياً من صفحة الجرد.
        // السطر الوحيد اللي بيخلينا نتجاهل بند بالكامل هو لو المنتج/الخيار
        // نفسه اتمسح أو بقى غير نشط في قاعدة البيانات (مش موجود خالص).
        foreach ($items as $item) {
            $productId = intval($item['product_id']);
            $variantId = !empty($item['variant_id']) ? intval($item['variant_id']) : null;
            $qty       = floatval($item['qty'] ?? 1);
            $priceType = in_array($item['price_type'] ?? '', ['retail','offer','wholesale']) ? $item['price_type'] : 'retail';
            $unitPrice = floatval($item['unit_price'] ?? 0);

            if ($qty <= 0) continue;

            if ($variantId) {
                $stmtV = $pdo->prepare("SELECT * FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE");
                $stmtV->execute([$variantId, $productId]);
                $variant = $stmtV->fetch();
                if (!$variant) continue; // الخيار اتمسح فعلاً — تخطي البند ده بس

                if ($variant['stock_qty'] < $qty) $wentNegative = true;

                $nameAr   = $item['name_ar'] ?? '';
                $nameEn   = $item['name_en'] ?? '';
                $colorAr  = $variant['color_ar'] ?? null;
                $colorEn  = $variant['color_en'] ?? null;
                $size     = $variant['size'] ?? null;
                $sku      = $variant['sku'] ?: ($item['sku'] ?? '');
                // التكلفة وقت المزامنة (أقرب حاجة متاحة لتكلفة وقت البيع الحقيقي —
                // الجهاز الأوفلاين مبيبعتش تكلفة تاريخية، فده أفضل تقدير ممكن)
                $costPriceSnapshot = $variant['cost_price'] !== null ? floatval($variant['cost_price']) : null;
            } else {
                $stmtP = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 FOR UPDATE");
                $stmtP->execute([$productId]);
                $prod = $stmtP->fetch();
                if (!$prod) continue; // المنتج اتمسح أو بقى غير نشط — تخطي البند ده بس

                if ($prod['stock_qty'] < $qty) $wentNegative = true;

                $nameAr  = $prod['name_ar'];
                $nameEn  = $prod['name_en'];
                $colorAr = null; $colorEn = null; $size = null;
                $sku     = $prod['sku'];
                $costPriceSnapshot = $prod['cost_price'] !== null ? floatval($prod['cost_price']) : null;
            }

            if ($unitPrice <= 0) $zeroPriceItems[] = $nameAr;
            if ($priceType === 'wholesale' && !$hasWholesalePermission) $unauthorizedWholesaleItems[] = $nameAr;

            $itemTotal  = $qty * $unitPrice;
            $subtotal  += $itemTotal;

            $processedItems[] = compact('productId','variantId','nameAr','nameEn','colorAr','colorEn','size','sku','qty','unitPrice','costPriceSnapshot','priceType','itemTotal');
        }

        if (empty($processedItems)) {
            $pdo->rollBack();
            $failed++;
            $failedReasons[] = ['local_id' => $localId, 'reason' => 'كل المنتجات في الفاتورة دي اتمسحت من النظام قبل ما تتزامن'];
            continue;
        }

        $finalTotal    = max(0, $subtotal - $discount);
        $cashAmount    = $paymentMethod === 'cash' ? $finalTotal : 0;
        $cardAmount    = $paymentMethod === 'card' ? $finalTotal : 0;
        $transferAmount = $paymentMethod === 'transfer' ? $finalTotal : 0;

        $invoiceNumber = nextInvoiceNumber($pdo);

        // فاتورة المعرض النشط
        $activeEx = $pdo->query("SELECT id FROM exhibitions WHERE status='active' AND CURDATE() BETWEEN start_date AND end_date LIMIT 1")->fetch();
        $exhibitionId = $activeEx ? intval($activeEx['id']) : null;

        // الوقت الحقيقي اللي حصل فيه البيع فعليًا (قبل ما الجهاز يفقد الاتصال)،
        // مش وقت المزامنة — وإلا فاتورة اتباعت الساعة 2 الضهر وتزامنت الساعة 6
        // المغرب هتتسجل وكأنها حصلت الساعة 6، وتبوظ أي تقرير بالساعة أو باليوم.
        // بنقبل التاريخ المرسل من الجهاز بس لو منطقي (مش في المستقبل، ومش قديم
        // أوي) — أي قيمة غريبة أو تالفة بترجع لسلوك النظام الافتراضي (وقت
        // المزامنة) بدل ما تتحفظ زي ما هي في عمود created_at.
        $createdAtToUse = null; // null = اسيب الـ DEFAULT current_timestamp() الطبيعي
        if ($offlineCreated !== '') {
            $ts = strtotime($offlineCreated);
            $now = time();
            if ($ts !== false && $ts <= $now + 300 && $ts >= $now - (90 * 86400)) {
                $createdAtToUse = date('Y-m-d H:i:s', $ts);
            }
        }

        // Insert فاتورة
        if ($createdAtToUse !== null) {
            $pdo->prepare("
                INSERT INTO sales (invoice_number, client_uid, exhibition_id, cashier_id, customer_name, customer_phone, payment_method, cash_amount, card_amount, transfer_amount, subtotal, discount, total, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $invoiceNumber, ($clientUid !== '' ? $clientUid : null), $exhibitionId, $_SESSION['user_id'],
                $customerName, $customerPhone, $paymentMethod,
                $cashAmount, $cardAmount, $transferAmount,
                $subtotal, $discount, $finalTotal, $notes, $createdAtToUse
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO sales (invoice_number, client_uid, exhibition_id, cashier_id, customer_name, customer_phone, payment_method, cash_amount, card_amount, transfer_amount, subtotal, discount, total, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $invoiceNumber, ($clientUid !== '' ? $clientUid : null), $exhibitionId, $_SESSION['user_id'],
                $customerName, $customerPhone, $paymentMethod,
                $cashAmount, $cardAmount, $transferAmount,
                $subtotal, $discount, $finalTotal, $notes
            ]);
        }
        $saleId = $pdo->lastInsertId();

        // Insert بنود وخصم مخزون
        $stmtItem     = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, variant_id, product_name_ar, product_name_en, color_ar, color_en, size, sku, quantity, unit_price, cost_price, price_type, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtDeductP  = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
        $stmtDeductV  = $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty - ? WHERE id = ?");
        $stmtMovement = $pdo->prepare("INSERT INTO inventory_movements (product_id, variant_id, movement_type, quantity, reference_id, notes, user_id) VALUES (?, ?, 'sale', ?, ?, ?, ?)");

        foreach ($processedItems as $pi) {
            $stmtItem->execute([$saleId, $pi['productId'], $pi['variantId'], $pi['nameAr'], $pi['nameEn'], $pi['colorAr'], $pi['colorEn'], $pi['size'], $pi['sku'], $pi['qty'], $pi['unitPrice'], $pi['costPriceSnapshot'], $pi['priceType'], $pi['itemTotal']]);
            if ($pi['variantId']) { $stmtDeductV->execute([$pi['qty'], $pi['variantId']]); }
            else                  { $stmtDeductP->execute([$pi['qty'], $pi['productId']]); }
            $stmtMovement->execute([$pi['productId'], $pi['variantId'], $pi['qty'], $saleId, "فاتورة أوفلاين #$invoiceNumber (مزامنة)", $_SESSION['user_id']]);
        }

        logAudit($pdo, 'sync_offline_sale', 'sale', $saleId, null, [
            'invoice_number'  => $invoiceNumber,
            'total'           => $finalTotal,
            'offline_created' => $offlineCreated,
            'created_at_used' => $createdAtToUse ?? 'sync_time (offline timestamp missing or implausible)',
            'items_count'     => count($processedItems),
            'stock_went_negative' => $wentNegative,
            'discount' => $discount,
            'unauthorized_discount' => $unauthorizedDiscount,
            'zero_price_items' => $zeroPriceItems,
            'unauthorized_wholesale_items' => $unauthorizedWholesaleItems,
        ]);

        $pdo->commit();
        $syncedIds[] = $localId;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // لو حصل تعارض (Race Condition) — طلبين مزامنة اشتغلوا في نفس اللحظة
        // لنفس الفاتورة أوفلاين — الفاتورة اتسجلت فعلاً من الطلب التاني، فاعتبرها
        // نجحت بدل ما تتسجل مرتين وتخصم من المخزون مرتين
        if ((int)$e->getCode() === 23000 || strpos($e->getMessage(), '1062') !== false) {
            if ($clientUid !== '') {
                $stmtCheck2 = $pdo->prepare("SELECT id FROM sales WHERE client_uid = ? LIMIT 1");
                $stmtCheck2->execute([$clientUid]);
                if ($stmtCheck2->fetch()) {
                    $syncedIds[] = $localId;
                    continue;
                }
            }
        }
        $failed++;
        $failedReasons[] = ['local_id' => $localId, 'reason' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed++;
        $failedReasons[] = ['local_id' => $localId, 'reason' => $e->getMessage()];
    }
}

echo json_encode([
    'success'        => true,
    'synced_ids'     => $syncedIds,
    'failed'         => $failed,
    'failed_reasons' => $failedReasons
]);
