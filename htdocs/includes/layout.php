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

function module_icon(string $module): string
{
    foreach (nav_items() as [$g, $items]) { foreach ($items as $i) { if ($i[3] === $module) { return $i[1]; } } }
    return 'bi-grid';
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
    $initial = mb_substr(trim($u['full_name']) ?: '؟', 0, 1);
    $icon = $opts['icon'] ?? module_icon($module !== '' ? $module : 'dashboard');
    $sub = $opts['subtitle'] ?? $company;
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e($company) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#d3e8f9">
<link rel="icon" type="image/svg+xml" href="<?= e(url('assets/favicon.svg')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
<link rel="preload" as="font" type="font/woff2" crossorigin href="<?= e(url('assets/fonts/ibm-plex-sans-arabic-arabic-600-normal.woff2')) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
</head>
<body>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="<?= e(url('assets/img/logo-mark.svg')) ?>" alt="">
        <div class="name"><?= e($company) ?><small><?= e(setting('company_name_en', APP_NAME_EN)) ?></small></div>
    </div>
    <nav>
    <?php foreach (nav_items() as [$group, $items]):
        $visible = array_filter($items, fn($i) => $i[3] === '' || can($i[3]));
        if (!$visible) { continue; }
        if ($group !== '') { echo '<div class="nav-group">' . e($group) . '</div>'; }
        foreach ($visible as [$label, $ico, $href, $mod]):
            if ($href === 'stock/') { $active = in_array($rel, ['stock/', 'stock/index.php'], true) || strpos($rel, 'stock/operation') === 0; }
            elseif (substr($href, -1) === '/') { $active = strpos($rel, $href) === 0; }
            else { $active = $rel === $href; }
    ?>
        <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e(url($href)) ?>"><i class="bi <?= e($ico) ?>"></i><?= e($label) ?></a>
    <?php endforeach; endforeach; ?>
    </nav>
    <div class="user-box">
        <div class="avatar"><?= e($initial) ?></div>
        <div class="who"><b><?= e($u['full_name']) ?></b><span><?= e(ROLES[$u['role']]['label'] ?? '') ?></span></div>
        <div class="acts"><a href="<?= e(url('users/profile.php')) ?>" title="حسابي"><i class="bi bi-person-gear"></i></a><a href="<?= e(url('auth/logout.php')) ?>" title="تسجيل الخروج"><i class="bi bi-box-arrow-left"></i></a></div>
    </div>
</aside>
<div class="main">
    <header class="topbar">
        <button class="btn btn-outline-brand menu-btn" type="button" id="menuBtn" aria-label="القائمة"><i class="bi bi-list fs-5"></i></button>
        <div class="ticon"><i class="bi <?= e($icon) ?>"></i></div>
        <div><h1><?= e($title) ?></h1><div class="sub"><?= e($sub) ?></div></div>
        <div class="date"><i class="bi bi-calendar3"></i> <?= e(fdate(today())) ?></div>
    </header>
    <div class="flash-wrap no-print" id="flashWrap">
    <?php foreach (take_flashes() as [$type, $msg]): ?>
        <div class="alert alert-<?= $type === 'error' ? 'danger' : e($type) ?> alert-dismissible" role="alert">
            <?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>
    </div>
    <main class="content">
<?php
    if (!empty($_SESSION['_recovery_notice'])) {
        $rn = $_SESSION['_recovery_notice']; unset($_SESSION['_recovery_notice']);
        ?>
        <div class="cardx notice-recovery no-print"><div class="head"><i class="bi bi-life-preserver"></i> رمز استرداد كلمة المرور — <?= e($rn['name']) ?></div>
          <div class="body"><div class="recovery-box">
            <div class="small text-muted mb-1">احفظ الرمز ده دلوقتي في مكان آمن — <b>بيظهر مرة واحدة فقط</b> ومش هيظهر تاني</div>
            <div class="recovery-code" dir="ltr" id="rcode"><?= e($rn['code']) ?></div>
            <div class="mt-2 d-flex gap-2 justify-content-center">
              <button type="button" class="btn btn-gold btn-sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('rcode').textContent);this.textContent='تم النسخ ✓'"><i class="bi bi-clipboard"></i> نسخ</button>
              <button type="button" class="btn btn-outline-brand btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
            </div></div>
            <div class="small text-muted mt-2">يُستخدم من صفحة «نسيت كلمة المرور» في شاشة الدخول لتغيير كلمة المرور بدون مساعدة.</div></div></div>
        <?php
    }
    if (empty($u['has_recovery']) && $rel !== 'users/profile.php') {
        echo '<div class="alert alert-warning no-print d-flex align-items-center gap-2 py-2"><i class="bi bi-shield-exclamation fs-5"></i><div class="flex-fill">لسه ماعملتش <b>رمز استرداد كلمة المرور</b> — اعمله دلوقتي علشان لو نسيت الباسوورد تقدر ترجعه بنفسك.</div><a class="btn btn-gold btn-sm" href="' . e(url('users/profile.php')) . '">إنشاء الرمز</a></div>';
    }
}

function page_end(array $opts = []): void
{
    ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ((array)($opts['scripts'] ?? []) as $sc): ?><script src="<?= e($sc) ?>"></script><?php endforeach; ?>
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
        <img src="<?= e(url('assets/img/logo-mark.svg')) ?>" alt="">
        <div>
            <h2><?= e(setting('company_name', APP_NAME)) ?> <small class="text-muted" style="font-size:.6em"><?= e(setting('company_name_en', APP_NAME_EN)) ?></small></h2>
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
