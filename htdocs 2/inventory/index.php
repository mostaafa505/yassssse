<?php
$pageTitle = 'المخزون / Inventory';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'stock'], 'manage_inventory');

$search = trim($_GET['q'] ?? '');
$catId = intval($_GET['cat'] ?? 0);
$lowStock = !empty($_GET['low_stock']);

$sql = "
    SELECT p.*, c.name_ar as category_name_ar,
    (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) as variants_count,
    (SELECT COALESCE(SUM(pv.stock_qty), 0) FROM product_variants pv WHERE pv.product_id = p.id) as variants_stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (p.name_ar LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

if ($catId > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $catId;
}

$sql .= " ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort ASC, name_ar ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">إدارة المخزون</h1>
        <p class="text-subtle mb-0">مراقبة كميات المنتجات الحالية واستلام وتوريد بضاعة جديدة</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/inventory/export.php" class="btn btn-outline-success fw-bold">
            <i class="bi bi-file-earmark-excel me-1"></i> تصدير إكسل (Excel)
        </a>
        <a href="<?= BASE_URL ?>/inventory/receive.php" class="btn btn-primary fw-bold">
            <i class="bi bi-box-arrow-in-down me-1"></i> استلام بضاعة جديدة
        </a>
        <a href="<?= BASE_URL ?>/inventory/transfer.php" class="btn btn-light border">
            <i class="bi bi-arrow-left-right me-1"></i> تحويل مخزن / معرض
        </a>
        <a href="<?= BASE_URL ?>/inventory/movements.php" class="btn btn-light border">
            <i class="bi bi-clock-history me-1"></i> سجل الحركات
        </a>
        <a href="<?= BASE_URL ?>/inventory/stocktake.php" class="btn btn-light border">
            <i class="bi bi-clipboard-check me-1"></i> الجرد
        </a>
    </div>
</div>

<!-- Filters -->
<div class="panel mb-4">
    <div class="panel-body">
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-6">
                <input type="text" name="q" class="form-control" placeholder="البحث بالاسم، الكود، الباركود..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-4">
                <select name="cat" class="form-select">
                    <option value="">جميع التصنيفات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $catId == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name_ar']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold">تصفية</button>
            </div>
        </form>
    </div>
</div>

<!-- Inventory Table -->
<div class="panel">
    <div class="table-wrapper">
        <div class="table-responsive border-0">
<table class="table table-hover mb-0">
            <thead style="background: var(--surface-default);">
                <tr>
                    <th class="text-caption text-subtle border-bottom-0" style="width: 60px;">الصورة</th>
                    <th class="text-caption text-subtle border-bottom-0">المنتج</th>
                    <th class="text-caption text-subtle border-bottom-0">الكود / الباركود</th>
                    <th class="text-caption text-subtle border-bottom-0">التصنيف</th>
                    <th class="text-caption text-subtle border-bottom-0">الكمية الإجمالية</th>
                    <th class="text-caption text-subtle border-bottom-0">تفاصيل الخيارات والألوان</th>
                    <th class="text-caption text-subtle border-bottom-0">الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-subtle">
                            <i class="bi bi-boxes fs-1 d-block mb-3 text-tertiary"></i>
                            لا توجد منتجات.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php
                            $totalStock = $p['has_variants'] ? $p['variants_stock'] : $p['stock_qty'];
                            $isLow = $totalStock <= $p['min_stock_alert'];

                            $variantsList = [];
                            if ($p['has_variants']) {
                                $stmtV = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ?");
                                $stmtV->execute([$p['id']]);
                                $variantsList = $stmtV->fetchAll();
                            }
                        ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['image']) && file_exists(dirname(__DIR__) . '/uploads/products/' . $p['image'])): ?>
                                    <img src="<?= UPLOAD_URL ?>products/<?= e($p['image']) ?>" alt="" class="rounded-2 border border-light" style="width: 44px; height: 44px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded-2 bg-light d-flex align-items-center justify-content-center text-tertiary border" style="width: 44px; height: 44px;">
                                        <i class="bi bi-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold text-dark mb-1"><?= e($p['name_ar']) ?></div>
                                <div class="text-caption text-subtle"><?= e($p['name_en']) ?></div>
                            </td>
                            <td>
                                <div class="text-caption mb-1"><span class="text-subtle">SKU:</span> <code class="num font-monospace" dir="ltr" style="display: inline-block; text-align: left;"><?= e($p['sku'] ?: '—') ?></code></div>
                                <?php if ($p['barcode']): ?>
                                    <div class="text-caption"><span class="text-subtle">باركود:</span> <code class="num font-monospace" dir="ltr" style="display: inline-block; text-align: left;"><?= e($p['barcode']) ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);"><?= e($p['category_name_ar'] ?: 'عام') ?></span></td>
                            <td>
                                <strong class="num fs-6 <?= $isLow ? 'text-danger' : 'text-success' ?>">
                                    <?= (float)$totalStock ?> قطعة
                                </strong>
                            </td>
                            <td>
                                <?php if ($p['has_variants'] && !empty($variantsList)): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($variantsList as $v): ?>
                                            <span class="badge num" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">
                                                <?= e($v['color_ar']) ?> <?= e($v['size']) ?>: <strong class="<?= $v['stock_qty'] <= 2 ? 'text-danger' : 'text-primary' ?> ms-1"><?= (float)$v['stock_qty'] ?></strong>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-caption text-subtle">منتج بدون خيارات</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($totalStock <= 0): ?>
                                    <span class="badge" style="background: var(--intent-danger-bg); color: var(--intent-danger); border: 1px solid rgba(239,68,68,0.2);">نفد المخزون</span>
                                <?php elseif ($isLow): ?>
                                    <span class="badge" style="background: var(--intent-warning-bg); color: var(--intent-warning); border: 1px solid rgba(217,119,6,0.2);">قارب النفاد</span>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--intent-success-bg); color: var(--intent-success); border: 1px solid rgba(16,185,129,0.2);">متوفر</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
</div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
