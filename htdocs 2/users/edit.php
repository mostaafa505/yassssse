<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAuth(['admin']);

$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    redirect('users/index.php');
}

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

    if (empty($nameAr) || empty($email)) {
        $error = 'يرجى ملء جميع الحقول الإلزامية.';
    } else {
        // Check if email already used by another user
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $checkStmt->execute([$email, $id]);
        if ($checkStmt->fetch()) {
            $error = 'البريد الإلكتروني مستخدم بالفعل من قبل حساب آخر.';
        } else {
            // If editing own account, keep active and preserve role
            if ($id === (int)$_SESSION['user_id']) {
                $isActive = 1;
                if ($_SESSION['user_role'] === 'admin') {
                    $role = 'admin';
                }
            }

            $sql = "UPDATE users SET name_ar = ?, name_en = ?, email = ?, role = ?, permissions = ?, is_active = ?";
            $params = [$nameAr, $nameEn ?: $nameAr, $email, $role, $customPerms, $isActive];

            if (!empty($password)) {
                $sql .= ", password = ?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = ?";
            $params[] = $id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Immediately refresh active session if user edited their own account
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['user_name_ar'] = $nameAr;
                $_SESSION['user_name_en'] = $nameEn ?: $nameAr;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = $role;
            }

            logAudit($pdo, 'update_user', 'user', $id, $user, ['name_ar' => $nameAr, 'role' => $role, 'email' => $email]);
            redirect('users/index.php');
        }
    }
}

$pageTitle = 'تعديل مستخدم / Edit User';
require_once dirname(__DIR__) . '/includes/header.php';

$currentPerms = is_string($user['permissions']) ? json_decode($user['permissions'], true) : [];
if (!is_array($currentPerms)) $currentPerms = [];
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="title-large mb-1">تعديل المستخدم</h1>
                <p class="text-subtle mb-0">تحديث بيانات المستخدم الصلاحيات وكلمة المرور: <?= e($user['name_ar']) ?></p>
            </div>
            <a href="<?= BASE_URL ?>/users/" class="btn btn-light">
                <i class="bi bi-arrow-right me-1"></i> العودة للمستخدمين
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 mb-4" role="alert"><i class="bi bi-exclamation-triangle-fill ms-2"></i><?= e($error) ?></div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-body">
                <form method="POST" action="">
                    <?= csrfField() ?>
                    <h6 class="title-medium text-primary mb-4"><i class="bi bi-person me-2"></i>البيانات الأساسية</h6>
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-caption">الاسم (عربي) *</label>
                            <input type="text" name="name_ar" class="form-control" required value="<?= e($user['name_ar']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-caption">الاسم (English)</label>
                            <input type="text" name="name_en" class="form-control" value="<?= e($user['name_en']) ?>">
                        </div>
                    </div>

                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-caption">البريد الإلكتروني *</label>
                            <input type="email" name="email" class="form-control" required value="<?= e($user['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-caption">كلمة المرور الجديدة</label>
                            <input type="password" name="password" class="form-control" placeholder="اتركها فارغة إذا لم ترغب بالتغيير">
                        </div>
                    </div>

                    <hr class="text-tertiary my-5">

                    <h6 class="title-medium text-primary mb-4"><i class="bi bi-shield-lock me-2"></i>الصلاحيات والأدوار</h6>
                    <div class="mb-4">
                        <label class="form-label text-caption">الدور الوظيفي الرئيسي *</label>
                        <select name="role" class="form-select">
                            <option value="cashier" <?= $user['role'] === 'cashier' ? 'selected' : '' ?>>كاشير (Cashier)</option>
                            <option value="stock" <?= $user['role'] === 'stock' ? 'selected' : '' ?>>مسؤول مخزن (Stock Manager)</option>
                            <option value="accountant" <?= $user['role'] === 'accountant' ? 'selected' : '' ?>>محاسب (Accountant)</option>
                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>المدير العام (Admin)</option>
                        </select>
                        <div class="text-caption text-subtle mt-2">الدور الرئيسي يحدد القوائم المتاحة للمستخدم.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-caption mb-3">صلاحيات إضافية مخصصة</label>
                        <p class="text-muted small mb-3">دي بتضاف فوق الدور الرئيسي — يعني ينفع تدي محاسب مثلاً صلاحية "إضافة وتعديل المنتجات" من غير ما تغيّر دوره الأساسي.</p>
                        <?php foreach (getAvailablePermissions() as $groupLabel => $groupPerms): ?>
                        <div class="mb-3">
                            <div class="fw-bold text-primary small mb-2"><?= e($groupLabel) ?></div>
                            <div class="row g-3">
                                <?php foreach ($groupPerms as $key => $label):
                                    $isChecked = in_array($key, $currentPerms, true) ? 'checked' : '';
                                ?>
                                <div class="col-md-6">
                                    <div class="form-check p-3 rounded-3" style="background: var(--surface-app); border: 1px solid var(--border-strong);">
                                        <input class="form-check-input ms-0 me-3" style="float: none;" type="checkbox" name="permissions[]" value="<?= e($key) ?>" id="perm_<?= e($key) ?>" <?= $isChecked ?>>
                                        <label class="form-check-label d-inline-block mt-1" for="perm_<?= e($key) ?>"><?= e($label) ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-check form-switch mb-5">
                        <input class="form-check-input ms-0 me-3" style="float: none;" type="checkbox" name="is_active" id="isActiveCheck" value="1" <?= $user['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label text-dark fw-bold mt-1" for="isActiveCheck">الحساب نشط (Active Account)</label>
                    </div>

                    <div class="d-flex justify-content-end gap-3 pt-3 border-top border-light">
                        <a href="<?= BASE_URL ?>/users/" class="btn btn-light px-4">إلغاء</a>
                        <button type="submit" class="btn btn-primary px-5">حفظ التعديلات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
