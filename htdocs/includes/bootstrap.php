<?php
/**
 * نظام ماهر البحيري للاستيراد والتصدير — الملف الأساسي (يُحمَّل في كل صفحة)
 * الإعدادات، الاتصال بقاعدة البيانات، الجلسة، الصلاحيات، ودوال مساعدة عامة.
 */
declare(strict_types=1);

const APP_NAME    = 'ماهر البحيري للاستيراد والتصدير';
const APP_SHORT   = 'ماهر البحيري';
const APP_NAME_EN = 'Maher El Bahery Import & Export';
const SCHEMA_VERSION = 3;   // رقم نسخة هيكل قاعدة البيانات (بيترقّى تلقائياً — راجع run_migrations)
const CURRENCY    = 'ج.م';

date_default_timezone_set('Africa/Cairo');
mb_internal_encoding('UTF-8');

// ── قوائم ثابتة (نفس القوائم المنسدلة في ملف الإكسل) ──────────────────────────
const UNITS           = ['شيكارة', 'كرتونة', 'كيلو', 'طن', 'عبوة', 'وحدة'];
const PAYMENT_METHODS = ['نقدي', 'تحويل بنكي', 'شيك', 'محفظة إلكترونية'];
const TREASURY_CATEGORIES = [
    'مبيعات', 'تحصيل عميل', 'شراء بضاعة', 'مصروف نقل', 'مرتبات',
    'إيجار', 'مرافق', 'مصروفات أخرى', 'إيداع بنكي', 'سحب بنكي',
];
// التصنيفات المسموح بتسجيلها يدوياً من شاشة الخزينة (الباقي بيتسجل تلقائياً من المستندات)
const MANUAL_RECEIPT_CATEGORIES = ['مبيعات'];
const MANUAL_PAYMENT_CATEGORIES = ['مصروف نقل', 'مرتبات', 'إيجار', 'مرافق', 'مصروفات أخرى'];
const EXPENSE_CATEGORIES        = ['مصروف نقل', 'مرتبات', 'إيجار', 'مرافق', 'مصروفات أخرى'];
const ACCOUNT_TYPES = ['cash' => 'خزينة نقدية', 'bank' => 'حساب بنكي', 'wallet' => 'محفظة إلكترونية'];

// الوحدات (Modules) اللي بتتحكم فيها الصلاحيات
const MODULES = [
    'dashboard' => 'الرئيسية',
    'items'     => 'الأصناف',
    'stock'     => 'المخازن وحركة المخزون',
    'purchases' => 'المشتريات',
    'sales'     => 'المبيعات',
    'suppliers' => 'الموردين',
    'customers' => 'العملاء',
    'vouchers'  => 'سندات القبض والصرف',
    'treasury'  => 'الخزينة والمصروفات',
    'reports'   => 'التقارير',
    'settings'  => 'الإعدادات',
    'audit'     => 'سجل العمليات',
    'users'     => 'إدارة المستخدمين',
];
const ADMIN_ONLY_MODULES = [];   // الحماية من ترقية النفس لمدير بتتم في شاشة المستخدمين (غير المدير مايقدرش يلمس حساب مدير)

const ROLES = [
    'admin'      => ['label' => 'مدير النظام',   'modules' => ['*']],
    'accounts_manager' => ['label' => 'مدير حسابات', 'modules' => ['*']],
    // المحاسب يشوف كل حاجة في النظام ما عدا إدارة المستخدمين
    'accountant' => ['label' => 'محاسب',          'modules' => ['dashboard', 'items', 'stock', 'purchases', 'sales', 'suppliers', 'customers', 'vouchers', 'treasury', 'reports', 'settings', 'audit']],
    // أمين المخزن: المخزن بس (الأصناف، الأرصدة، حركة المخزون، إذن استلام/صرف/تحويل) — بدون أي أرقام مالية
    'warehouse'  => ['label' => 'أمين مخزن',      'modules' => ['items', 'stock']],
    'sales'      => ['label' => 'مبيعات',         'modules' => ['dashboard', 'items', 'sales', 'customers', 'vouchers']],
    'purchases'  => ['label' => 'مشتريات',        'modules' => ['dashboard', 'items', 'purchases', 'suppliers', 'vouchers']],
    'viewer'     => ['label' => 'مطّلع (تقارير)', 'modules' => ['dashboard', 'reports']],
];

/** خطأ متوقع من منطق الشغل — رسالته بالعربي وبتتعرض للمستخدم زي ما هي */
class AppError extends RuntimeException {}

// ── الإعدادات والاتصال بقاعدة البيانات ───────────────────────────────────────
$envFile = getenv('MB_ENV_FILE') ?: dirname(__DIR__) . '/config/env.php';   // MB_ENV_FILE: للاختبارات فقط
$ENV = is_file($envFile) ? (array)(require $envFile) : [];
define('DEBUG', !empty($ENV['debug']));
ini_set('display_errors', DEBUG ? '1' : '0');
error_reporting(DEBUG ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/** هل الصفحة الحالية هي صفحة التثبيت؟ */
define('IN_INSTALLER', defined('MB_INSTALLER'));

function detectBaseUrl(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $path    = ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) ? substr($appRoot, strlen($docRoot)) : '';
    return ($isHttps ? 'https' : 'http') . '://' . $host . rawurlencode_path($path);
}
function rawurlencode_path(string $p): string
{
    return implode('/', array_map('rawurlencode', explode('/', $p)));
}
define('BASE_URL', detectBaseUrl());

// ── رؤوس الأمان ──
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

// ── الجلسة ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('mb_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $isHttps,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

$pdo = null;
if (!IN_INSTALLER) {
    if (!$ENV) {
        if (PHP_SAPI !== 'cli') { header('Location: ' . BASE_URL . '/install/'); }
        exit('النظام غير مثبّت بعد. افتح صفحة install/ أولاً.');
    }
    $pdo = db_connect($ENV);
}

function db_connect(array $env): PDO
{
    try {
        $pdo = new PDO(
            'mysql:host=' . $env['db_host'] . ';port=' . ($env['db_port'] ?? '3306') . ';dbname=' . $env['db_name'] . ';charset=utf8mb4',
            $env['db_user'],
            $env['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        // نفس توقيت مصر (بيراعي التوقيت الصيفي) — بالإزاحة الرقمية لأن أسماء المناطق مش مضمونة على الاستضافة
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('<div style="font-family:sans-serif;direction:rtl;padding:30px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;margin:20px">'
            . '<h3>⚠️ تعذّر الاتصال بقاعدة البيانات</h3><p>راجع بيانات الاتصال في <code>config/env.php</code>.</p>'
            . (DEBUG ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : '') . '</div>');
    }
}

// ── دوال قاعدة البيانات المختصرة ─────────────────────────────────────────────
function db_all(string $sql, array $params = []): array
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}
function db_one(string $sql, array $params = []): ?array
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
function db_val(string $sql, array $params = [])
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
function db_exec(string $sql, array $params = []): int
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
function db_insert(string $table, array $data): int
{
    global $pdo;
    $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
    $ph   = implode(',', array_fill(0, count($data), '?'));
    $st   = $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)");
    $st->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}
function db_update(string $table, int $id, array $data): void
{
    $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
    db_exec("UPDATE `$table` SET $set WHERE id=?", [...array_values($data), $id]);
}
/** ينفذ الدالة داخل معاملة واحدة (كله أو لا شيء) */
function db_tx(callable $fn)
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }

// ── الإعدادات المخزنة في قاعدة البيانات ──────────────────────────────────────
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all('SELECT skey, svalue FROM settings') as $r) { $cache[$r['skey']] = (string)$r['svalue']; }
        } catch (Throwable $e) { /* قبل التثبيت */ }
    }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, string $value): void
{
    db_exec('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
}

/** رقم تسلسلي تلقائي (بيتقفل جوه معاملة عشان ما يتكررش) */
function next_number(string $name, string $prefix, int $pad = 6): string
{
    db_exec('INSERT IGNORE INTO sequences (name, last_value) VALUES (?, 0)', [$name]);
    db_exec('UPDATE sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE name = ?', [$name]);
    $n = (int)db_val('SELECT LAST_INSERT_ID()');
    return $prefix . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}

// ── التنسيق والتحقق ──────────────────────────────────────────────────────────
function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float)$v, 2, '.', ','); }
/** مبلغ للعرض في HTML: الرقم + ج.م */
function egp($v): string { return '<span class="num">' . money($v) . '</span><span class="cur">' . CURRENCY . '</span>'; }
function qty($v): string
{
    $s = rtrim(rtrim(number_format((float)$v, 3, '.', ','), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}
function fdate(?string $d): string
{
    if (!$d || $d === '0000-00-00') { return '—'; }
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : e($d);
}
/** يحوّل نص رقم (يقبل الأرقام العربية والفاصلة) لـ float، أو null لو مش رقم */
function parse_num($s): ?float
{
    if ($s === null) { return null; }
    $s = trim((string)$s);
    if ($s === '') { return null; }
    $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '', ',' => '']);
    return is_numeric($s) ? (float)$s : null;
}
function parse_date($s): ?string
{
    $s = trim((string)$s);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) { return null; }
    return $s;
}
function add_days(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ' +' . $days . ' days'));
}
function money_eq(float $a, float $b): bool { return abs($a - $b) < 0.005; }
function post_str(string $key, int $max = 255): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}
function get_str(string $key, int $max = 100): string
{
    return mb_substr(trim((string)($_GET[$key] ?? '')), 0, $max);
}

// ── تنقل ورسائل ─────────────────────────────────────────────────────────────
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): never
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path);
    if (!headers_sent()) { header('Location: ' . $target); }
    else { echo '<script>location.href=' . json_encode($target) . '</script>'; }
    exit;
}
function flash(string $type, string $msg): void { $_SESSION['_flash'][] = [$type, $msg]; }
function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; }
/** يوقف أي طلب POST مفيهوش توكن صحيح */
function csrf_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
    $t = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($t) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(419);
        exit('<div style="font-family:sans-serif;direction:rtl;padding:40px;text-align:center"><h3>انتهت صلاحية الصفحة</h3><p>ارجع للصفحة السابقة وحدّثها ثم أعد المحاولة.</p></div>');
    }
}

// ── المستخدم والصلاحيات ─────────────────────────────────────────────────────
function is_logged_in(): bool { return !empty($_SESSION['user']); }
function current_user(): array { return $_SESSION['user'] ?? ['id' => 0, 'full_name' => '', 'role' => 'viewer', 'modules' => []]; }

function role_modules(string $role): array
{
    $m = ROLES[$role]['modules'] ?? [];
    return $m === ['*'] ? array_keys(MODULES) : $m;
}
function can(string $module): bool
{
    $u = current_user();
    if (($u['role'] ?? '') === 'admin') { return true; }
    if (in_array($module, ADMIN_ONLY_MODULES, true)) { return false; }
    return in_array($module, $u['modules'] ?? [], true);
}
/** أول صفحة يقدر المستخدم يفتحها (الرئيسية لو مسموحة، وإلا أول وحدة مسموحة) */
function home_path(): string
{
    foreach (['dashboard/' => 'dashboard', 'sales/' => 'sales', 'purchases/' => 'purchases', 'stock/' => 'stock', 'items/' => 'items', 'vouchers/' => 'vouchers',
              'treasury/' => 'treasury', 'customers/' => 'customers', 'suppliers/' => 'suppliers', 'reports/' => 'reports', 'audit/' => 'audit'] as $path => $mod) {
        if (can($mod)) { return $path; }
    }
    return 'users/profile.php';
}
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('auth/login.php');
    }
}
function require_module(string $module): void
{
    require_login();
    if (!can($module)) {
        http_response_code(403);
        exit('<div style="font-family:sans-serif;direction:rtl;text-align:center;padding:60px"><h2>غير مصرّح</h2><p>ليس لديك صلاحية الدخول لهذه الصفحة.</p><a href="' . e(url('dashboard/')) . '">العودة للرئيسية</a></div>');
    }
}
function load_user_session(array $u): void
{
    $mods = $u['permissions'] ? (json_decode($u['permissions'], true) ?: []) : role_modules($u['role']);
    $_SESSION['user'] = [
        'id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'],
        'role' => $u['role'], 'modules' => $mods, 'has_recovery' => !empty($u['recovery_hash']),
    ];
}

function audit(string $action, string $entity, ?int $entityId = null, string $details = ''): void
{
    try {
        $u = current_user();
        db_insert('audit_logs', [
            'user_id' => $u['id'] ?: null, 'user_name' => $u['full_name'] ?: null,
            'action' => $action, 'entity' => $entity, 'entity_id' => $entityId,
            'details' => mb_substr($details, 0, 2000), 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => now(),
        ]);
    } catch (Throwable $e) { error_log('audit failed: ' . $e->getMessage()); }
}

// ── ترقيم الصفحات ───────────────────────────────────────────────────────────
function page_num(): int { return max(1, (int)($_GET['page'] ?? 1)); }
function pager(int $total, int $perPage, int $page): string
{
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) { return ''; }
    $q = $_GET; unset($q['page']);
    $mk = fn(int $p) => '?' . http_build_query($q + ['page' => $p]);
    $h = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap">';
    $from = max(1, $page - 3); $to = min($pages, $page + 3);
    if ($page > 1) { $h .= '<li class="page-item"><a class="page-link" href="' . e($mk($page - 1)) . '">السابق</a></li>'; }
    for ($p = $from; $p <= $to; $p++) {
        $h .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . e($mk($p)) . '">' . $p . '</a></li>';
    }
    if ($page < $pages) { $h .= '<li class="page-item"><a class="page-link" href="' . e($mk($page + 1)) . '">التالي</a></li>'; }
    return $h . '</ul></nav>';
}

// ── تصدير CSV (بيفتح في Excel بالعربي صح) ───────────────────────────────────
function csv_download(string $filename, array $headers, array $rows): never
{
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '.csv"; filename*=UTF-8\'\'' . rawurlencode($filename) . '.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        // حماية من حقن الصيغ في Excel
        $r = array_map(fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v, $r);
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/pwa.php';
if (!IN_INSTALLER && $pdo) { run_migrations(); }
