<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAuth(['admin', 'cashier', 'accountant']);

$status = $_GET['status'] ?? '';
$period = $_GET['period'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT s.*, u.name_ar as cashier_name,
        (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) as items_count,
        COALESCE((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id), 0) as returned_amount
        FROM sales s
        LEFT JOIN users u ON s.cashier_id = u.id
        WHERE 1=1";
$params = [];

if ($status) {
    $sql .= " AND s.status = ?";
    $params[] = $status;
}
if ($search) {
    $sql .= " AND s.invoice_number LIKE ?";
    $params[] = "%$search%";
}

$sql .= " ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Output Excel / CSV headers
$filename = "تقرير_فواتير_" . APP_NAME . "_" . date('Y-m-d_H-i') . ".xls";
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream and add UTF-8 BOM

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


// Column Headers
excel_row([
    'رقم الفاتورة',
    'التاريخ والوقت',
    'الكاشير المسؤول',
    'طريقة الدفع',
    'عدد الأصناف',
    'المجموع الفرعي (درهم)',
    'الخصم (درهم)',
    'الإجمالي النهائي (درهم)',
    'حالة الفاتورة'
]);

$totalGross = 0;
$totalDisc = 0;
$totalNet = 0;
$totalReturned = 0;
$returnedCount = 0;

// Rows
foreach ($sales as $s) {
    $paymentMethod = $s['payment_method'] === 'cash' ? 'نقد (كاش)' : ($s['payment_method'] === 'card' ? 'بطاقة (شبكة)' : ($s['payment_method'] === 'transfer' ? 'تحويل بنكي' : $s['payment_method']));
    $statusText = match ($s['status']) {
        'completed'      => 'مكتملة ومستلمة',
        'partial_return' => 'مرتجع جزئي',
        'returned'       => 'مرتجع / ملغية بالكامل',
        default          => $s['status'],
    };
    $gross = floatval($s['total']);
    $returnedAmt = floatval($s['returned_amount']);
    $net = max(0, $gross - $returnedAmt);

    // نجمع كل الفواتير مهما كانت حالتها — الصافي بيتحسب لكل واحدة بطرح
    // المرتجع الفعلي منها (صفر لو مفيهاش مرتجع)، مش باستبعادها بالكامل لو
    // كانت مرتجع جزئي
    $totalGross += floatval($s['subtotal']);
    $totalDisc += floatval($s['discount']);
    $totalNet += $net;
    if ($returnedAmt > 0) {
        $totalReturned += $returnedAmt;
        $returnedCount++;
    }

    excel_row([
        $s['invoice_number'],
        $s['created_at'],
        $s['cashier_name'] ?: 'المدير',
        $paymentMethod,
        $s['items_count'],
        number_format($s['subtotal'], 2),
        number_format($s['discount'], 2),
        $returnedAmt > 0 ? number_format($net, 2) . ' (اترجع منها ' . number_format($returnedAmt, 2) . ')' : number_format($net, 2),
        $statusText
    ]);
}

// Summary Rows
excel_row([]);
excel_row(['=== ملخص المبيعات الدقيق ===']);
excel_row(['إجمالي المبيعات (قبل المرتجعات)', '', '', '', '', number_format($totalGross, 2), number_format($totalDisc, 2), number_format($totalNet + $totalReturned, 2), 'درهم']);
excel_row(['إجمالي المرتجعات المستردة (مخصومة)', '', '', '', '', '', '', '-' . number_format($totalReturned, 2), "عدد $returnedCount مرتجع"]);
excel_row(['صافي المبيعات المحصلة الفعلي', '', '', '', '', '', '', number_format($totalNet, 2), 'درهم (صافي)']);

echo '</table></body></html>';
exit;
