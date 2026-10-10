<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAuth(['admin', 'cashier', 'accountant']);

$saleId = intval($_GET['id'] ?? 0);
$autoPrint = isset($_GET['autoprint']);

$stmt = $pdo->prepare("
    SELECT s.*, u.id as cashier_user_id
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    die("الفاتورة غير موجودة.");
}

// كاشير يشوف بس فواتيره هو — المدير والمحاسب يشوفوا كل الفواتير (قرار العميل)
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) {
    die("غير مصرح لك بعرض هذه الفاتورة.");
}

$itemsStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
$itemsStmt->execute([$saleId]);
$items = $itemsStmt->fetchAll();

$footerAr = getSetting($pdo, 'invoice_footer_ar', 'شكراً لتسوقكم معنا');
$footerEn = getSetting($pdo, 'invoice_footer_en', 'Thank you for your visit');

$lang = $_SESSION['lang'] ?? 'ar';
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

// Translation helpers for invoice
$t = [
    'print' => $lang === 'ar' ? 'طباعة الفاتورة' : 'Print Invoice',
    'close' => $lang === 'ar' ? 'إغلاق النافذة' : 'Close Window',
    'invoice_no' => $lang === 'ar' ? 'رقم الفاتورة' : 'Invoice No',
    'date' => $lang === 'ar' ? 'التاريخ' : 'Date',
    'cashier' => $lang === 'ar' ? 'رقم الكاشير' : 'Cashier ID',
    'payment' => $lang === 'ar' ? 'طريقة الدفع' : 'Payment',
    'item' => $lang === 'ar' ? 'الصنف' : 'Item',
    'total' => $lang === 'ar' ? 'المجموع' : 'Total',
    'final_total' => $lang === 'ar' ? 'الإجمالي النهائي' : 'Total Due',
];

$cashierNumber = !empty($sale['cashier_user_id']) ? (int)$sale['cashier_user_id'] : ((int)($sale['cashier_id'] ?: 1));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t['invoice_no'] ?> #<?= e($sale['invoice_number']) ?></title>
    <!-- Cairo Font -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Thermal Printer Reset & Typography */
        * { box-sizing: border-box; font-family: 'Cairo', sans-serif; }
        @page { size: 80mm auto; margin: 0; }
        body { background: #f1f5f9; color: #000000; margin: 0; padding: 0; width: 100%; }
        
        .no-print { text-align: center; padding: 15px; background: #fff; border-bottom: 1px solid #ddd; margin-bottom: 20px; }
        .btn-print { padding: 8px 20px; font-size: 14px; background: #264D4C; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 700; margin-left: 10px; margin-right: 10px; }
        .btn-close { padding: 8px 20px; font-size: 14px; background: #e2e8f0; color: #1e293b; border: none; border-radius: 4px; cursor: pointer; }

        .receipt-container {
            width: 76mm; max-width: 76mm; margin: 0 auto; background: #ffffff;
            padding: 5mm 3mm; font-size: 12px; line-height: 1.4; overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        .fw-bolder { font-weight: 800; }
        
        .dashed-line { border-top: 1px dashed #000; margin: 8px 0; }
        .solid-line { border-top: 2px solid #000; margin: 10px 0; }

        .flex-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        
        .item-row { page-break-inside: avoid; break-inside: avoid; margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dotted #ccc; }
        .item-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .item-title { font-weight: 700; margin-bottom: 2px; display: block; }
        .item-details { display: flex; justify-content: space-between; align-items: center; font-size: 11px; }

        .total-row { font-size: 16px; font-weight: 800; margin: 8px 0; }

        @media print {
            body { background: #ffffff; }
            .no-print { display: none !important; }
            .receipt-container { margin: 0 !important; padding: 2mm !important; box-shadow: none !important; width: 100% !important; max-width: 78mm !important; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()" class="btn-print"><?= $t['print'] ?></button>
    <button onclick="window.close()" class="btn-close"><?= $t['close'] ?></button>
</div>

<div class="receipt-container">
    <?php $brandInfo = getActiveBrandInfo(); ?>
    
    <!-- Header: ONLY LOGO -->
    <div class="text-center" style="margin-bottom: 12px;">
        <div style="display: flex; justify-content: center; align-items: center;">
            <img src="<?= $brandInfo['logo'] ?>" alt="<?= e($brandInfo['name_en']) ?>" style="height: 95px; max-width: 210px; object-fit: contain;">
        </div>
    </div>

    <div class="dashed-line"></div>

    <!-- Invoice Details -->
    <div class="flex-row">
        <span><?= $t['invoice_no'] ?></span>
        <strong dir="ltr">#<?= e($sale['invoice_number']) ?></strong>
    </div>
    <div class="flex-row">
        <span><?= $t['date'] ?></span>
        <span dir="ltr"><?= date('Y-m-d H:i', strtotime($sale['created_at'])) ?></span>
    </div>
    <div class="flex-row">
        <span><?= $t['cashier'] ?></span>
        <strong dir="ltr">#<?= $cashierNumber ?></strong>
    </div>
    <div class="flex-row">
        <span><?= $t['payment'] ?></span>
        <strong><?= e(strtoupper($sale['payment_method'])) ?></strong>
    </div>

    <div class="dashed-line"></div>

    <!-- Items List -->
    <div style="margin-bottom: 10px;">
        <div class="flex-row fw-bold" style="border-bottom: 1px solid #000; padding-bottom: 4px; margin-bottom: 6px;">
            <span><?= $t['item'] ?></span>
            <span><?= $t['total'] ?></span>
        </div>
        
        <?php foreach ($items as $it): ?>
            <div class="item-row">
                <span class="item-title">
                    <?= e($lang === 'ar' ? $it['product_name_ar'] : ($it['product_name_en'] ?: $it['product_name_ar'])) ?>
                    <?php if ($it['color_ar'] || $it['size']): ?>
                        <span style="font-weight: normal; font-size: 11px;">[<?= e($lang === 'ar' ? $it['color_ar'] : ($it['color_en'] ?: $it['color_ar'])) ?> <?= e($it['size']) ?>]</span>
                    <?php endif; ?>
                </span>
                <div class="item-details">
                    <span dir="ltr"><?= $it['quantity'] ?> × <?= money($it['unit_price']) ?></span>
                    <strong dir="ltr"><?= money($it['total']) ?></strong>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Final Total ONLY (No subtotal) -->
    <div class="solid-line"></div>
    
    <div class="flex-row total-row">
        <span><?= $t['final_total'] ?></span>
        <span dir="ltr"><?= money($sale['total']) ?></span>
    </div>

    <div class="dashed-line" style="margin-top: 10px;"></div>

    <!-- Footer: Thank you, Website & Instagram -->
    <div class="text-center" style="margin-top: 15px; margin-bottom: 5px;">
        <div class="fw-bold" style="font-size: 13px; margin-bottom: 10px;"><?= e($lang === 'ar' ? $footerAr : $footerEn) ?></div>
        <div style="display: flex; flex-direction: row; justify-content: space-between; align-items: center; padding: 0 5px;">
            <div style="display: flex; align-items: center; gap: 4px; direction: ltr; font-weight: 700; font-size: 11px; color: #000;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
                <span><?= e($brandInfo['website'] ?? 'adventure.ae') ?></span>
            </div>
            <div style="display: flex; align-items: center; gap: 4px; direction: ltr; font-weight: 700; font-size: 11px; color: #000;">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                </svg>
                <span><?= e($brandInfo['instagram'] ?? 'adventure.ae') ?></span>
            </div>
        </div>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>
window.onload = function() { window.print(); };
</script>
<?php endif; ?>

</body>
</html>
