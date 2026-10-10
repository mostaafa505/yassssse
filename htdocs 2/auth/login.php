<?php
require_once dirname(__DIR__) . '/config/config.php';

if (isLoggedIn()) {
    redirect('dashboard/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $error = 'انتهت صلاحية الصفحة، من فضلك أعد تحميلها وحاول تسجيل الدخول مرة أخرى.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']          = $user['id'];
            $_SESSION['user_name_ar']     = $user['name_ar'];
            $_SESSION['user_name_en']     = $user['name_en'];
            $_SESSION['user_email']       = $user['email'];
            $_SESSION['user_role']        = $user['role'];
            $_SESSION['user_permissions'] = $user['permissions'] ? json_decode($user['permissions'], true) : [];

            logAudit($pdo, 'login', 'user', $user['id'], null, ['email' => $email]);

            $target = $_SESSION['_redirect'] ?? 'dashboard/index.php';
            if (empty($target) || strpos($target, 'auth') !== false) {
                $target = 'dashboard/index.php';
            }
            unset($_SESSION['_redirect']);

            // حفظ بيانات المستخدم في localStorage لوضع الأوفلاين
            $userDataJson = json_encode([
                'id'      => $user['id'],
                'name_ar' => $user['name_ar'],
                'name_en' => $user['name_en'],
                'email'   => $user['email'],
                'role'    => $user['role'],
                'saved_at'=> time()
            ], JSON_UNESCAPED_UNICODE);
            $targetUrl = BASE_URL . '/' . ltrim($target, '/');

            // JavaScript يحفظ في localStorage، يمسح صفحات الـ Service Worker
            // المخزنة من جلسة دخول سابقة (ممكن تكون لمستخدم بصلاحية مختلفة
            // على نفس الجهاز)، ثم يوجه المستخدم
            echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>
            <script>
            try { localStorage.setItem('pos_user', " . json_encode($userDataJson) . "); } catch(e){}
            (async () => {
                try {
                    if ('caches' in window) {
                        const keys = await caches.keys();
                        // نمسح بس كاش صفحات الـ HTML المخزنة (ممكن تحمل واجهة
                        // مستخدم سابق بصلاحية مختلفة)، ونسيب كاش الأصول الثابتة
                        // (CSS/JS/صور) زي ما هو عشان الدخول الأول أوفلاين يبقى أسرع
                        await Promise.all(keys.filter(k => !k.includes('assets')).map(k => caches.delete(k)));
                    }
                } catch(e) {}
                window.location.href = " . json_encode($targetUrl) . ";
            })();
            </script>
            <noscript><meta http-equiv='refresh' content='0;url={$targetUrl}'></noscript>
            </body></html>";
            exit;
        } else {
            $error = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | <?= APP_NAME ?></title>
    <!-- Google Font Cairo -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css?v=<?= time() ?>">
    <style>
        body {
            background-color: var(--surface-default);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }
        .login-panel {
            padding: 48px 40px;
        }
    </style>
</head>
<body>

    <div class="login-wrapper text-center">
            <?php $brandInfo = getActiveBrandInfo(); ?>
            <div class="d-flex justify-content-center align-items-center mb-3">
                <img src="<?= $brandInfo['logo'] ?>" alt="<?= e($brandInfo['name_en']) ?>" style="height: 65px; object-fit: contain;">
            </div>
            <h4 class="title-large mb-1 text-dark" style="color: var(--brand-primary) !important;"><?= e($brandInfo['name_ar']) ?> <?= e($brandInfo['name_en']) ?></h4>
            <p class="text-subtle mb-4" style="color: var(--brand-secondary) !important; font-weight: 600;">نظام المبيعات وإدارة المخزون</p>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2 text-start small mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill ms-2"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="text-start">
            <?= csrfField() ?>
            <div class="mb-4">
                <label class="form-label text-caption">البريد الإلكتروني <span class="text-subtle font-monospace ms-1">/ Email</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-subtle"></i></span>
                    <input type="email" name="email" class="form-control border-start-0" placeholder="example@domain.com" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-5">
                <label class="form-label text-caption">كلمة المرور <span class="text-subtle font-monospace ms-1">/ Password</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-subtle"></i></span>
                    <input type="password" name="password" id="password" class="form-control border-start-0" placeholder="••••••••" required value="">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-2 py-3">
                <i class="bi bi-box-arrow-in-right ms-2"></i>تسجيل الدخول / Login
            </button>
        </form>
    </div>
</div>

</body>
</html>
