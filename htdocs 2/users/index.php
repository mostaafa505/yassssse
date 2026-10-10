<?php
$pageTitle = 'المستخدمون والصلاحيات / Users & Permissions';
require_once dirname(__DIR__) . '/includes/header.php';

requireAuth(['admin']);

$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="title-large mb-1">إدارة المستخدمين والصلاحيات</h1>
        <p class="text-subtle mb-0">إضافة الكاشيرات ومسؤولي المخزن والمحاسبين وتعيين صلاحياتهم</p>
    </div>
    <a href="<?= BASE_URL ?>/users/create.php" class="btn btn-primary">
        <i class="bi bi-person-plus me-1"></i> إضافة مستخدم جديد
    </a>
</div>

<!-- Users Table -->
<div class="panel">
    <div class="table-wrapper">
        <div class="table-responsive border-0">
<table class="table table-hover mb-0">
            <thead style="background: var(--surface-default);">
                <tr>
                    <th class="text-caption text-subtle border-bottom-0" style="width: 80px;">رقم الكاشير</th>
                    <th class="text-caption text-subtle border-bottom-0">الاسم</th>
                    <th class="text-caption text-subtle border-bottom-0">البريد الإلكتروني</th>
                    <th class="text-caption text-subtle border-bottom-0">الدور الوظيفي / Role</th>
                    <th class="text-caption text-subtle border-bottom-0">الحالة</th>
                    <th class="text-caption text-subtle border-bottom-0">تاريخ الإنشاء</th>
                    <th class="text-center text-caption text-subtle border-bottom-0">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <span class="badge bg-light text-dark border fw-bold fs-6">#<?= (int)$u['id'] ?></span>
                        </td>
                        <td>
                            <div class="fw-bold text-dark mb-1"><span dir="rtl"><?= e($u['name_ar']) ?></span></div>
                            <div class="text-caption text-subtle"><span dir="rtl"><?= e($u['name_en']) ?></span></div>
                        </td>
                        <td><span class="text-subtle font-monospace"><?= e($u['email']) ?></span></td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="badge" style="background: var(--intent-danger-bg); color: var(--intent-danger); border: 1px solid rgba(239,68,68,0.2);">المدير العام</span>
                            <?php elseif ($u['role'] === 'cashier'): ?>
                                <span class="badge" style="background: var(--intent-success-bg); color: var(--intent-success); border: 1px solid rgba(16,185,129,0.2);">كاشير</span>
                            <?php elseif ($u['role'] === 'stock'): ?>
                                <span class="badge" style="background: var(--intent-warning-bg); color: var(--intent-warning); border: 1px solid rgba(217,119,6,0.2);">مسؤول مخزن</span>
                            <?php else: ?>
                                <span class="badge" style="background: #EFF6FF; color: #2563EB; border: 1px solid rgba(37,99,235,0.2);">محاسب</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $u['is_active'] ? '<span class="badge status-active">نشط</span>' : '<span class="badge status-inactive">معطل</span>' ?>
                        </td>
                        <td class="text-subtle num"><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                        <td class="text-center">
                            <a href="<?= BASE_URL ?>/users/edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-light">
                                <i class="bi bi-pencil me-1"></i> تعديل الصلاحيات
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
