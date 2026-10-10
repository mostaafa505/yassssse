<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAuth(['admin', 'accountant', 'cashier']);

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    $stmtFirst = $pdo->query("SELECT id FROM exhibitions ORDER BY id DESC LIMIT 1");
    $id = (int)$stmtFirst->fetchColumn();
}

$stmt = $pdo->prepare("SELECT * FROM exhibitions WHERE id = ?");
$stmt->execute([$id]);
$ex = $stmt->fetch();

if (!$ex) {
    die("المعرض غير موجود.");
}

$startDate = $ex['start_date'];
$endDate = $ex['end_date'];
$brandInfo = getActiveBrandInfo();
$daysCount = (int)ceil((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
if ($daysCount < 1) $daysCount = 1;

$categoryTitles = [
    'booth_rent'         => 'إيجار الجناح والمساحة',
    'staff_wages'        => 'رواتب وأجور الموظفين',
    'hospitality_food'   => 'طعام وإعاشة وضيافة',
    'packaging_supplies' => 'باكدجات وأكياس وتغليف',
    'shipping_transport' => 'نقل وشحن وتجهيز',
    'marketing_ads'      => 'تسويق وإعلانات المعرض',
    'utilities_permits'  => 'رسوم وتصاريح وخدمات',
    'other'              => 'مصاريف أخرى ونثرية',
];

// 1. Fetch Expenses
$stmtExp = $pdo->prepare("
    SELECT ee.*, u.name_ar as user_name
    FROM exhibition_expenses ee
    LEFT JOIN users u ON ee.created_by = u.id
    WHERE ee.exhibition_id = ?
    ORDER BY ee.expense_date ASC, ee.id ASC
");
$stmtExp->execute([$id]);
$expenses = $stmtExp->fetchAll();

$totalExpenses = 0;
foreach ($expenses as $e) {
    $totalExpenses += (float)$e['amount'];
}

// 2. Fetch Sales Summary — كل الفواتير بغض النظر عن حالتها، والصافي بيتحسب
// بطرح المرتجع الفعلي (من جدول returns) لكل فاتورة
$stmtSales = $pdo->prepare("
    SELECT
        COALESCE(SUM(total), 0) as gross_sales_raw,
        COALESCE(SUM((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = sales.id)), 0) as returned_sales,
        COUNT(*) as invoice_count
    FROM sales
    WHERE (exhibition_id = ? OR (exhibition_id IS NULL AND DATE(created_at) BETWEEN ? AND ?))
");
$stmtSales->execute([$id, $startDate, $endDate]);
$salesData = $stmtSales->fetch();

$totalReturns = (float)$salesData['returned_sales'];
$totalSales = max(0, (float)$salesData['gross_sales_raw'] - $totalReturns);
$invoicesCount = (int)$salesData['invoice_count'];

// 3. Fetch Sold Products — صافي الكمية/الإيراد لكل بند (المباع ناقص أي كمية
// اترجعت)، بدل استبعاد فاتورة المرتجع الجزئي بالكامل
$stmtProds = $pdo->prepare("
    SELECT
        si.product_id,
        si.variant_id,
        si.product_name_ar,
        si.color_ar,
        si.size,
        COALESCE(si.cost_price, pv.cost_price, p.cost_price, 0) as unit_cost,
        AVG(si.unit_price) as avg_unit_price,
        SUM(si.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) as total_qty_sold,
        SUM(si.total - COALESCE((SELECT SUM(ri.total) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) as total_sales_amount,
        SUM((si.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)) * COALESCE(si.cost_price, pv.cost_price, p.cost_price, 0)) as total_cost
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    LEFT JOIN products p ON si.product_id = p.id
    LEFT JOIN product_variants pv ON si.variant_id = pv.id
    WHERE (s.exhibition_id = ? OR (s.exhibition_id IS NULL AND DATE(s.created_at) BETWEEN ? AND ?))
    GROUP BY si.product_id, si.variant_id, si.product_name_ar, si.color_ar, si.size, unit_cost
    HAVING total_qty_sold > 0
    ORDER BY total_sales_amount DESC
");
$stmtProds->execute([$id, $startDate, $endDate]);
$products = $stmtProds->fetchAll();
foreach ($products as &$p) {
    $p['product_profit'] = (float)$p['total_sales_amount'] - (float)$p['total_cost'];
}
unset($p);

$totalCogs = 0;
$totalQtySold = 0;
foreach ($products as $p) {
    $totalCogs += (float)$p['total_cost'];
    $totalQtySold += (float)$p['total_qty_sold'];
}

$netProfit = $totalSales - $totalExpenses; // Direct cash profit (Sales - Expenses)
$isProfitable = $netProfit >= 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير أرباح ومصاريف المعرض - <?= e($ex['title']) ?></title>
    <!-- Google Cairo Font -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Cairo', Arial, sans-serif; }
        @page { size: A4 portrait; margin: 10mm 12mm; }
        body {
            background: #f1f5f9;
            color: #000000;
            margin: 0;
            padding: 20px 0;
            direction: rtl;
            font-size: 13px;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Top Action Bar (Screen Only) */
        .action-bar {
            max-width: 210mm;
            margin: 0 auto 15px auto;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }
        .btn-print { background: #1e293b; color: #ffffff; }
        .btn-excel { background: #047857; color: #ffffff; }
        .btn-close { background: #cbd5e1; color: #0f172a; }

        /* Report Paper Sheet */
        .report-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #ffffff;
            padding: 12mm 15mm;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        /* Clean Header */
        .header-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #000000;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-brand img {
            height: 55px;
            max-width: 130px;
            object-fit: contain;
        }
        .header-title {
            font-size: 20px;
            font-weight: 800;
            color: #000000;
            margin: 0;
        }
        .header-sub {
            font-size: 13px;
            color: #475569;
            margin-top: 2px;
            font-weight: 700;
        }
        .header-date {
            text-align: left;
            font-size: 11px;
            color: #475569;
        }

        /* Summary Grid */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .summary-box {
            border: 1.5px solid #000000;
            padding: 12px;
            text-align: center;
            background: #ffffff;
            border-radius: 4px;
        }
        .summary-box.profit {
            background: #f0fdf4;
            border-color: #16a34a;
        }
        .summary-box.loss {
            background: #fef2f2;
            border-color: #dc2626;
        }
        .summary-label {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
        }
        .summary-val {
            font-size: 20px;
            font-weight: 900;
            color: #000000;
            direction: ltr;
        }

        /* Section Title */
        .section-title {
            font-size: 14px;
            font-weight: 800;
            color: #000000;
            background: #f1f5f9;
            padding: 6px 10px;
            border-right: 4px solid #000000;
            margin: 18px 0 10px 0;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
        }

        /* Data Tables */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 12px;
        }
        table.data-table th {
            background: #f8fafc;
            color: #000000;
            font-weight: 800;
            padding: 8px;
            border: 1px solid #000000;
            text-align: right;
        }
        table.data-table td {
            padding: 7px 8px;
            border: 1px solid #000000;
            color: #000000;
        }
        table.data-table tfoot td {
            background: #f1f5f9;
            font-weight: 900;
            font-size: 13px;
            border-top: 2px solid #000000;
        }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-bold { font-weight: 800; }

        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            margin-top: 35px;
            padding-top: 15px;
            border-top: 1px solid #cbd5e1;
            page-break-inside: avoid;
        }
        .sign-item {
            text-align: center;
        }
        .sign-title { font-size: 12px; font-weight: 700; margin-bottom: 30px; }
        .sign-line { border-bottom: 1px dashed #000; width: 60%; margin: 0 auto; }

        @media print {
            body { background: #ffffff !important; padding: 0 !important; }
            .action-bar { display: none !important; }
            .report-sheet { width: 100% !important; box-shadow: none !important; padding: 0 !important; }
        }
    </style>
</head>
<body>

<!-- Screen Action Bar -->
<div class="action-bar">
    <div style="font-weight: 800; font-size: 14px;">تقرير أرباح ومصاريف المعرض</div>
    <div style="display: flex; gap: 8px;">
        <button onclick="window.print()" class="btn btn-print">طباعة التقرير / حفظ PDF</button>
        <a href="<?= BASE_URL ?>/exhibitions/export.php?id=<?= $id ?>" class="btn btn-excel">تصدير إكسل</a>
        <button onclick="window.close()" class="btn btn-close">إغلاق</button>
    </div>
</div>

<!-- Main Printable Sheet -->
<div class="report-sheet">
    
    <!-- 1. Header -->
    <div class="header-box">
        <div class="header-brand">
            <img src="<?= e($brandInfo['logo']) ?>" alt="<?= e($brandInfo['name_en']) ?>">
            <div>
                <h1 class="header-title"><?= APP_NAME ?> — تقرير أرباح ومصاريف المعرض</h1>
                <div class="header-sub"><?= e($ex['title']) ?> <?= $ex['location'] ? ' (' . e($ex['location']) . ')' : '' ?></div>
            </div>
        </div>
        <div class="header-date">
            <div><strong>الفترة:</strong> من <?= $startDate ?> إلى <?= $endDate ?> (<?= $daysCount ?> أيام)</div>
            <div><strong>تاريخ الطباعة:</strong> <?= date('Y-m-d H:i') ?></div>
        </div>
    </div>

    <!-- 2. Direct Financial Summary -->
    <div class="summary-grid">
        <div class="summary-box">
            <div class="summary-label">إجمالي المبيعات (درهم)</div>
            <div class="summary-val" style="color: #16a34a;"><?= number_format($totalSales, 2) ?></div>
            <small style="color: #64748b;">(عدد <?= $invoicesCount ?> فاتورة مبيعات)</small>
        </div>

        <div class="summary-box">
            <div class="summary-label">إجمالي المصاريف (درهم)</div>
            <div class="summary-val" style="color: #dc2626;">-<?= number_format($totalExpenses, 2) ?></div>
            <small style="color: #64748b;">(عدد <?= count($expenses) ?> بند صرف)</small>
        </div>

        <div class="summary-box <?= $isProfitable ? 'profit' : 'loss' ?>">
            <div class="summary-label">صافي الربح النهائي (درهم)</div>
            <div class="summary-val" style="color: <?= $isProfitable ? '#15803d' : '#b91c1c' ?>;"><?= number_format($netProfit, 2) ?></div>
            <small style="font-weight: 700; color: <?= $isProfitable ? '#15803d' : '#b91c1c' ?>;"><?= $isProfitable ? 'معرض رابح' : 'عجز / خاسر' ?></small>
        </div>
    </div>

    <?php if ($totalReturns > 0): ?>
    <div style="background: #fef2f2; border: 1px solid #fca5a5; padding: 6px 12px; font-weight: 700; margin-bottom: 15px; border-radius: 4px; color: #991b1b;">
        تم خصم مرتجعات من الإجمالي بقيمة: -<?= number_format($totalReturns, 2) ?> درهم
    </div>
    <?php endif; ?>

    <!-- 3. Expenses Table -->
    <div class="section-title">أولاً: بنود مصاريف المعرض التفصيلية</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 35px;">#</th>
                <th class="text-center" style="width: 85px;">التاريخ</th>
                <th style="width: 150px;">بند المصروف</th>
                <th>البيان والتفاصيل</th>
                <th class="text-center" style="width: 85px;">طريقة الدفع</th>
                <th class="text-left" style="width: 100px;">المبلغ (درهم)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($expenses)): ?>
                <tr><td colspan="6" class="text-center" style="padding: 12px; color: #64748b;">لا توجد مصاريف مسجلة.</td></tr>
            <?php else: ?>
                <?php $idx = 1; foreach ($expenses as $eItem): ?>
                    <tr>
                        <td class="text-center"><?= $idx++ ?></td>
                        <td class="text-center"><?= e($eItem['expense_date'] ?? '') ?></td>
                        <td class="text-bold"><?= e($categoryTitles[$eItem['category'] ?? ''] ?? 'أخرى') ?></td>
                        <td><?= e($eItem['title'] ?? '') ?> <?= !empty($eItem['notes']) ? '<small style="color:#64748b;">(' . e($eItem['notes']) . ')</small>' : '' ?></td>
                        <td class="text-center"><?= ($eItem['payment_method'] ?? '') === 'cash' ? 'كاش' : (( $eItem['payment_method'] ?? '') === 'card' ? 'بطاقة' : 'تحويل') ?></td>
                        <td class="text-left text-bold"><?= number_format((float)($eItem['amount'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align: right;">إجمالي مصاريف المعرض:</td>
                <td class="text-left text-bold" style="color: #dc2626;"><?= number_format($totalExpenses, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- 4. Sold Products Table -->
    <div class="section-title">ثانياً: مبيعات الأصناف والمنتجات بالمعرض</div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 35px;">#</th>
                <th>اسم المنتج والصنف</th>
                <th class="text-center" style="width: 100px;">الخيار (اللون/المقاس)</th>
                <th class="text-center" style="width: 70px;">الكمية</th>
                <th class="text-left" style="width: 90px;">سعر القطعة</th>
                <th class="text-left" style="width: 110px;">إجمالي المبيعات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="6" class="text-center" style="padding: 12px; color: #64748b;">لا توجد مبيعات مسجلة.</td></tr>
            <?php else: ?>
                <?php $pIdx = 1; foreach ($products as $pItem): 
                    $colorStr = $pItem['color_ar'] ?? '';
                    $sizeStr = $pItem['size'] ?? '';
                    $optionStr = trim($colorStr . ' ' . $sizeStr);
                ?>
                    <tr>
                        <td class="text-center"><?= $pIdx++ ?></td>
                        <td class="text-bold"><?= e($pItem['product_name_ar'] ?? '') ?></td>
                        <td class="text-center"><?= !empty($optionStr) ? e($optionStr) : '—' ?></td>
                        <td class="text-center text-bold"><?= (float)($pItem['total_qty_sold'] ?? 0) ?></td>
                        <td class="text-left"><?= number_format((float)($pItem['avg_unit_price'] ?? 0), 2) ?></td>
                        <td class="text-left text-bold" style="color: #16a34a;"><?= number_format((float)($pItem['total_sales_amount'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align: right;">إجمالي القطع والمبيعات:</td>
                <td class="text-center text-bold"><?= $totalQtySold ?></td>
                <td></td>
                <td class="text-left text-bold" style="color: #16a34a;"><?= number_format($totalSales, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- 5. Signatures -->
    <div class="signatures">
        <div class="sign-item">
            <div class="sign-title">توقيع مسؤول المعرض</div>
            <div class="sign-line"></div>
        </div>
        <div class="sign-item">
            <div class="sign-title">اعتماد صاحب المتجر / الإدارة</div>
            <div class="sign-line"></div>
        </div>
    </div>

</div>

</body>
</html>
