<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAccess(['admin', 'accountant'], 'manage_treasury');

$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
$typeFilter = $_GET['type'] ?? '';

$typeTitles = [
    'funding'          => 'تغذية الصندوق',
    'expense'          => 'مصروف عام',
    'purchase_goods'   => 'شراء بضاعة',
    'purchase_service' => 'شراء مواد/خدمة',
];

$transactions = $pdo->query("
    SELECT t.*, u.name_ar as user_name
    FROM treasury_transactions t
    LEFT JOIN users u ON t.created_by = u.id
    ORDER BY t.transaction_date ASC, t.id ASC
")->fetchAll();

$runningCash = 0.0;
foreach ($transactions as &$t) {
    $signed = ($t['type'] === 'funding') ? floatval($t['amount']) : -floatval($t['amount']);
    if ($t['payment_method'] === 'cash') {
        $runningCash += $signed;
    }
    $t['running_cash_balance'] = $runningCash;
}
unset($t);
$currentBalance = $runningCash;

if ($dateFrom !== '') {
    $transactions = array_filter($transactions, fn($t) => $t['transaction_date'] >= $dateFrom);
}
if ($dateTo !== '') {
    $transactions = array_filter($transactions, fn($t) => $t['transaction_date'] <= $dateTo);
}
if ($typeFilter !== '') {
    $transactions = array_filter($transactions, fn($t) => $t['type'] === $typeFilter);
}

$filename = "سجل_الصندوق_الرئيسي_" . date('Y-m-d') . ".xls";
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

excel_row(['سجل حركات الصندوق الرئيسي - ' . APP_NAME]);
excel_row(['تاريخ التصدير:', date('Y-m-d H:i')]);
excel_row(['الرصيد الحالي (كاش):', number_format($currentBalance, 2) . ' درهم', $currentBalance < 0 ? 'الصندوق في حالة عجز' : 'الصندوق سليم']);
excel_row([]);

excel_row(['=== سجل الحركات ===']);
excel_row(['التاريخ', 'النوع', 'البيان', 'التصنيف', 'المبلغ (درهم)', 'طريقة الدفع', 'الرصيد بعدها (كاش)', 'المسؤول', 'ملاحظات']);
foreach ($transactions as $t) {
    $isIn = $t['type'] === 'funding';
    excel_row([
        $t['transaction_date'],
        $typeTitles[$t['type']] ?? $t['type'],
        $t['title'],
        $t['category'] ?: '',
        ($isIn ? '' : '-') . number_format($t['amount'], 2),
        $t['payment_method'] === 'cash' ? 'كاش' : ($t['payment_method'] === 'card' ? 'بطاقة' : 'تحويل'),
        number_format($t['running_cash_balance'], 2),
        $t['user_name'] ?: 'المدير',
        $t['notes'] ?: ''
    ]);
}
excel_row([]);
excel_row(['الرصيد الحالي (كاش فعلي):', number_format($currentBalance, 2) . ' درهم']);

echo '</table></body></html>';
exit;
