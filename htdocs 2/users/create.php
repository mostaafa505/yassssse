<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAuth(['admin']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'cashier';
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $customPerms = !empty($_POST['permissions']) ? json_encode($_POST['permissions']) : json_encode([]);

    if (empty($nameAr) || empty($email) || empty($password)) {
        $error = 'يرجى ملء جميع الحقول الإلزامية.';
    } else {
        // Check email uniqueness
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            $error = 'البريد الإلكتروني مسجل مسبقاً لمستخدم آخر.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (name_ar, name_en, email, password, role, permissions, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nameAr, $nameEn ?: $nameAr, $email, $hash, $role, $customPerms, $isActive]);
            logAudit($pdo, 'create_user', 'user', $pdo->lastInsertId(), null, ['name_ar' => $nameAr, 'role' => $role, 'email' => $email]);
            redirect('users/index.php');
        }
    }
}

$pageTitle = 'إضافة مستخدم جديد / New User';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">إضافة مستخدم جديد <span class="text-muted fs-6">/ New User</span></h4>
                <p class="text-muted small mb-0">إنشاء حساب جديد وتعيين كلمة المرور والصلاحيات</p>
            </div>
            <a href="<?= BASE_URL ?>/users/" class="btn btn-outline-secondary rounded-pill px-3">
                <i class="bi bi-arrow-right me-1"></i> العودة للمستخدمين
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-4" role="alert"><i class="bi bi-exclamation-triangle-fill ms-2"></i><?= e($error) ?></div>
        <?php endif; ?>

        <div class="panel ">
            <div class="panel-body p-4">
                <form method="POST" action="">
                    <?= csrfField() ?>
                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-person me-2"></i>البيانات الأساسية</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الاسم (عربي) *</label>
                            <input type="text" name="name_ar" class="form-control" required placeholder="مثال: أحمد محمد" value="<?= e($_POST['name_ar'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الاسم (English)</label>
                            <input type="text" name="name_en" class="form-control" placeholder="Ahmed Mohamed" value="<?= e($_POST['name_en'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">البريد الإلكتروني (اسم المستخدم) *</label>
                            <input type="email" name="email" class="form-control" required placeholder="user@store.com" value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">كلمة المرور *</label>
                            <input type="password" name="password" class="form-control" required placeholder="••••••••">
                        </div>
                    </div>

                    <hr class="text-muted my-4">

                    <h6 class="fw-bold text-primary mb-3"><i class="bi bi-shield-lock me-2"></i>الصلاحيات والأدوار (Roles & Permissions)</h6>
                    <div class="mb-4">
                        <label class="form-label fw-bold">الدور الوظيفي الرئيسي (Role) *</label>
                        <select name="role" class="form-select">
                            <option value="cashier">كاشير (Cashier)</option>
                            <option value="stock">مسؤول مخزن (Stock Manager)</option>
                            <option value="accountant">محاسب (Accountant)</option>
                            <option value="admin">المدير العام (Admin)</option>
                        </select>
                        <small class="text-muted mt-1 d-block">الدور الرئيسي يحدد القوائم المتاحة للمستخدم.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold mb-2">صلاحيات إضافية مخصصة (Custom Permissions)</label>
                        <p class="text-muted small mb-3">دي بتضاف فوق الدور الرئيسي — يعني ينفع تدي محاسب مثلاً صلاحية "إضافة وتعديل المنتجات" من غير ما تغيّر دوره الأساسي.</p>
                        <?php foreach (getAvailablePermissions() as $groupLabel => $groupPerms): ?>
                        <div class="mb-3">
                            <div class="fw-bold text-primary small mb-2"><?= e($groupLabel) ?></div>
                            <div class="row g-2">
                                <?php foreach ($groupPerms as $key => $label): ?>
                                <div class="col-md-6">
                                    <div class="form-check border rounded p-2 ps-4">
                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($key) ?>" id="perm_<?= e($key) ?>">
                                        <label class="form-check-label d-block w-100" for="perm_<?= e($key) ?>"><?= e($label) ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActiveCheck" value="1" checked>
                        <label class="form-check-label fw-bold" for="isActiveCheck">الحساب نشط (Active Account)</label>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= BASE_URL ?>/users/" class="btn btn-light px-4">إلغاء</a>
                        <button type="submit" class="btn btn-primary px-5 fw-bold">حفظ المستخدم</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
