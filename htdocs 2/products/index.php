<?php
$pageTitle = 'المنتجات / Products';
require_once dirname(__DIR__) . '/includes/header.php';

requireAuth(['admin', 'stock', 'accountant', 'cashier']);

// Search and Filters
$search = trim($_GET['q'] ?? '');
$catId = intval($_GET['cat'] ?? 0);
$stockFilter = $_GET['stock'] ?? '';

$sql = "SELECT p.*, c.name_ar as category_name_ar, c.name_en as category_name_en,
        (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) as variants_count,
        (SELECT COALESCE(SUM(pv.stock_qty), 0) FROM product_variants pv WHERE pv.product_id = p.id) as variants_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = 1";

$params = [];

if ($search !== '') {
    $sql .= " AND (p.name_ar LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND (pv.sku LIKE ? OR pv.barcode LIKE ?)))";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
}

if ($catId > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $catId;
}

$sql .= " ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Fetch Categories for filter
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort ASC, name_ar ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">إدارة المنتجات</h1>
        <p class="text-subtle mb-0">عرض وتعديل والتحكم في جميع منتجات المعرض والخيارات والألوان والمقاسات</p>
    </div>
    <?php if (in_array($_SESSION['user_role'], ['admin', 'stock'])): ?>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/products/export.php" class="btn btn-outline-success fw-bold">
            <i class="bi bi-file-earmark-excel me-1"></i> تصدير إكسل (Excel)
        </a>
        <a href="<?= BASE_URL ?>/products/categories.php" class="btn btn-outline-primary fw-bold">
            <i class="bi bi-tags me-1"></i> إدارة الأقسام
        </a>
        <a href="<?= BASE_URL ?>/products/create.php" class="btn btn-primary fw-bold">
            <i class="bi bi-plus-lg me-1"></i> إضافة منتج جديد
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filter Bar -->
<div class="panel mb-4">
    <div class="panel-body">
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="البحث بالاسم، SKU، أو الباركود..." value="<?= e($search) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="cat" class="form-select">
                    <option value="">جميع التصنيفات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $catId == $cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name_ar']) ?> (<?= e($cat['name_en']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">تصفية</button>
                <?php if ($search !== '' || $catId > 0): ?>
                    <a href="<?= BASE_URL ?>/products/" class="btn btn-light"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="panel">
    <div class="table-wrapper">
        <div class="table-responsive border-0">
<table class="table table-hover mb-0">
            <thead style="background: var(--surface-default);">
                <tr>
                    <th class="text-caption text-subtle border-bottom-0" style="width: 70px;">الصورة</th>
                    <th class="text-caption text-subtle border-bottom-0">المنتج</th>
                    <th class="text-caption text-subtle border-bottom-0">التصنيف</th>
                    <th class="text-caption text-subtle border-bottom-0">سعر التجزئة</th>
                    <th class="text-caption text-subtle border-bottom-0">سعر العرض</th>
                    <th class="text-caption text-subtle border-bottom-0">سعر الجملة</th>
                    <th class="text-caption text-subtle border-bottom-0">المخزون المتوفر</th>
                    <th class="text-center text-caption text-subtle border-bottom-0" style="width: 140px;">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-subtle">
                            <i class="bi bi-box-seam fs-1 d-block mb-3 text-tertiary"></i>
                            لا توجد منتجات مطابقة لخيارات البحث.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php 
                            $effectiveStock = $p['has_variants'] ? $p['variants_stock'] : $p['stock_qty'];
                            $isLowStock = $effectiveStock <= $p['min_stock_alert'];
                        ?>
                        <tr>
                            <td>
                                <?php if (!empty($p['image']) && file_exists(dirname(__DIR__) . '/uploads/products/' . $p['image'])): ?>
                                    <img src="<?= UPLOAD_URL ?>products/<?= e($p['image']) ?>" alt="" class="rounded-3 border" style="width: 48px; height: 48px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded-3 bg-light d-flex align-items-center justify-content-center text-muted border" style="width: 48px; height: 48px;">
                                        <i class="bi bi-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold text-dark mb-1"><?= e($p['name_ar']) ?></div>
                                <div class="text-caption text-subtle d-flex gap-2 num">
                                    <span>SKU: <span dir="ltr"><?= e($p['sku'] ?: '—') ?></span></span>
                                    <?php if ($p['barcode']): ?>
                                        <span class="text-tertiary">|</span>
                                        <span><i class="bi bi-upc me-1"></i><span dir="ltr"><?= e($p['barcode']) ?></span></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">
                                    <?= e($p['category_name_ar'] ?: 'بدون تصنيف') ?>
                                </span>
                            </td>
                            <td class="num fw-bold text-primary"><?= money($p['retail_price']) ?></td>
                            <td class="num">
                                <?= $p['offer_price'] ? '<span class="badge" style="background: var(--intent-warning-bg); color: var(--intent-warning); border: 1px solid rgba(217,119,6,0.2);">' . money($p['offer_price']) . '</span>' : '<span class="text-tertiary">—</span>' ?>
                            </td>
                            <td class="num">
                                <?= $p['wholesale_price'] ? '<span class="badge" style="background: #EFF6FF; color: #2563EB; border: 1px solid rgba(37,99,235,0.2);">' . money($p['wholesale_price']) . '</span>' : '<span class="text-tertiary">—</span>' ?>
                            </td>
                            <td class="num">
                                <?php if ($p['has_variants']): ?>
                                    <span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">
                                        <i class="bi bi-diagram-3 me-1"></i> <?= (float)$p['variants_stock'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge <?= $isLowStock ? 'status-inactive' : 'status-active' ?>">
                                        <?= (float)$effectiveStock ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                                    <div class="btn-group">
                                        <a href="<?= BASE_URL ?>/products/edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-light" title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button onclick="deleteProduct(<?= $p['id'] ?>)" class="btn btn-sm btn-light text-danger" title="حذف">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-subtle small">مشاهدة فقط</span>
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

<script>
function deleteProduct(id) {
    if (confirm('هل أنت متأكد من حذف هذا المنتج؟')) {
        fetch('<?= BASE_URL ?>/products/delete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'حدث خطأ أثناء الحذف');
            }
        });
    }
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
