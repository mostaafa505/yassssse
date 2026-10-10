<?php
/** القالب العام للصفحات: الشريط الجانبي + الشريط العلوي + رسائل التنبيه */
declare(strict_types=1);

function nav_items(): array
{
    return [
        ['', [['الرئيسية', 'bi-speedometer2', 'dashboard/', 'dashboard']]],
        ['العمليات', [
            ['المشتريات', 'bi-cart-plus', 'purchases/', 'purchases'],
            ['المبيعات', 'bi-receipt', 'sales/', 'sales'],
            ['سندات القبض والصرف', 'bi-cash-coin', 'vouchers/', 'vouchers'],
            ['الخزينة والمصروفات', 'bi-safe2', 'treasury/', 'treasury'],
        ]],
        ['المخازن', [
            ['الأصناف', 'bi-box-seam', 'items/', 'items'],
            ['أرصدة المخازن', 'bi-boxes', 'stock/', 'stock'],
            ['حركة المخزون', 'bi-arrow-left-right', 'stock/movements.php', 'stock'],
            ['إدارة المخازن', 'bi-building', 'warehouses/', 'stock'],
        ]],
        ['الحسابات', [
            ['العملاء', 'bi-people', 'customers/', 'customers'],
            ['الموردين', 'bi-truck', 'suppliers/', 'suppliers'],
        ]],
        ['التقارير', [['التقارير', 'bi-bar-chart-line', 'reports/', 'reports']]],
        ['المساعدة', [['دليل الاستخدام', 'bi-question-circle', 'help/', '']]],
        ['الإدارة', [
            ['المستخدمين', 'bi-person-gear', 'users/', 'users'],
            ['الإعدادات', 'bi-gear', 'settings/', 'settings'],
            ['سجل العمليات', 'bi-clock-history', 'audit/', 'audit'],
        ]],
    ];
}

function page_start(string $title, string $module, array $opts = []): void
{
    if ($module === '') { require_login(); } else { require_module($module); }
    header('Cache-Control: no-store');   // بيانات مالية: ما تتخزنش في كاش المتصفح
    $u = current_user();
    $company = setting('company_name', APP_NAME);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $base = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
    $rel = ltrim(substr($path, strlen($base)), '/');
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e($company) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#2f86c6">
<link rel="icon" type="image/svg+xml" href="<?= e(url('assets/favicon.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
</head>
<body>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="<?= e(url('assets/img/logo.svg')) ?>" alt="">
        <div class="name"><?= e($company) ?><small><?= e(setting('company_name_en', APP_NAME_EN)) ?></small></div>
    </div>
    <nav>
    <?php foreach (nav_items() as [$group, $items]):
        $visible = array_filter($items, fn($i) => $i[3] === '' || can($i[3]));
        if (!$visible) { continue; }
        if ($group !== '') { echo '<div class="nav-group">' . e($group) . '</div>'; }
        foreach ($visible as [$label, $icon, $href, $mod]):
            if ($href === 'stock/') { $active = in_array($rel, ['stock/', 'stock/index.php'], true) || strpos($rel, 'stock/operation') === 0; }
            elseif (substr($href, -1) === '/') { $active = strpos($rel, $href) === 0; }
            else { $active = $rel === $href; }
    ?>
        <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e(url($href)) ?>"><i class="bi <?= e($icon) ?>"></i><?= e($label) ?></a>
    <?php endforeach; endforeach; ?>
    </nav>
    <div class="user-box">
        <div class="fw-bold text-white"><i class="bi bi-person-circle"></i> <?= e($u['full_name']) ?></div>
        <div class="d-flex justify-content-between mt-1">
            <span><?= e(ROLES[$u['role']]['label'] ?? '') ?></span>
            <span><a href="<?= e(url('users/profile.php')) ?>">حسابي</a> · <a href="<?= e(url('auth/logout.php')) ?>">خروج</a></span>
        </div>
    </div>
</aside>
<div class="main">
    <header class="topbar">
        <button class="btn btn-outline-brand btn-sm menu-btn" type="button" id="menuBtn" aria-label="القائمة"><i class="bi bi-list fs-5"></i></button>
        <h1><?= e($title) ?></h1>
        <div class="date"><i class="bi bi-calendar3"></i> <?= e(fdate(today())) ?></div>
    </header>
    <div class="flash-wrap no-print" id="flashWrap">
    <?php foreach (take_flashes() as [$type, $msg]): ?>
        <div class="alert alert-<?= $type === 'error' ? 'danger' : e($type) ?> alert-dismissible shadow" role="alert">
            <?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>
    </div>
    <main class="content">
<?php
}

function page_end(array $opts = []): void
{
    ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ((array)($opts['scripts'] ?? []) as $s): ?><script src="<?= e($s) ?>"></script><?php endforeach; ?>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/app.js') ?>"></script>
<?= $opts['inline_js'] ?? '' ?>
</body>
</html>
<?php
}

/** يطبع بادج حالة صغير */
function badge(string $text, string $cls = 'secondary'): string
{
    return '<span class="badge-soft ' . e($cls) . '">' . e($text) . '</span>';
}

/** رأس الطباعة (اللوجو واسم الشركة) — بيظهر في الطباعة فقط */
function print_header(string $title): void
{
    ?>
    <div class="print-only print-head">
        <img src="<?= e(url('assets/img/logo.svg')) ?>" alt="">
        <div>
            <h2><?= e(setting('company_name', APP_NAME)) ?></h2>
            <div class="text-muted small"><?= e(setting('company_address')) ?> <?= setting('company_phone') ? ' — ت: ' . e(setting('company_phone')) : '' ?>
                <?= setting('company_tax_no') ? ' — الرقم الضريبي: ' . e(setting('company_tax_no')) : '' ?></div>
            <div class="fw-bold"><?= e($title) ?></div>
        </div>
    </div>
    <?php
}

/** مربع اختيار من قائمة (select) جاهز */
function select_options(array $rows, string $valueKey, string $labelKey, $selected = null, bool $blank = true, string $blankLabel = '— اختر —'): string
{
    $h = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($rows as $r) {
        $h .= '<option value="' . e((string)$r[$valueKey]) . '"' . ((string)$selected === (string)$r[$valueKey] ? ' selected' : '') . '>' . e((string)$r[$labelKey]) . '</option>';
    }
    return $h;
}
function simple_options(array $values, $selected = null, bool $blank = false, string $blankLabel = '— الكل —'): string
{
    $h = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($values as $v) { $h .= '<option' . ((string)$selected === (string)$v ? ' selected' : '') . '>' . e((string)$v) . '</option>'; }
    return $h;
}
