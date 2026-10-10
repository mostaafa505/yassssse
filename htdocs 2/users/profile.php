<?php
$pageTitle = 'الملف الشخصي وتعديل الحساب / My Profile';
require_once dirname(__DIR__) . '/includes/header.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    redirect('auth/login.php');
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    redirect('auth/logout.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($nameAr) || empty($email)) {
        $error = 'يرجى ملء جميع الحقول الإلزامية (الاسم والبريد الإلكتروني).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'يرجى إدخال بريد إلكتروني صحيح.';
    } elseif (!empty($newPassword) && strlen($newPassword) < 6) {
        $error = 'كلمة المرور يجب ألا تقل عن 6 خانات.';
    } elseif (!empty($newPassword) && $newPassword !== $confirmPassword) {
        $error = 'كلمتا المرور غير متطابقتين. يرجى التأكد وإعادة المحاولة.';
    } else {
        // Check if email taken by another user
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $checkStmt->execute([$email, $userId]);
        if ($checkStmt->fetch()) {
            $error = 'البريد الإلكتروني مستخدم بالفعل من قبل حساب آخر.';
        } else {
            $sql = "UPDATE users SET name_ar = ?, name_en = ?, email = ?";
            $params = [$nameAr, $nameEn ?: $nameAr, $email];

            if (!empty($newPassword)) {
                $sql .= ", password = ?";
                $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = ?";
            $params[] = $userId;

            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);

            // Update session data immediately
            $_SESSION['user_name_ar'] = $nameAr;
            $_SESSION['user_name_en'] = $nameEn ?: $nameAr;
            $_SESSION['user_email'] = $email;

            // Audit log
            logAudit($pdo, 'update_profile', 'user', $userId, $currentUser, [
                'name_ar' => $nameAr,
                'email' => $email,
                'password_changed' => !empty($newPassword)
            ]);

            $success = 'تم تحديث بيانات حسابك بنجاح!';

            // Refresh user data for the form
            $stmt->execute([$userId]);
            $currentUser = $stmt->fetch();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-7 col-md-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="title-large mb-1">الملف الشخصي وإعدادات الحساب</h1>
                <p class="text-subtle mb-0">يمكنك تعديل اسمك، بريدك الإلكتروني، وكلمة المرور الخاصة بك</p>
            </div>
            <?php if ($_SESSION['user_role'] === 'admin'): ?>
            <a href="<?= BASE_URL ?>/users/" class="btn btn-light">
                <i class="bi bi-people me-1"></i> إدارة المستخدمين
            </a>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-3 mb-4 shadow-sm border-0 d-flex align-items-center" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5 me-2 ms-2"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-3 mb-4 shadow-sm border-0 d-flex align-items-center" role="alert">
                <i class="bi bi-check-circle-fill fs-5 me-2 ms-2"></i>
                <div><?= e($success) ?></div>
            </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-body p-4">
                <!-- User Summary Header -->
                <div class="d-flex align-items-center gap-3 p-3 rounded-3 mb-4" style="background: var(--surface-app); border: 1px solid var(--border-strong);">
                    <div class="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle shadow-sm" style="width: 54px; height: 54px; font-size: 1.5rem;">
                        <i class="bi bi-person-circle"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5 text-dark"><?= e($currentUser['name_ar']) ?></div>
                        <div class="text-subtle small"><?= e($currentUser['email']) ?></div>
                    </div>
                    <div class="ms-auto me-0">
                        <?php if ($currentUser['role'] === 'admin'): ?>
                            <span class="badge" style="background: var(--intent-danger-bg); color: var(--intent-danger); border: 1px solid rgba(239,68,68,0.2); font-size: 0.85rem; padding: 6px 12px;">المدير العام</span>
                        <?php elseif ($currentUser['role'] === 'cashier'): ?>
                            <span class="badge" style="background: var(--intent-success-bg); color: var(--intent-success); border: 1px solid rgba(16,185,129,0.2); font-size: 0.85rem; padding: 6px 12px;">كاشير</span>
                        <?php elseif ($currentUser['role'] === 'stock'): ?>
                            <span class="badge" style="background: var(--intent-warning-bg); color: var(--intent-warning); border: 1px solid rgba(217,119,6,0.2); font-size: 0.85rem; padding: 6px 12px;">مسؤول مخزن</span>
                        <?php else: ?>
                            <span class="badge" style="background: #EFF6FF; color: #2563EB; border: 1px solid rgba(37,99,235,0.2); font-size: 0.85rem; padding: 6px 12px;">محاسب</span>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" action="" autocomplete="off">
                    <?= csrfField() ?>
                    <h6 class="title-medium text-primary mb-3">
                        <i class="bi bi-person-vcard me-2"></i>المعلومات الشخصية
                    </h6>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-caption fw-bold">الاسم (بالعربية) *</label>
                            <input type="text" name="name_ar" class="form-control" required value="<?= e($currentUser['name_ar']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-caption fw-bold">الاسم (English)</label>
                            <input type="text" name="name_en" class="form-control" value="<?= e($currentUser['name_en']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-caption fw-bold">البريد الإلكتروني (لتسجيل الدخول) *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control" required value="<?= e($currentUser['email']) ?>">
                            </div>
                        </div>
                    </div>

                    <hr class="text-tertiary my-4">

                    <h6 class="title-medium text-primary mb-3">
                        <i class="bi bi-key me-2"></i>تغيير كلمة المرور
                    </h6>
                    <p class="text-subtle small mb-3">اترك الحقلين التاليين فارغين إذا كنت لا ترغب بتغيير كلمة المرور الحالية.</p>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-caption fw-bold">كلمة المرور الجديدة</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                                <input type="password" name="new_password" class="form-control" placeholder="••••••••" minlength="6" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-caption fw-bold">تأكيد كلمة المرور الجديدة</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" minlength="6" autocomplete="new-password">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                        <a href="<?= BASE_URL ?>/dashboard/" class="btn btn-light px-4">إلغاء</a>
                        <button type="submit" class="btn btn-primary px-5 fw-bold">
                            <i class="bi bi-check2-circle me-1"></i> حفظ التعديلات
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
