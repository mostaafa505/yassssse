<?php
$pageTitle = 'تعديل منتج / Edit Product';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin'], 'manage_products');

$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    redirect('products/index.php');
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort ASC, name_ar ASC")->fetchAll();
$variants = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ?");
$variants->execute([$id]);
$existingVariants = $variants->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $barcode = trim($_POST['barcode'] ?? '');
    $categoryId = intval($_POST['category_id'] ?? 0) ?: null;
    $retailPrice = floatval($_POST['retail_price'] ?? 0);
    $offerPrice = !empty($_POST['offer_price']) ? floatval($_POST['offer_price']) : null;
    $wholesalePrice = !empty($_POST['wholesale_price']) ? floatval($_POST['wholesale_price']) : null;
    // سعر التكلفة ميتغيّرش من غير صلاحية view_cost — لو المستخدم مالوش الصلاحية
    // دي، الحقل مش هيظهرله في الفورم أصلاً، فبنحافظ على القيمة القديمة زي ما
    // هي بدل ما نمسحها (نمسحها كان غلط: أي تعديل تاني في المنتج من مستخدم
    // مالوش الصلاحية كان هيصفّر سعر التكلفة بالغلط من غير قصد)
    $costPrice = hasPermission('view_cost')
        ? (!empty($_POST['cost_price']) ? floatval($_POST['cost_price']) : null)
        : $product['cost_price'];
    $stockQty = floatval($_POST['stock_qty'] ?? 0);
    $minStock = floatval($_POST['min_stock_alert'] ?? 5);
    $hasVariants = !empty($_POST['has_variants']) ? 1 : 0;
    $descriptionAr = trim($_POST['description_ar'] ?? '');
    $descriptionEn = trim($_POST['description_en'] ?? '');

    if (empty($nameAr)) {
        $error = 'يرجى إدخال اسم المنتج باللغة العربية على الأقل.';
    } elseif ($retailPrice <= 0 && !$hasVariants) {
        $error = 'يرجى إدخال سعر تجزئة صالح للمنتج.';
    } elseif ($barcode !== '' && barcodeExists($pdo, $barcode, $id)) {
        $error = 'الباركود "' . $barcode . '" مستخدم بالفعل لمنتج أو خيار (لون/مقاس) آخر. كل باركود لازم يكون فريد عشان الماسح الضوئي يجيب الصنف الصح.';
    } else {
        // تحقق من تكرار الباركود بين خيارات المنتج نفسه، وإنه مش مستخدم في منتج/خيار
        // تاني (باستثناء خيارات نفس المنتج ده، لأنها هتتمسح وتتحفظ من جديد بنفس الـ id)
        $seenVariantBarcodes = [];
        if ($hasVariants && !empty($_POST['variants'])) {
            foreach ($_POST['variants'] as $v) {
                $vBarcode = trim($v['barcode'] ?? '');
                if ($vBarcode === '') continue;
                if (isset($seenVariantBarcodes[$vBarcode]) || $vBarcode === $barcode) {
                    $error = 'الباركود "' . $vBarcode . '" مكرر أكتر من مرة بين خيارات نفس المنتج.';
                    break;
                }
                if (barcodeExists($pdo, $vBarcode, null, $id)) {
                    $error = 'الباركود "' . $vBarcode . '" مستخدم بالفعل لمنتج أو خيار آخر.';
                    break;
                }
                $seenVariantBarcodes[$vBarcode] = true;
            }
        }
    }

    if (!$error) {
        $imageName = $product['image'];
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $detectedMime = mime_content_type($_FILES['image']['tmp_name']);
                if (in_array($detectedMime, $allowedMimes)) {
                    $targetDir = dirname(__DIR__) . '/uploads/products/';
                    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                    $baseName = 'prod_' . time() . '_' . uniqid();
                    $compressed = compressAndSaveImage($_FILES['image']['tmp_name'], $targetDir, $baseName);
                    if ($compressed) {
                        $imageName = $compressed;
                    } else {
                        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                        $newImageName = $baseName . '.' . $ext;
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetDir . $newImageName)) {
                            $imageName = $newImageName;
                        } else {
                            $error = 'حدث خطأ أثناء حفظ الصورة في الخادم. تأكد من صلاحيات المجلد.';
                        }
                    }
                } else {
                    $error = 'صيغة الصورة غير مدعومة. يرجى رفع صورة بصيغة JPG, PNG أو WEBP.';
                }
            } else {
                $error = 'فشل رفع الصورة. الحجم قد يكون كبيراً جداً (الحد الأقصى ' . ini_get('upload_max_filesize') . ').';
            }
        }

        if (!$error) {

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE products SET
                name_ar = ?, name_en = ?, sku = ?, barcode = ?, category_id = ?, image = ?,
                description_ar = ?, description_en = ?, retail_price = ?, offer_price = ?, wholesale_price = ?,
                cost_price = ?, has_variants = ?, stock_qty = ?, min_stock_alert = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $nameAr, $nameEn ?: $nameAr, $sku, ($barcode !== '' ? $barcode : null), $categoryId, $imageName,
                $descriptionAr, $descriptionEn, $retailPrice, $offerPrice, $wholesalePrice, $costPrice,
                $hasVariants, $hasVariants ? 0 : $stockQty, $minStock, $id
            ]);

            // Clear and re-insert variants if has_variants is true
            if ($hasVariants) {
                $pdo->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$id]);
                if (!empty($_POST['variants'])) {
                    $stmtVar = $pdo->prepare("
                        INSERT INTO product_variants (product_id, color_ar, color_en, size, barcode, sku, retail_price, offer_price, wholesale_price, cost_price, stock_qty)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($_POST['variants'] as $v) {
                        if (!empty($v['color_ar']) || !empty($v['size']) || !empty($v['barcode'])) {
                            $vRetail = !empty($v['retail_price']) ? floatval($v['retail_price']) : $retailPrice;
                            $vOffer = !empty($v['offer_price']) ? floatval($v['offer_price']) : $offerPrice;
                            $vWholesale = !empty($v['wholesale_price']) ? floatval($v['wholesale_price']) : $wholesalePrice;
                            $vCost = (hasPermission('view_cost') && !empty($v['cost_price'])) ? floatval($v['cost_price']) : $costPrice;
                            $vStock = floatval($v['stock_qty'] ?? 0);

                            $stmtVar->execute([
                                $id,
                                $v['color_ar'] ?? '',
                                $v['color_en'] ?? '',
                                $v['size'] ?? '',
                                (!empty($v['barcode']) ? trim($v['barcode']) : null),
                                $v['sku'] ?? '',
                                $vRetail,
                                $vOffer,
                                $vWholesale,
                                $vCost,
                                $vStock
                            ]);
                        }
                    }
                }
            }

            logAudit($pdo, 'update', 'product', $id, $product, [
                'name_ar' => $nameAr,
                'retail_price' => $retailPrice,
                'offer_price' => $offerPrice,
                'wholesale_price' => $wholesalePrice
            ]);

            $pdo->commit();
            redirect('products/index.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            // راجع نفس التعليق في products/create.php (F-09)
            if ($e instanceof PDOException && (int)$e->getCode() === 23000) {
                $error = 'الباركود ده مستخدم بالفعل لمنتج أو خيار آخر (حصل تعارض وقت الحفظ). يرجى تغيير الباركود والمحاولة تاني.';
            } else {
                $error = 'خطأ أثناء التعديل: ' . $e->getMessage();
            }
        }
        } // End if (!$error)
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">تعديل المنتج <span class="text-muted fs-6">/ Edit: <?= e($product['name_ar']) ?></span></h4>
                <p class="text-muted small mb-0">تحديث تفاصيل المنتج والأسعار والمخزون</p>
            </div>
            <a href="<?= BASE_URL ?>/products/" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-arrow-right me-1"></i> العودة للقائمة
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill ms-2"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data">
            <?= csrfField() ?>
            <!-- Basic Info Card -->
            <div class="panel  mb-4">
                <div class="panel-header bg-light">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle text-primary me-2"></i>البيانات الأساسية للمنتج</h6>
                </div>
                <div class="panel-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اسم المنتج (عربي) *</label>
                            <input type="text" name="name_ar" class="form-control" required value="<?= e($product['name_ar']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اسم المنتج (English)</label>
                            <input type="text" name="name_en" class="form-control" value="<?= e($product['name_en']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">كود المنتج / SKU</label>
                            <input type="text" name="sku" class="form-control" value="<?= e($product['sku']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">الباركود الرئيسي / Barcode</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                                <input type="text" name="barcode" class="form-control" value="<?= e($product['barcode']) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-bold mb-0">التصنيف / Category</label>
                                <a href="<?= BASE_URL ?>/products/categories.php" target="_blank" class="small text-primary text-decoration-none fw-bold" title="إضافة أو تعديل الأقسام"><i class="bi bi-plus-circle"></i> قسم جديد</a>
                            </div>
                            <select name="category_id" class="form-select">
                                <option value="">اختر التصنيف...</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $product['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                        <?= e($cat['name_ar']) ?> (<?= e($cat['name_en']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">صورة المنتج</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                            <?php if ($product['image']): ?>
                                <small class="text-muted mt-1 d-block">الصورة الحالية: <?= e($product['image']) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">حد تنبيه نقص المخزون</label>
                            <input type="number" name="min_stock_alert" class="form-control" value="<?= e($product['min_stock_alert']) ?>" min="0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pricing & Base Stock Card -->
            <div class="panel  mb-4">
                <div class="panel-header bg-light">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-cash-stack text-success me-2"></i>الأسعار والكمية الأساسية (AED)</h6>
                </div>
                <div class="panel-body p-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold text-primary">سعر التجزئة (العادي) *</label>
                            <div class="input-group">
                                <span class="input-group-text">AED</span>
                                <input type="number" step="0.01" name="retail_price" class="form-control" required value="<?= e($product['retail_price']) ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold text-warning">سعر العرض (اختياري)</label>
                            <div class="input-group">
                                <span class="input-group-text">AED</span>
                                <input type="number" step="0.01" name="offer_price" class="form-control" value="<?= e($product['offer_price'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold text-info">سعر الجملة</label>
                            <div class="input-group">
                                <span class="input-group-text">AED</span>
                                <input type="number" step="0.01" name="wholesale_price" class="form-control" value="<?= e($product['wholesale_price'] ?? '') ?>">
                            </div>
                        </div>
                        <?php if (hasPermission('view_cost')): ?>
                        <div class="col-md-3">
                            <label class="form-label fw-bold text-secondary">سعر التكلفة (سري)</label>
                            <div class="input-group">
                                <span class="input-group-text">AED</span>
                                <input type="number" step="0.01" name="cost_price" class="form-control" value="<?= e($product['cost_price'] ?? '') ?>">
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Single stock qty if no variants -->
                        <div class="col-md-4 mt-3" id="singleStockGroup" style="<?= $product['has_variants'] ? 'display:none;' : '' ?>">
                            <label class="form-label fw-bold">الكمية المتوفرة بالمخزون</label>
                            <input type="number" step="1" name="stock_qty" class="form-control" value="<?= e($product['stock_qty']) ?>" min="0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Variants Section (Colors & Sizes) -->
            <div class="panel  mb-4">
                <div class="panel-header bg-light d-flex justify-content-between align-items-center">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="has_variants" id="hasVariantsToggle" value="1" <?= $product['has_variants'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="hasVariantsToggle">
                            المنتج يحتوي على خيارات متعددة (ألوان / مقاسات)
                        </label>
                    </div>
                </div>
                <div class="panel-body p-4" id="variantsContainer" style="<?= $product['has_variants'] ? 'display:block;' : 'display:none;' ?>">
                    <p class="text-muted small mb-3">تفاصيل كل خيار (لون ومقاس مع باركود وكمية وسعر خاص به):</p>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle" id="variantsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>اللون (عربي)</th>
                                    <th>اللون (EN)</th>
                                    <th>المقاس / الحجم</th>
                                    <th>الباركود</th>
                                    <th>سعر التجزئة</th>
                                    <th>سعر العرض</th>
                                    <th>سعر الجملة</th>
                                    <th>الكمية</th>
                                    <th style="width: 50px;">حذف</th>
                                </tr>
                            </thead>
                            <tbody id="variantsTbody">
                                <?php foreach ($existingVariants as $idx => $ev): ?>
                                    <tr>
                                        <td><input type="text" name="variants[<?= $idx ?>][color_ar]" class="form-control form-control-sm" value="<?= e($ev['color_ar']) ?>"></td>
                                        <td><input type="text" name="variants[<?= $idx ?>][color_en]" class="form-control form-control-sm" value="<?= e($ev['color_en']) ?>"></td>
                                        <td><input type="text" name="variants[<?= $idx ?>][size]" class="form-control form-control-sm" value="<?= e($ev['size']) ?>"></td>
                                        <td><input type="text" name="variants[<?= $idx ?>][barcode]" class="form-control form-control-sm" value="<?= e($ev['barcode']) ?>"></td>
                                        <td><input type="number" step="0.01" name="variants[<?= $idx ?>][retail_price]" class="form-control form-control-sm" value="<?= e($ev['retail_price'] ?? '') ?>"></td>
                                        <td><input type="number" step="0.01" name="variants[<?= $idx ?>][offer_price]" class="form-control form-control-sm" value="<?= e($ev['offer_price'] ?? '') ?>"></td>
                                        <td><input type="number" step="0.01" name="variants[<?= $idx ?>][wholesale_price]" class="form-control form-control-sm" value="<?= e($ev['wholesale_price'] ?? '') ?>"></td>
                                        <td><input type="number" step="1" name="variants[<?= $idx ?>][stock_qty]" class="form-control form-control-sm" value="<?= e($ev['stock_qty']) ?>"></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-outline-danger btn-sm p-1 rounded-circle" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold" onclick="addVariantRow()">
                        <i class="bi bi-plus-circle me-1"></i> إضافة خيار (لون / مقاس)
                    </button>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-5">
                <a href="<?= BASE_URL ?>/products/" class="btn btn-light px-4 py-2">إلغاء</a>
                <button type="submit" class="btn btn-primary px-5 py-2 fw-bold">
                    <i class="bi bi-check2-circle me-1"></i> حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let variantIndex = <?= count($existingVariants) ?>;
const hasVariantsToggle = document.getElementById('hasVariantsToggle');
const variantsContainer = document.getElementById('variantsContainer');
const singleStockGroup = document.getElementById('singleStockGroup');

hasVariantsToggle.addEventListener('change', function() {
    if (this.checked) {
        variantsContainer.style.display = 'block';
        singleStockGroup.style.display = 'none';
        if (document.querySelectorAll('#variantsTbody tr').length === 0) {
            addVariantRow();
        }
    } else {
        variantsContainer.style.display = 'none';
        singleStockGroup.style.display = 'block';
    }
});

function addVariantRow(data = {}) {
    const tbody = document.getElementById('variantsTbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="variants[${variantIndex}][color_ar]" class="form-control form-control-sm" placeholder="مثال: أسود" value="${data.color_ar || ''}"></td>
        <td><input type="text" name="variants[${variantIndex}][color_en]" class="form-control form-control-sm" placeholder="Black" value="${data.color_en || ''}"></td>
        <td><input type="text" name="variants[${variantIndex}][size]" class="form-control form-control-sm" placeholder="M, L, XL" value="${data.size || ''}"></td>
        <td><input type="text" name="variants[${variantIndex}][barcode]" class="form-control form-control-sm" placeholder="باركود الخيار" value="${data.barcode || ''}"></td>
        <td><input type="number" step="0.01" name="variants[${variantIndex}][retail_price]" class="form-control form-control-sm" placeholder="افتراضي"></td>
        <td><input type="number" step="0.01" name="variants[${variantIndex}][offer_price]" class="form-control form-control-sm" placeholder="افتراضي"></td>
        <td><input type="number" step="0.01" name="variants[${variantIndex}][wholesale_price]" class="form-control form-control-sm" placeholder="افتراضي"></td>
        <td><input type="number" step="1" name="variants[${variantIndex}][stock_qty]" class="form-control form-control-sm" value="0" min="0"></td>
        <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm p-1 rounded-circle" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button>
        </td>
    `;
    tbody.appendChild(tr);
    variantIndex++;
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
