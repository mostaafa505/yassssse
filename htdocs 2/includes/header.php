<?php
require_once dirname(__DIR__) . '/config/config.php';
requireAuth();

$currentUri = $_SERVER['REQUEST_URI'];
$userRole = $_SESSION['user_role'] ?? 'cashier';
$userNameAr = $_SESSION['user_name_ar'] ?? 'المستخدم';
$userNameEn = $_SESSION['user_name_en'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' . APP_NAME : APP_NAME ?></title>
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <!-- Google Font Cairo -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 -->
    <?php if ($lang === 'ar'): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php endif; ?>
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom CSS -->
    <?php
    // لازم رقم إصدار ثابت (بيتغير بس لما الملف يتغير فعلاً) مش time() اللي بيتغير
    // كل ريكوست — لأن الـ Service Worker بيخزن ويجيب الملف باستخدام الرابط الكامل
    // بما فيه ?v=، فلو الرقم بيتغير كل مرة، رابط النسخة الأوفلاين المطلوبة
    // (اللي اتولدت وقت الانقطاع) مش هيتطابق مع أي حاجة اتخزنت قبل كده، والنتيجة
    // إن الصفحة أوفلاين بترجع بدون أي تنسيق CSS خالص.
    $appCssFile = dirname(__DIR__) . '/assets/css/app.css';
    $appCssVer = file_exists($appCssFile) ? filemtime($appCssFile) : '1';
    ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css?v=<?= $appCssVer ?>">
    <!-- PWA Manifest & App Script -->
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <script src="<?= BASE_URL ?>/assets/js/pwa-install.js" defer></script>
</head>
<body>
<script>
// ─── حفظ بيانات المستخدم في localStorage لوضع الأوفلاين ──────────────────────
try {
    const posUser = {
        id:       <?= (int)($_SESSION['user_id'] ?? 0) ?>,
        name_ar:  <?= json_encode($_SESSION['user_name_ar'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
        name_en:  <?= json_encode($_SESSION['user_name_en'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
        email:    <?= json_encode($_SESSION['user_email'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
        role:     <?= json_encode($_SESSION['user_role'] ?? 'cashier', JSON_UNESCAPED_UNICODE) ?>,
        saved_at: <?= time() ?>
    };
    if (posUser.id > 0) {
        localStorage.setItem('pos_user', JSON.stringify(posUser));
        localStorage.setItem('pos_base_url', '<?= BASE_URL ?>');
        localStorage.setItem('pos_app_name', '<?= APP_NAME ?>');
    }
} catch(e) {}

// ─── تسجيل الـ Service Worker ─────────────────────────────────────────────────
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js', { scope: '<?= BASE_URL ?>/' })
            .then(reg => {
                // نبعت توكن الـ CSRF لعامل الخدمة (Service Worker) عشان يستخدمه
                // وقت الـ Background Sync (رفع الفواتير المعلقة أوفلاين) — عامل
                // الخدمة شغال في سياق منفصل تمامًا عن صفحة المتصفح، فمش بيقدر
                // يقرأ <meta name="csrf-token"> مباشرة، فلازم نبعتهاله كده صراحةً
                // في كل مرة الصفحة بتحمّل (F-05)
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const sendCsrfToken = (sw) => {
                    if (sw && csrfMeta) sw.postMessage({ type: 'SET_CSRF_TOKEN', token: csrfMeta.getAttribute('content') });
                };
                sendCsrfToken(reg.active);
                sendCsrfToken(navigator.serviceWorker.controller);

                // تفعيل Background Sync لو في فواتير أو طلبات إلغاء/إرجاع معلقة
                if ('sync' in reg) {
                    reg.sync.register('sync-pending-sales').catch(() => {});
                    reg.sync.register('sync-pending-actions').catch(() => {});
                }

                navigator.serviceWorker.addEventListener('message', e => {
                    if (e.data && e.data.type === 'SYNC_COMPLETE') {
                        if (typeof updateOfflineBar === 'function') updateOfflineBar();
                        if (typeof renderPendingSalesRows === 'function') renderPendingSalesRows();
                        if (e.data.synced > 0) {
                            const msg = document.createElement('div');
                            msg.style = 'position:fixed;bottom:20px;right:20px;background:#16a34a;color:#fff;padding:12px 20px;border-radius:10px;font-family:Cairo,sans-serif;font-weight:700;z-index:9999;direction:rtl;box-shadow:0 4px 15px rgba(0,0,0,0.3)';
                            msg.innerHTML = `✅ تمت مزامنة ${e.data.synced} فاتورة تلقائياً`;
                            document.body.appendChild(msg);
                            setTimeout(() => msg.remove(), 4000);
                        }
                        if (e.data.failed > 0) {
                            const reasons = (e.data.failedReasons || []).map(r => r.reason).join('\n');
                            const msg = document.createElement('div');
                            msg.style = 'position:fixed;bottom:20px;right:20px;left:20px;max-width:400px;margin-inline-start:auto;background:#dc2626;color:#fff;padding:12px 20px;border-radius:10px;font-family:Cairo,sans-serif;font-weight:700;z-index:9999;direction:rtl;box-shadow:0 4px 15px rgba(0,0,0,0.3);white-space:pre-line;font-size:0.85rem';
                            msg.innerHTML = `⚠️ ${e.data.failed} فاتورة أوفلاين فشلت مزامنتها ولسه محفوظة على الجهاز:\n${reasons}`;
                            document.body.appendChild(msg);
                            setTimeout(() => msg.remove(), 9000);
                        }
                    }
                    if (e.data && e.data.type === 'ACTIONS_SYNC_COMPLETE') {
                        if (typeof updatePendingActionsBar === 'function') updatePendingActionsBar();
                        if (e.data.synced > 0) {
                            const msg = document.createElement('div');
                            msg.style = 'position:fixed;bottom:20px;right:20px;background:#16a34a;color:#fff;padding:12px 20px;border-radius:10px;font-family:Cairo,sans-serif;font-weight:700;z-index:9999;direction:rtl;box-shadow:0 4px 15px rgba(0,0,0,0.3)';
                            msg.innerHTML = `✅ تمت مزامنة ${e.data.synced} طلب إلغاء/إرجاع تلقائيًا`;
                            document.body.appendChild(msg);
                            setTimeout(() => msg.remove(), 4000);
                        }
                    }
                });
            })
            .catch(() => {});
    });
}

// ─── مراقبة الاتصال في كل الصفحات ───────────────────────────────────────────
window.addEventListener('offline', () => {
    const bar = document.getElementById('globalOfflineBar');
    if (bar) bar.style.display = 'block';
});
window.addEventListener('online', () => {
    const bar = document.getElementById('globalOfflineBar');
    if (bar) bar.style.display = 'none';
    // حاول مزامنة الفواتير المعلقة وطلبات الإلغاء/الإرجاع المعلقة لو الصفحة
    // المفتوحة مش هي اللي بتعمل ده بنفسها
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.ready.then(reg => {
            if ('sync' in reg) {
                reg.sync.register('sync-pending-sales').catch(() => {});
                reg.sync.register('sync-pending-actions').catch(() => {});
            }
        });
    }
});
</script>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="brand d-flex align-items-center justify-content-center py-3 border-bottom">
        <?php $brandInfo = getActiveBrandInfo(); ?>
        <div class="text-center px-3">
            <img src="<?= $brandInfo['logo'] ?>" alt="<?= e($brandInfo['name_ar']) ?>" style="height: <?= $brandInfo['logo_height'] ?>; max-width: 200px; object-fit: contain; mix-blend-mode: multiply;">
            <div class="fw-bold text-dark mt-2" style="font-size: 0.85rem;"><?= e($lang === 'en' ? $brandInfo['name_en'] : $brandInfo['name_ar']) ?></div>
        </div>
    </div>

    <nav class="nav flex-column py-3">
        <!-- Dashboard (All roles) -->
        <a class="nav-link <?= strpos($currentUri, '/dashboard') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/dashboard/">
            <i class="bi bi-speedometer2"></i>
            <span><?= __('dashboard') ?></span>
        </a>

        <!-- POS Screen (Admin, Cashier, or anyone granted access_pos) -->
        <?php if (in_array($userRole, ['admin', 'cashier']) || hasPermission('access_pos')): ?>
        <a class="nav-link <?= strpos($currentUri, '/pos') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/pos/">
            <i class="bi bi-calculator"></i>
            <span><?= __('pos') ?></span>
        </a>
        <?php endif; ?>

        <!-- Sales / Invoices (Admin, Cashier, Accountant) -->
        <?php if (in_array($userRole, ['admin', 'cashier', 'accountant'])): ?>
        <a class="nav-link <?= strpos($currentUri, '/sales') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/sales/">
            <i class="bi bi-receipt"></i>
            <span><?= __('invoices') ?></span>
        </a>
        <?php endif; ?>

        <!-- Products (Admin, Stock, Accountant, or anyone granted manage_products) -->
        <?php if (in_array($userRole, ['admin', 'stock', 'accountant']) || hasPermission('manage_products')): ?>
        <a class="nav-link <?= strpos($currentUri, '/products/') !== false && strpos($currentUri, 'categories') === false ? 'active' : '' ?>" href="<?= BASE_URL ?>/products/">
            <i class="bi bi-box-seam"></i>
            <span><?= __('products') ?></span>
        </a>
        <a class="nav-link <?= strpos($currentUri, 'categories') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/products/categories.php">
            <i class="bi bi-folder2-open"></i>
            <span>الأقسام والتصنيفات</span>
        </a>
        <?php endif; ?>

        <!-- Inventory (Admin, Stock, or anyone granted manage_inventory) -->
        <?php if (in_array($userRole, ['admin', 'stock']) || hasPermission('manage_inventory')): ?>
        <a class="nav-link <?= strpos($currentUri, '/inventory') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/inventory/">
            <i class="bi bi-boxes"></i>
            <span><?= __('inventory') ?></span>
        </a>
        <?php endif; ?>

        <!-- Offers (Admin, or anyone granted manage_offers) -->
        <?php if ($userRole === 'admin' || hasPermission('manage_offers')): ?>
        <a class="nav-link <?= strpos($currentUri, '/offers') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/offers/">
            <i class="bi bi-tags"></i>
            <span><?= __('offers') ?></span>
        </a>
        <?php endif; ?>

        <!-- Wholesale Price List (Admin, Accountant, or anyone granted manage_wholesale) -->
        <?php if (in_array($userRole, ['admin', 'accountant']) || hasPermission('manage_wholesale')): ?>
        <a class="nav-link <?= strpos($currentUri, '/wholesale') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/wholesale/pricelist.php">
            <i class="bi bi-file-earmark-spreadsheet"></i>
            <span><?= __('wholesale') ?></span>
        </a>
        <?php endif; ?>

        <!-- Exhibitions & Expenses (Admin, Accountant, or anyone granted manage_exhibitions) -->
        <?php if (in_array($userRole, ['admin', 'accountant']) || hasPermission('manage_exhibitions')): ?>
        <a class="nav-link <?= strpos($currentUri, '/exhibitions') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/exhibitions/">
            <i class="bi bi-calendar-event"></i>
            <span>إدارة المعارض والمصاريف</span>
        </a>
        <?php endif; ?>

        <!-- Main Fund / Treasury & Purchases (Admin, Accountant, or anyone granted manage_treasury) -->
        <?php if (in_array($userRole, ['admin', 'accountant']) || hasPermission('manage_treasury')): ?>
        <a class="nav-link <?= strpos($currentUri, '/treasury') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/treasury/">
            <i class="bi bi-safe2"></i>
            <span>الصندوق الرئيسي</span>
        </a>
        <a class="nav-link <?= strpos($currentUri, '/purchases') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/purchases/">
            <i class="bi bi-receipt-cutoff"></i>
            <span>فواتير المشتريات</span>
        </a>
        <?php endif; ?>

        <!-- Reports (Admin, Accountant, or anyone granted view_reports) -->
        <?php if (in_array($userRole, ['admin', 'accountant']) || hasPermission('view_reports')): ?>
        <a class="nav-link <?= strpos($currentUri, '/reports') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/reports/">
            <i class="bi bi-bar-chart"></i>
            <span><?= __('reports') ?></span>
        </a>
        <?php endif; ?>

        <!-- Users Management (Admin only — لا تُمنح كصلاحية مخصصة لأي دور تاني، راجع getAvailablePermissions() في config.php للسبب) -->
        <?php if ($userRole === 'admin'): ?>
        <a class="nav-link <?= strpos($currentUri, '/users') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/users/">
            <i class="bi bi-people"></i>
            <span><?= __('users') ?></span>
        </a>
        <?php endif; ?>

        <!-- Audit Log (Admin, or anyone granted view_audit_log) -->
        <?php if ($userRole === 'admin' || hasPermission('view_audit_log')): ?>
        <a class="nav-link <?= strpos($currentUri, '/audit') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/audit/">
            <i class="bi bi-clock-history"></i>
            <span><?= __('audit_log') ?></span>
        </a>
        <?php endif; ?>

        <!-- Install App / WebView Hub (All Logged-in users) -->
        <div class="px-3 pt-3 mt-2 border-top">
            <a class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2 py-2 shadow-sm rounded-3 <?= strpos($currentUri, '/app') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/app/">
                <i class="bi bi-download fs-6"></i>
                <span class="fw-bold" style="font-size: 0.85rem;">تثبيت التطبيق (App)</span>
            </a>
        </div>
    </nav>
</aside>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay"></div>

<!-- Main Wrapper -->
<div class="main-wrapper">
    <!-- Top Navbar -->
    <header class="top-navbar d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light border rounded-circle shadow-sm d-flex align-items-center justify-content-center" id="sidebarToggle" style="width: 38px; height: 38px;" title="إظهار / إخفاء القائمة الجانبية">
                <i class="bi bi-list fs-5 text-dark"></i>
            </button>
            <h5 class="mb-0 fw-bold text-dark"><?= isset($pageTitle) ? e($pageTitle) : __('dashboard') ?></h5>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Language Dropdown -->
            <div class="dropdown">
                <button class="btn btn-light btn-sm rounded-pill px-3 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-globe"></i> <?= strtoupper($lang) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 12px;">
                    <li><a class="dropdown-item" href="?lang=ar">العربية (AR)</a></li>
                    <li><a class="dropdown-item" href="?lang=en">English (EN)</a></li>
                </ul>
            </div>
            <div class="dropdown">
                <button class="btn btn-light rounded-pill px-3 py-2 d-flex align-items-center gap-2 border" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="background: var(--surface-app); border-color: var(--border-strong) !important;">
                    <div class="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle" style="width: 28px; height: 28px;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <div class="text-start d-none d-md-block" style="line-height: 1.1;">
                        <span class="d-block text-dark fw-bold" style="font-size: 0.85rem;"><?= e($lang === 'en' ? $userNameEn : $userNameAr) ?></span>
                        <span class="text-subtle" style="font-size: 0.7rem;"><?= ucfirst($userRole) ?></span>
                    </div>
                    <i class="bi bi-chevron-down ms-2 text-subtle" style="font-size: 0.7rem;"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0" style="box-shadow: var(--shadow-dropdown); border-radius: 12px; min-width: 220px;">
                    <li class="px-3 py-2 border-bottom">
                        <strong class="d-block text-dark"><?= e($lang === 'en' ? $userNameEn : $userNameAr) ?></strong>
                        <small class="text-subtle"><?= e($_SESSION['user_email'] ?? '') ?></small>
                    </li>
                    <li>
                        <a class="dropdown-item py-2 text-dark d-flex align-items-center gap-2" href="<?= BASE_URL ?>/users/profile.php">
                            <i class="bi bi-person-gear text-primary"></i> تعديل بيانات الحساب
                        </a>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item py-2 text-danger d-flex align-items-center gap-2" href="<?= BASE_URL ?>/auth/logout.php">
                            <i class="bi bi-box-arrow-right"></i> <?= __('logout') ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Content Body -->
    <main class="content-body">
