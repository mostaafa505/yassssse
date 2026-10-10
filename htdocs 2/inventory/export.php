<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAccess(['admin', 'stock', 'accountant'], 'manage_inventory');

$sql = "
    SELECT p.*, c.name_ar as category_name_ar,
    (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) as variants_count,
    (SELECT COALESCE(SUM(pv.stock_qty), 0) FROM product_variants pv WHERE pv.product_id = p.id) as variants_stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1
    ORDER BY p.id DESC
";
$products = $pdo->query($sql)->fetchAll();

$filename = "كشف_المخزون_" . APP_NAME . "_" . date('Y-m-d_H-i') . ".xls";
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');


echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta http-equiv="Content-type" content="text/html;charset=utf-8" />';
echo '<style>
    body { font-family: "Times New Roman", Arial, serif; direction: rtl; color: #000000; background: #ffffff; }
    table { border-collapse: collapse; width: 100%; direction: rtl; margin-bottom: 20px; border: 2px solid #000000; }
    th { background-color: #f2f2f2; color: #000000; font-weight: bold; border: 1px solid #000000; padding: 12px; text-align: center; font-size: 15px; border-bottom: 2px solid #000000; text-transform: uppercase; }
    td { border: 1px solid #000000; padding: 8px; text-align: center; font-size: 14px; color: #000000; }
    .negative { color: #000000; font-weight: bold; }
    .section-title th { font-size: 18px; font-weight: bold; background-color: #e6e6e6; color: #000000; text-align: center; padding: 15px; border: 2px solid #000000; border-bottom: 3px double #000000; letter-spacing: 1px; }
    .summary-row td { font-weight: bold; background-color: #fafafa; font-size: 15px; border-top: 2px solid #000000; border-bottom: 2px solid #000000; }
    .empty-row td { border: none; height: 30px; background-color: #ffffff; }
</style>';
echo '</head><body><table>';

function excel_row($data, $isHeader = false, $isSection = false) {
    if (empty($data)) {
        echo '<tr class="empty-row"><td colspan="20"></td></tr>';
        return;
    }
    
    // Auto-detect summary rows
    $isSummary = false;
    $firstCell = (string)reset($data);
    if (strpos($firstCell, '===') !== false || strpos($firstCell, 'إجمالي') !== false || strpos($firstCell, 'صافي') !== false) {
        $isSummary = true;
    }
    if ($isSection || strpos($firstCell, '===') !== false) {
        echo '<tr class="section-title"><th colspan="20">' . htmlspecialchars(str_replace('===', '', $firstCell)) . '</th></tr>';
        return;
    }

    echo $isSummary ? '<tr class="summary-row">' : '<tr>';
    foreach ($data as $cell) {
        $class = '';
        if (is_string($cell) && strpos($cell, '-') === 0 && preg_match('/-[0-9]/', $cell)) {
            $class = 'negative';
        }
        if ($isHeader) {
            echo '<th>' . htmlspecialchars((string)$cell) . '</th>';
        } else {
            echo '<td class="' . $class . '">' . htmlspecialchars((string)$cell) . '</td>';
        }
    }
    echo '</tr>';
}


// Headers
excel_row([
    'كود المنتج (SKU)',
    'الباركود',
    'اسم المنتج',
    'القسم',
    'الكمية المتوفرة',
    'حد التنبيه',
    'سعر التكلفة (درهم)',
    'سعر البيع (درهم)',
    'إجمالي القيمة التقديرية (درهم)',
    'حالة المخزون'
]);

foreach ($products as $p) {
    $qty = $p['has_variants'] ? $p['variants_stock'] : $p['stock_qty'];
    $minAlert = floatval($p['min_stock_alert']);
    $totalVal = $qty * floatval($p['cost_price'] ?: $p['retail_price']);
    
    if ($qty <= 0) {
        $status = 'نفد المخزون';
    } elseif ($qty <= $minAlert) {
        $status = 'مخزون منخفض (تنبيه)';
    } else {
        $status = 'متوفر ومستقر';
    }

    excel_row([
        $p['sku'] ?: '—',
        $p['barcode'] ?: '—',
        $p['name_ar'],
        $p['category_name_ar'] ?: 'غير محدد',
        $qty,
        $minAlert,
        number_format($p['cost_price'] ?? 0, 2),
        number_format($p['retail_price'], 2),
        number_format($totalVal, 2),
        $status
    ]);
}

echo '</table></body></html>';
exit;
