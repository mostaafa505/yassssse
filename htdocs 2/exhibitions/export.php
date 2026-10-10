<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAccess(['admin', 'accountant'], 'manage_exhibitions');

$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM exhibitions WHERE id = ?");
$stmt->execute([$id]);
$ex = $stmt->fetch();

if (!$ex) {
    die('المعرض غير موجود');
}

$startDate = $ex['start_date'];
$endDate = $ex['end_date'];
$daysCount = (int)ceil((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;

// Category titles
$categoryTitles = [
    'booth_rent'         => 'إيجار الجناح / المساحة',
    'staff_wages'        => 'رواتب وبدلات الموظفين',
    'hospitality_food'   => 'طعام وإعاشة وضيافة',
    'packaging_supplies' => 'أكياس وباكدجات وتغليف',
    'shipping_transport' => 'نقل وشحن وتوصيل',
    'setup_decoration'   => 'تجهيز وديكور الجناح',
    'marketing_ads'      => 'تسويق وإعلانات المعرض',
    'utilities_permits'  => 'رسوم وتصاريح وكهرباء',
    'other'              => 'مصاريف أخرى ونثرية',
];

// Fetch expenses
$stmtExp = $pdo->prepare("
    SELECT ee.*, u.name_ar as user_name 
    FROM exhibition_expenses ee
    LEFT JOIN users u ON ee.created_by = u.id
    WHERE ee.exhibition_id = ?
    ORDER BY ee.expense_date ASC, ee.id ASC
");
$stmtExp->execute([$id]);
$expenses = $stmtExp->fetchAll();
$totalExpenses = array_sum(array_column($expenses, 'amount'));

// Fetch Sales — كل الفواتير بغض النظر عن حالتها، والصافي بيتحسب بطرح
// المرتجع الفعلي (من جدول returns) لكل فاتورة بدل استبعاد المرتجع الجزئي بالكامل
$stmtSales = $pdo->prepare("
    SELECT
        COALESCE(SUM(total), 0) as gross_sales_raw,
        COALESCE(SUM((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = sales.id)), 0) as returned_sales,
        COUNT(*) as invoice_count,
        COUNT(CASE WHEN (SELECT SUM(r.total) FROM returns r WHERE r.sale_id = sales.id) > 0 THEN 1 END) as returned_count
    FROM sales
    WHERE (exhibition_id = ? OR (exhibition_id IS NULL AND DATE(created_at) BETWEEN ? AND ?))
");
$stmtSales->execute([$id, $startDate, $endDate]);
$salesData = $stmtSales->fetch();

$returnedSales = (float)$salesData['returned_sales'];
$grossSales = (float)$salesData['gross_sales_raw'];
$netSales = max(0, $grossSales - $returnedSales);
$completedCount = $salesData['invoice_count'] - $salesData['returned_count'];

// Fetch Products Breakdown & COGS — صافي الكمية/الإيراد لكل بند (المباع ناقص
// أي كمية/مبلغ اترجع منه)، بدل استبعاد فاتورة المرتجع الجزئي بالكامل
$stmtProds = $pdo->prepare("
    SELECT
        si.product_name_ar,
        si.color_ar,
        si.size,
        COALESCE(si.cost_price, pv.cost_price, p.cost_price, 0) as unit_cost,
        SUM(si.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) as total_qty_sold,
        SUM(si.total - COALESCE((SELECT SUM(ri.total) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) as total_revenue,
        SUM((si.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price, pv.cost_price, p.cost_price, 0)) as total_cost
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN product_variants pv ON si.variant_id = pv.id
    WHERE (s.exhibition_id = ? OR (s.exhibition_id IS NULL AND DATE(s.created_at) BETWEEN ? AND ?))
    GROUP BY si.product_id, si.variant_id, si.product_name_ar, si.color_ar, si.size, unit_cost
    HAVING total_qty_sold > 0
    ORDER BY total_revenue DESC
");
$stmtProds->execute([$id, $startDate, $endDate]);
$products = $stmtProds->fetchAll();
foreach ($products as &$p) {
    $p['product_gross_profit'] = (float)$p['total_revenue'] - (float)$p['total_cost'];
}
unset($p);

$totalCogs = array_sum(array_column($products, 'total_cost'));
$grossProfit = $netSales - $totalCogs;
$netProfit = $grossProfit - $totalExpenses;
$roi = ($totalCogs + $totalExpenses) > 0 ? ($netProfit / ($totalCogs + $totalExpenses)) * 100 : 0;

$filename = "تقرير_أرباح_معرض_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $ex['title']) . "_" . date('Y-m-d') . ".xls";
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
 // UTF-8 BOM

// Section 1: Overview
excel_row(['تقرير أرباح وخسائر المعرض - ' . APP_NAME]);
excel_row(['اسم المعرض:', $ex['title']]);
excel_row(['الموقع:', $ex['location'] ?: 'غير محدد']);
excel_row(['الفترة:', 'من ' . $startDate . ' إلى ' . $endDate, 'المدة:', $daysCount . ' أيام']);
excel_row(['الميزانية المرصودة:', number_format($ex['budget'], 2) . ' درهم']);
excel_row([]);

// Section 2: P&L Summary
excel_row(['=== قائمة الدخل وتقرير الأرباح والخسائر (P&L) ===']);
excel_row(['البند المحاسبي', 'المبلغ (درهم)', 'ملاحظات']);
excel_row(['إجمالي المبيعات الصادرة (Gross Sales)', number_format($grossSales, 2), "عدد " . $salesData['invoice_count'] . " فاتورة"]);
excel_row(['يخصم: المرتجعات والمبالغ المستردة', '-' . number_format($returnedSales, 2), "عدد " . $salesData['returned_count'] . " فاتورة فيها مرتجع"]);
excel_row(['صافي إيرادات المبيعات المحصلة (Net Sales)', number_format($netSales, 2), "عدد " . $completedCount . " فاتورة بدون مرتجع"]);
excel_row(['يخصم: تكلفة البضاعة المباعة (COGS)', '-' . number_format($totalCogs, 2), 'تكلفة القطع المباعة فعلياً']);
excel_row(['مجمل ربح البضاعة (Gross Profit)', number_format($grossProfit, 2), 'فارق سعر البيع عن التكلفة']);
excel_row(['يخصم: إجمالي مصاريف المعرض والتشغيل', '-' . number_format($totalExpenses, 2), "إجمالي " . count($expenses) . " بند مصروف"]);
excel_row([
    'صافي الربح / الخسارة النهائي للمعرض',
    number_format($netProfit, 2),
    $netProfit > 0 ? "معرض رابح (+ العائد على الاستثمار " . number_format($roi, 1) . "%)" : "معرض خاسر"
]);
excel_row([]);

// Section 3: Expenses Log
excel_row(['=== بنود مصاريف المعرض التفصيلية ===']);
excel_row(['التاريخ', 'التصنيف', 'بيان المصروف', 'المبلغ (درهم)', 'طريقة الدفع', 'رقم السند/الإيصال', 'المسؤول', 'ملاحظات']);
foreach ($expenses as $e) {
    excel_row([
        $e['expense_date'],
        $categoryTitles[$e['category']] ?? 'أخرى',
        $e['title'],
        number_format($e['amount'], 2),
        $e['payment_method'] === 'cash' ? 'كاش' : ($e['payment_method'] === 'card' ? 'بطاقة' : 'تحويل'),
        $e['receipt_ref'] ?: '',
        $e['user_name'] ?: 'المدير',
        $e['notes'] ?: ''
    ]);
}
excel_row(['', '', 'إجمالي المصاريف:', number_format($totalExpenses, 2) . ' درهم', '', '', '', '']);
excel_row([]);

// Section 4: Products Breakdown
excel_row(['=== المنتجات المعروضة والمباعة في المعرض ===']);
excel_row(['اسم المنتج', 'الخيار / المقاس', 'الكمية المباعة', 'سعر التكلفة (درهم)', 'إجمالي التكلفة (درهم)', 'إجمالي الإيراد (درهم)', 'صافي ربح الصنف (درهم)', 'هامش الربح']);
foreach ($products as $p) {
    $pRev = (float)$p['total_revenue'];
    $pProf = (float)$p['product_gross_profit'];
    $pMargin = $pRev > 0 ? ($pProf / $pRev) * 100 : 0;

    excel_row([
        $p['product_name_ar'],
        trim($p['color_ar'] . ' ' . $p['size']) ?: '—',
        $p['total_qty_sold'],
        number_format($p['unit_cost'], 2),
        number_format($p['total_cost'], 2),
        number_format($pRev, 2),
        number_format($pProf, 2),
        number_format($pMargin, 1) . '%'
    ]);
}
excel_row(['', '', 'الإجمالي:', '', number_format($totalCogs, 2), number_format($netSales, 2), number_format($grossProfit, 2), '']);

echo '</table></body></html>';
exit;
