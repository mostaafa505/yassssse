<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAccess(['admin', 'accountant'], 'view_reports');

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$cashierId = intval($_GET['cashier_id'] ?? 0);

$sql = "
    SELECT s.*, u.name_ar as cashier_name,
    COALESCE((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id), 0) as returned_amount
    FROM sales s
    LEFT JOIN users u ON s.cashier_id = u.id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
";
$params = [$dateFrom, $dateTo];

if ($cashierId > 0) {
    $sql .= " AND s.cashier_id = ?";
    $params[] = $cashierId;
}

$sql .= " ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Fetch Expenses
$stmtExp = $pdo->prepare("
    SELECT ee.*, u.name_ar as user_name
    FROM exhibition_expenses ee
    LEFT JOIN users u ON ee.created_by = u.id
    WHERE ee.expense_date BETWEEN ? AND ?
    ORDER BY ee.expense_date ASC, ee.id ASC
");
$stmtExp->execute([$dateFrom, $dateTo]);
$expenses = $stmtExp->fetchAll();
$totalExpenses = array_sum(array_column($expenses, 'amount'));

$categoryTitles = [
    'booth_rent'         => 'إيجار الجناح / المقر',
    'staff_wages'        => 'رواتب وأجور العمال والموظفين',
    'hospitality_food'   => 'طعام وإعاشة وضيافة',
    'packaging_supplies' => 'أكياس وباكدجات وتغليف',
    'shipping_transport' => 'نقل وشحن وتوصيل',
    'setup_decoration'   => 'تجهيز وديكور',
    'marketing_ads'      => 'تسويق وإعلانات',
    'utilities_permits'  => 'رسوم وتصاريح وكهرباء',
    'other'              => 'مصاريف أخرى ونثرية',
];

$filename = "التقرير_المالي_" . APP_NAME . "_" . $dateFrom . "_إلى_" . $dateTo . ".xls";
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

// Header info
excel_row(['التقرير المالي التنفيذي الشامل (المبيعات والمصروفات والأرباح) - ' . APP_NAME]);
excel_row(['الفترة من:', $dateFrom, 'إلى:', $dateTo, 'تاريخ الاستخراج:', date('Y-m-d H:i')]);
excel_row([]);

// Columns
excel_row([
    'رقم الفاتورة',
    'التاريخ والوقت',
    'الكاشير',
    'طريقة الدفع',
    'المجموع الفرعي (درهم)',
    'الخصم (درهم)',
    'المدفوع كاش (درهم)',
    'المدفوع بطاقة (درهم)',
    'المدفوع تحويل (درهم)',
    'الإجمالي النهائي (درهم)',
    'حالة الفاتورة'
], true);

$totalSub = 0;
$totalDisc = 0;
$totalCash = 0;
$totalCard = 0;
$totalTrans = 0;
$totalNet = 0;
$totalReturned = 0;
$returnedCount = 0;

foreach ($sales as $s) {
    $sub = floatval($s['subtotal']);
    $disc = floatval($s['discount']);
    $cash = floatval($s['cash_amount']);
    $card = floatval($s['card_amount']);
    $trans = floatval($s['transfer_amount']);
    $gross = floatval($s['total']);
    $returnedAmt = floatval($s['returned_amount']);
    $net = max(0, $gross - $returnedAmt);

    // نجمع كل الفواتير (مكتملة/مرتجع جزئي/مرتجع كامل) — الصافي بيتحسب لكل
    // واحدة على حدة بطرح المرتجع الفعلي منها، مش باستبعادها بالكامل لو حصلها
    // مرتجع جزئي (كان ده أصل الغلطة قبل كده)
    $totalSub += $sub;
    $totalDisc += $disc;
    $totalCash += $cash;
    $totalCard += $card;
    $totalTrans += $trans;
    $totalNet += $net;
    if ($returnedAmt > 0) {
        $totalReturned += $returnedAmt;
        $returnedCount++;
    }

    $statusText = match ($s['status']) {
        'completed'      => 'ناجحة ومستلمة',
        'partial_return' => 'مرتجع جزئي',
        'returned'       => 'ملغية / مرتجع بالكامل',
        default          => $s['status'],
    };

    excel_row([
        $s['invoice_number'],
        $s['created_at'],
        $s['cashier_name'] ?: 'المدير',
        strtoupper($s['payment_method']),
        number_format($sub, 2),
        number_format($disc, 2),
        number_format($cash, 2),
        number_format($card, 2),
        number_format($trans, 2),
        $returnedAmt > 0 ? number_format($net, 2) . ' (اترجع منها ' . number_format($returnedAmt, 2) . ')' : number_format($net, 2),
        $statusText
    ]);
}

// Summary Rows
excel_row([]);
excel_row(['=== ملخص المبيعات والمتحصلات ===']);
excel_row(['إجمالي المبيعات (قبل المرتجعات)', '', '', '', '', '', '', '', '', number_format($totalNet + $totalReturned, 2), 'درهم']);
if ($returnedCount > 0) {
    excel_row(['إجمالي المرتجعات المستردة (مخصومة)', '', '', '', '', '', '', '', '', '-' . number_format($totalReturned, 2), "عدد $returnedCount مرتجع"]);
}
excel_row(['صافي إيرادات المبيعات المحصلة', '', '', '', number_format($totalSub, 2), number_format($totalDisc, 2), number_format($totalCash, 2), number_format($totalCard, 2), number_format($totalTrans, 2), number_format($totalNet, 2), 'درهم (صافي)']);

// Section: Expenses
excel_row([]);
excel_row(['=== بيان المصاريف التشغيلية التفصيلية (إيجار، رواتب، طعام، باكدجات) ===']);
excel_row(['#', 'التاريخ', 'التصنيف', 'بيان المصروف', 'طريقة الدفع', 'رقم السند/الإيصال', 'المسؤول', 'المبلغ (درهم)'], true);

if (empty($expenses)) {
    excel_row(['لا توجد مصاريف مسجلة في هذه الفترة']);
} else {
    foreach ($expenses as $idx => $e) {
        excel_row([
            $idx + 1,
            $e['expense_date'],
            $categoryTitles[$e['category']] ?? 'أخرى',
            $e['title'],
            $e['payment_method'] === 'cash' ? 'كاش' : ($e['payment_method'] === 'card' ? 'بطاقة' : 'تحويل'),
            $e['receipt_ref'] ?: '—',
            $e['user_name'] ?: 'المدير',
            number_format($e['amount'], 2)
        ]);
    }
}
excel_row(['إجمالي المصاريف التشغيلية', '', '', '', '', '', '', number_format($totalExpenses, 2) . ' درهم']);

// Section: Final P&L
$finalNetProfit = $totalNet - $totalExpenses;
excel_row([]);
excel_row(['=== النتيجة المالية وقائمة الأرباح والخسائر النهائية (P&L) ===']);
excel_row(['صافي إيرادات المبيعات المحصلة الفعلية', '', '', '', '', '', '', number_format($totalNet, 2), 'درهم']);
excel_row(['يخصم: إجمالي المصاريف التشغيلية', '', '', '', '', '', '', '-' . number_format($totalExpenses, 2), 'درهم']);
excel_row(['صافي الربح الفعلي النهائي لصاحب المتجر', '', '', '', '', '', '', number_format($finalNetProfit, 2), $finalNetProfit >= 0 ? 'درهم (أرباح صافية)' : 'درهم (خسارة)']);

echo '</table></body></html>';
exit;
