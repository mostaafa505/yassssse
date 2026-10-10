<?php
$content = file_get_contents('/Applications/XAMPP/xamppfiles/htdocs/store/products/index.php');

$clean_part_1 = substr($content, 0, strpos($content, '<div class="d-flex justify-content-between align-items-center mb-4">'));

$new_content = $clean_part_1 . '
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">المنتجات</h4>
        <p class="text-muted small mb-0">إدارة وتسجيل المنتجات وتفاصيلها</p>
    </div>
    <?php if (in_array($_SESSION[\'user_role\'], [\'admin\', \'stock\'])): ?>
    <a href="<?= BASE_URL ?>/products/create.php" class="btn btn-primary fw-medium">
        <i class="bi bi-plus-lg me-1"></i> منتج جديد
    </a>
    <?php endif; ?>
</div>

<div class="panel mb-4">
    <div class="panel-body">
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-5">
                <input type="text" name="q" class="form-control" placeholder="البحث بالاسم، SKU، أو الباركود..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-4">
                <select name="cat" class="form-select">
                    <option value="">جميع التصنيفات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat[\'id\'] ?>" <?= $catId == $cat[\'id\'] ? \'selected\' : \'\' ?>>
                            <?= e($cat[\'name_ar\']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-dark w-100 fw-medium">تصفية</button>
                <?php if ($search !== \'\' || $catId > 0): ?>
                    <a href="<?= BASE_URL ?>/products/" class="btn btn-light px-3"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th style="width: 70px;">الصورة</th>
                    <th>المنتج</th>
                    <th>الكود / الباركود</th>
                    <th>التصنيف</th>
                    <th class="text-end">التجزئة</th>
                    <th class="text-end">العرض</th>
                    <th class="text-end">الجملة</th>
                    <th class="text-center">المخزون</th>
                    <th class="text-center" style="width: 140px;">الخيارات</th>
                </tr>
            </thead>
            <tbody>
';

$part_2 = substr($content, strpos($content, '<?php if (empty($products)): ?>'));
file_put_contents('/Applications/XAMPP/xamppfiles/htdocs/store/products/index.php', $new_content . $part_2);
echo "Fixed!";
