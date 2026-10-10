<?php
$pageTitle = 'كشف أسعار الجملة / Wholesale Price List';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'accountant'], 'manage_wholesale');

$selectedCategory = intval($_GET['cat'] ?? 0);

$sql = "
    SELECT p.*, c.name_ar as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1
";
if ($selectedCategory > 0) {
    $sql .= " AND p.category_id = " . $selectedCategory;
}
$sql .= " ORDER BY p.name_ar ASC";

$products = $pdo->query($sql)->fetchAll();
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort ASC, name_ar ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h4 class="fw-bold mb-1">كشف أسعار الجملة <span class="text-muted fs-6">/ Wholesale Price List</span></h4>
        <p class="text-muted small mb-0">توليد وطباعة قوائم أسعار الجملة للموزعين والعملاء التجاريين</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-primary rounded-3 px-3 py-2 fw-bold">
            <i class="bi bi-printer me-1"></i> طباعة الكشف
        </button>
        <button onclick="downloadPDF()" class="btn btn-danger rounded-3 px-3 py-2 fw-bold">
            <i class="bi bi-file-earmark-pdf me-1"></i> تصدير PDF
        </button>
    </div>
</div>

<!-- Category Filter -->
<div class="panel border-0 shadow-sm mb-4 no-print">
    <div class="panel-body py-2">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-4">
                <select name="cat" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">جميع التصنيفات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $selectedCategory == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Printable Price List Document -->
<div id="priceListDocument" class="bg-white p-4 rounded-3 border shadow-sm">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-0"><?= APP_NAME ?></h4>
            <small class="text-muted"><?= APP_NAME_EN ?> — Wholesale Price Catalog</small>
        </div>
        <div class="text-end">
            <span class="badge bg-primary fs-6 px-3 py-2">كشف أسعار الجملة</span>
            <div class="small text-muted mt-1">تاريخ الكشف: <?= date('Y-m-d') ?></div>
        </div>
    </div>

    <!-- Table -->
    <div class="table-responsive border-0">
<table class="table table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th style="width: 70px;" class="text-center">الصورة</th>
                <th>اسم المنتج (Product Name)</th>
                <th>الخيارات (الألوان والمقاسات)</th>
                <th>كود الصنف / SKU</th>
                <th class="text-end" style="width: 140px;">سعر الجملة (Wholesale)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $p): ?>
                <?php
                    $variants = [];
                    if ($p['has_variants']) {
                        $stmtV = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ?");
                        $stmtV->execute([$p['id']]);
                        $variants = $stmtV->fetchAll();
                    }
                ?>
                <tr>
                    <td class="text-center">
                        <?php if (!empty($p['image']) && file_exists(dirname(__DIR__) . '/uploads/products/' . $p['image'])): ?>
                            <img src="<?= UPLOAD_URL ?>products/<?= e($p['image']) ?>" alt="" style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px;">
                        <?php else: ?>
                            <div class="bg-light text-muted d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; border-radius: 6px; margin: auto;">
                                <i class="bi bi-image"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong class="text-dark"><?= e($p['name_ar']) ?></strong>
                        <div class="small text-muted"><?= e($p['name_en']) ?></div>
                    </td>
                    <td>
                        <?php if ($p['has_variants'] && !empty($variants)): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($variants as $v): ?>
                                    <span class="badge bg-light text-dark border" style="font-size: 0.75rem;">
                                        <?= e($v['color_ar']) ?> <?= e($v['size']) ?>: <strong><?= money($v['wholesale_price'] ?: ($p['wholesale_price'] ?: $p['retail_price'])) ?></strong>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">موحد</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= e($p['sku'] ?: '—') ?></code></td>
                    <td class="text-end fw-bold fs-6 text-primary">
                        <?= money($p['wholesale_price'] ?: $p['retail_price']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

    <div class="border-top pt-3 mt-4 text-center text-muted small">
        الأسعار بالدرهم الإماراتي (AED) — خاضعة للشروط والأحكام — الأسعار قابلة للتحديث
    </div>
</div>

<!-- html2pdf.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF() {
    const element = document.getElementById('priceListDocument');
    const opt = {
        margin: [10, 10, 10, 10],
        filename: 'كشف_أسعار_الجملة_' + new Date().toISOString().slice(0,10) + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
