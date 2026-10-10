<?php
$pageTitle = 'إدارة الأقسام والتصنيفات / Categories';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'stock'], 'manage_products');

$error = '';
$success = '';

// Handle Create Category
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $sort = intval($_POST['sort'] ?? 0);

    if (empty($nameAr)) {
        $error = 'يرجى كتابة اسم القسم بالعربية.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (name_ar, name_en, sort) VALUES (?, ?, ?)");
            $stmt->execute([$nameAr, $nameEn ?: $nameAr, $sort]);
            logAudit($pdo, 'create', 'category', $pdo->lastInsertId(), null, ['name_ar' => $nameAr]);
            $success = 'تمت إضافة القسم بنجاح!';
        } catch (Exception $e) {
            $error = 'خطأ أثناء الإضافة: ' . $e->getMessage();
        }
    }
}

// Handle Update Category
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $catId = intval($_POST['id'] ?? 0);
    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $sort = intval($_POST['sort'] ?? 0);

    if ($catId <= 0 || empty($nameAr)) {
        $error = 'بيانات التعديل غير صحيحة.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE categories SET name_ar = ?, name_en = ?, sort = ? WHERE id = ?");
            $stmt->execute([$nameAr, $nameEn ?: $nameAr, $sort, $catId]);
            logAudit($pdo, 'update', 'category', $catId, null, ['name_ar' => $nameAr]);
            $success = 'تم تحديث بيانات القسم بنجاح!';
        } catch (Exception $e) {
            $error = 'خطأ أثناء التعديل: ' . $e->getMessage();
        }
    }
}

// Handle Delete Category
// الحذف هنا لسه رابط GET عادي (تصميم قديم) بدل POST — عشان نقفل ثغرة CSRF
// عليه من غير ما نعيد بناء الشاشة، بنطلب نفس توكن السيشن كـ query param
// (متولّد جوه الرابط نفسه وقت العرض، مش تخمين ممكن لموقع تاني)
if (isset($_GET['delete'])) {
    if (!verifyCsrf($_GET['csrf_token'] ?? null)) {
        $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
    } else {
    $delId = intval($_GET['delete']);
    // Check if products exist in this category
    $prodCheck = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ? AND is_active = 1");
    $prodCheck->execute([$delId]);
    $hasProducts = $prodCheck->fetchColumn();

    if ($hasProducts > 0) {
        $error = "لا يمكن حذف هذا القسم لأنه يحتوي على ($hasProducts) منتج. يرجى نقل أو حذف المنتجات أولاً.";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$delId]);
            logAudit($pdo, 'delete', 'category', $delId);
            $success = 'تم حذف القسم بنجاح!';
        } catch (Exception $e) {
            $error = 'خطأ أثناء الحذف: ' . $e->getMessage();
        }
    }
    }
}

// Fetch all categories with product count
$sql = "SELECT c.*, COUNT(p.id) AS products_count
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
        GROUP BY c.id
        ORDER BY c.sort ASC, c.id ASC";
$categories = $pdo->query($sql)->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">إدارة الأقسام والتصنيفات</h1>
        <p class="text-subtle mb-0">إضافة وتعديل أقسام المنتجات لعرضها في نقطة البيع والمنتجات</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/products/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right me-1"></i> العودة للمنتجات
        </a>
        <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="bi bi-plus-lg me-1"></i> إضافة قسم جديد
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?= e($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Categories Table -->
<div class="panel">
    <div class="panel-header d-flex justify-content-between align-items-center">
        <span>قائمة الأقسام (<?= count($categories) ?>)</span>
    </div>
    <div class="panel-body p-0">
        <div class="table-responsive border-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th>اسم القسم (عربي)</th>
                        <th>اسم القسم (English)</th>
                        <th class="text-center" style="width: 120px;">الترتيب</th>
                        <th class="text-center" style="width: 140px;">عدد المنتجات</th>
                        <th class="text-end" style="width: 150px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">لا توجد أقسام حتى الآن. أضف أول قسم!</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $index => $cat): ?>
                            <tr>
                                <td class="fw-bold text-muted"><?= $index + 1 ?></td>
                                <td class="fw-bold text-dark fs-6"><?= e($cat['name_ar']) ?></td>
                                <td class="text-secondary"><?= e($cat['name_en']) ?></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?= $cat['sort'] ?></span></td>
                                <td class="text-center">
                                    <span class="badge rounded-pill bg-primary-subtle text-primary fw-bold px-3 py-2">
                                        <i class="bi bi-box-seam me-1"></i> <?= $cat['products_count'] ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary me-1" 
                                            onclick="openEditModal(<?= $cat['id'] ?>, '<?= e(addslashes($cat['name_ar'])) ?>', '<?= e(addslashes($cat['name_en'])) ?>', <?= $cat['sort'] ?>)"
                                            title="تعديل">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="?delete=<?= $cat['id'] ?>&csrf_token=<?= e(csrfToken()) ?>"
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('هل أنت متأكد من حذف قسم (<?= e(addslashes($cat['name_ar'])) ?>)؟')"
                                       title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add Category -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content border-0 shadow">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-folder-plus text-primary me-2"></i> إضافة قسم جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">اسم القسم بالعربية <span class="text-danger">*</span></label>
                    <input type="text" name="name_ar" class="form-control" placeholder="مثال: أدوات تخييم، خيام، كراسي..." required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">اسم القسم بالإنجليزية (English)</label>
                    <input type="text" name="name_en" class="form-control" placeholder="e.g. Tents, Camping Gear...">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">ترتيب الظهور</label>
                    <input type="number" name="sort" class="form-control" value="0" min="0">
                    <small class="text-muted">الأرقام الأصغر تظهر أولاً في نقطة البيع.</small>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-check-lg me-1"></i> حفظ القسم</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Category -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content border-0 shadow">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editCatId">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i> تعديل بيانات القسم</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">اسم القسم بالعربية <span class="text-danger">*</span></label>
                    <input type="text" name="name_ar" id="editCatNameAr" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">اسم القسم بالإنجليزية (English)</label>
                    <input type="text" name="name_en" id="editCatNameEn" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">ترتيب الظهور</label>
                    <input type="number" name="sort" id="editCatSort" class="form-control" min="0">
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary fw-bold px-4"><i class="bi bi-check-lg me-1"></i> حفظ التعديل</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, nameAr, nameEn, sort) {
    document.getElementById('editCatId').value = id;
    document.getElementById('editCatNameAr').value = nameAr;
    document.getElementById('editCatNameEn').value = nameEn;
    document.getElementById('editCatSort').value = sort;
    const modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    modal.show();
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
