<?php
ob_start(); // منع مشكلة headers already sent
/**
 * Store POS System — Main Configuration
 */

// ── Error Reporting ───────────────────────────────────────────────────────────
ini_set('display_errors', 0);
error_reporting(0);

// ── Timezone (F-02) ───────────────────────────────────────────────────────────
// من غير السطر ده، PHP بيستخدم توقيت السيرفر الافتراضي (مختلف حسب الاستضافة —
// ممكن يكون UTC أو أي حاجة تانية)، ومستقل تمامًا عن توقيت MySQL، فالاتنين ممكن
// يختلفوا عن بعض وعن توقيت الإمارات الحقيقي. ده بيأثر على: التاريخ المكتوب في
// رقم الفاتورة (nextInvoiceNumber بيستخدم date() من PHP)، وقت created_at
// المسجل فعليًا (MySQL current_timestamp())، وكل فلاتر "اليوم/الأسبوع/الشهر"
// في الداشبورد والتقارير. الإمارات مالهاش توقيت صيفي (DST)، فالقيمة دي ثابتة
// وآمنة طول السنة من غير أي استثناء.
date_default_timezone_set('Asia/Dubai');

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 2592000; // 30 يوم — عشان الكاشير يفضل مسجل دخول حتى لو الجهاز شغال أوفلاين لفترة طويلة
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    // اسم كوكي مستقل عن store2 — القاعدتين بقوا منفصلين تمامًا، فلو الكوكي
    // اتشاركت (لو الموقعين شغالين على نفس الدومين/الهوست)، ممكن user_id
    // يتسجل في السيشن من قاعدة بيانات مختلفة عن اللي الصفحة الحالية شغالة
    // عليها، وده يبوظ أي INSERT فيه foreign key على users.id (أو أسوأ، يحصل
    // تطابق مصادفة مع مستخدم مختلف تمامًا).
    session_name('dahana_session');
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // يتفعل تلقائياً لو الموقع شغال HTTPS
        'httponly' => true,       // يمنع الوصول للكوكي عبر JavaScript (حماية من XSS)
        'samesite' => 'Lax',      // حماية أساسية من CSRF
    ]);
    session_start();
}

// ── Language ──────────────────────────────────────────────────────────────────
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
    $url = preg_replace('/([?&])lang=[^&]+(&|$)/', '$1', $_SERVER['REQUEST_URI']);
    $url = rtrim($url, '?&');
    header('Location: ' . $url);
    exit;
}
$lang = $_SESSION['lang'] ?? 'ar';

function getActiveBrandInfo(): array {
    global $pdo;
    static $brandCache = null;
    if ($brandCache !== null) {
        return $brandCache;
    }

    $nameAr = defined('APP_NAME') ? APP_NAME : 'المتجر';
    $nameEn = defined('APP_NAME_EN') ? APP_NAME_EN : 'Store';
    
    $logo = BASE_URL . '/assets/img/dahana.jpg';

    // ثابتين هنا (مش بيتفرض عليهم دومين السيرفر الفعلي)، عشان يفضلوا يظهروا
    // "www.dahana.ae" و"dahana.ae" في الفاتورة حتى لو الموقع شغال مؤقتًا على
    // دومين استضافة مختلف (زي InfinityFree) قبل ما الدومين الحقيقي يتربط.
    $website = 'www.dahana.ae';
    $instagram = 'dahana.ae';

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('store_name_ar', 'store_name_en', 'store_logo')");
            $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($dbSettings['store_name_ar'])) $nameAr = $dbSettings['store_name_ar'];
            if (!empty($dbSettings['store_name_en'])) $nameEn = $dbSettings['store_name_en'];
            if (!empty($dbSettings['store_logo'])) $logo = BASE_URL . '/' . ltrim($dbSettings['store_logo'], '/');
        } catch (Throwable $t) {
            // ignore if DB not ready
        }
    }

    $brandCache = [
        'id'          => 'store',
        'name_ar'     => $nameAr,
        'name_en'     => $nameEn,
        'logo'        => $logo,
        'logo_height' => '75px',
        'website'     => $website,
        'instagram'   => $instagram,
    ];
    return $brandCache;
}


function __(string $key): string {
    global $lang;
    static $translations = null;
    if ($translations === null) {
        $translations = [
            'ar' => [
                'dashboard' => 'الرئيسية',
                'pos' => 'نقطة البيع',
                'invoices' => 'الفواتير',
                'products' => 'المنتجات',
                'inventory' => 'المخزون',
                'offers' => 'العروض والخصومات',
                'wholesale' => 'كشف الجملة',
                'reports' => 'التقارير',
                'users' => 'المستخدمين والصلاحيات',
                'audit_log' => 'سجل العمليات',
                'logout' => 'تسجيل الخروج',
                'invoice' => 'فاتورة ضريبية',
                'invoice_no' => 'رقم الفاتورة',
                'date' => 'التاريخ',
                'cashier' => 'الكاشير',
                'item' => 'الصنف',
                'qty' => 'الكمية',
                'price' => 'السعر',
                'total' => 'الإجمالي',
                'subtotal' => 'المجموع الفرعي',
                'discount' => 'الخصم',
                'net_total' => 'الإجمالي الصافي',
                'payment_method' => 'طريقة الدفع',
                'thanks' => 'شكراً لتسوقكم معنا!',
                'welcome' => 'مرحباً',
                'overview_subtitle' => 'نظرة عامة على مبيعات المعرض والمخزون',
                'total_sales' => 'إجمالي المبيعات',
                'completed_invoices' => 'الفواتير المنجزة',
                'items_sold' => 'القطع المباعة',
                'stock_alerts' => 'تنبيهات المخزون',
                'cash_sales' => 'مبيعات النقد (كاش)',
                'card_sales' => 'مبيعات البطاقات (شبكة)',
                'transfer_sales' => 'مبيعات التحويلات',
                'recent_invoices' => 'آخر الفواتير الصادرة',
                'view_all' => 'عرض الكل',
                'staff_performance' => 'أداء الموظفين والكاشير',
                'time' => 'الوقت',
                'employee' => 'الموظف',
                'sales' => 'المبيعات',
                'today' => 'اليوم',
                'yesterday' => 'أمس',
                'this_week' => 'هذا الأسبوع',
                'this_month' => 'هذا الشهر',
                'all' => 'الكل',
            ],
            'en' => [
                'dashboard' => 'Dashboard',
                'pos' => 'Point of Sale',
                'invoices' => 'Invoices',
                'products' => 'Products',
                'inventory' => 'Inventory',
                'offers' => 'Offers & Discounts',
                'wholesale' => 'Wholesale List',
                'reports' => 'Reports',
                'users' => 'Users & Roles',
                'audit_log' => 'Audit Log',
                'logout' => 'Logout',
                'invoice' => 'Tax Invoice',
                'invoice_no' => 'Invoice No',
                'date' => 'Date',
                'cashier' => 'Cashier',
                'item' => 'Item',
                'qty' => 'Qty',
                'price' => 'Price',
                'total' => 'Total',
                'subtotal' => 'Subtotal',
                'discount' => 'Discount',
                'net_total' => 'Net Total',
                'payment_method' => 'Payment Method',
                'thanks' => 'Thank you for shopping with us!',
                'welcome' => 'Welcome',
                'overview_subtitle' => 'Overview of store sales and inventory',
                'total_sales' => 'Total Sales',
                'completed_invoices' => 'Completed Invoices',
                'items_sold' => 'Items Sold',
                'stock_alerts' => 'Stock Alerts',
                'cash_sales' => 'Cash Sales',
                'card_sales' => 'Card Sales',
                'transfer_sales' => 'Transfer Sales',
                'recent_invoices' => 'Recent Invoices',
                'view_all' => 'View All',
                'staff_performance' => 'Staff Performance',
                'time' => 'Time',
                'employee' => 'Employee',
                'sales' => 'Sales',
                'today' => 'Today',
                'yesterday' => 'Yesterday',
                'this_week' => 'This Week',
                'this_month' => 'This Month',
                'all' => 'All',
            ]
        ];
    }
    return $translations[$lang][$key] ?? $key;
}

// ── App Constants ─────────────────────────────────────────────────────────────// Basic Info
define('APP_NAME', 'دحانة');
define('APP_NAME_EN', 'Dahana');

/**
 * BASE_URL لازم يتحدد ديناميكياً حسب الجهاز اللي بيفتح الموقع (Host/IP/Port)،
 * مش يتثبت على "localhost". لو فضل مثبت على localhost، أي جهاز تاني (موبايل/تابلت)
 * بيفتح الموقع بالـ IP هيحاول يحمّل الصور/الـ manifest/الـ service worker من
 * "localhost" بتاعه هو (يعني نفسه)، فكل حاجة هتفشل بصمت والتطبيق هيبان مكسور
 * ومش هيتثبت كتطبيق حقيقي ولا الأوفلاين هيشتغل صح.
 *
 * وكمان مسار الفولدر ("/store") لازم يتحسب تلقائياً مش يتثبت في الكود، عشان
 * نفس الملف يشتغل صح محلياً (XAMPP: /store) وعلى أي سيرفر استضافة زي
 * InfinityFree لو رفعت الملفات في جذر الموقع مباشرة (من غير فولدر store أصلاً)
 * أو في أي فولدر تاني بأي اسم.
 */
function detectBaseUrl(): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $path    = '';
    if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $path = substr($appRoot, strlen($docRoot));
    }

    return $scheme . '://' . $host . $path;
}
define('BASE_URL',    detectBaseUrl());
define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL',  BASE_URL . '/uploads/');

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST', 'sql210.infinityfree.com');
define('DB_PORT', '3306');
define('DB_NAME', 'if0_42969075_root');
define('DB_USER', 'if0_42969075');
define('DB_PASS', 'ZLprIH7keh');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // نفس توقيت الإمارات بتاع PHP فوق (+04:00 ثابت، مفيش صيفي) — بنستخدم
    // الإزاحة الرقمية مش اسم المنطقة ("Asia/Dubai") عشان الأسماء محتاجة جدول
    // mysql.time_zone_name يكون متحمّل على السيرفر، وده مش مضمون على استضافات
    // مشتركة زي InfinityFree.
    $pdo->exec("SET time_zone = '+04:00'");
} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:30px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;margin:20px;">
        <h3>⚠️ خطأ في الاتصال بقاعدة البيانات</h3>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <small>تأكد من صحة بيانات الاتصال (Hostname / Username / Password) في لوحة تحكم InfinityFree</small>
    </div>');
}

// ── Helper Functions ──────────────────────────────────────────────────────────

/** Escape output */
function e(?string $val): string {
    return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect helper */
function redirect(string $path): void {
    $url = BASE_URL . '/' . ltrim($path, '/');
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>window.location.href="' . $url . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $url . '" /></noscript>';
    }
    exit;
}

/** Check if user is logged in */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

/** Require authentication, optionally restrict to roles */
function requireAuth(array $roles = []): void {
    if (!isLoggedIn()) {
        $_SESSION['_redirect'] = $_SERVER['REQUEST_URI'];
        redirect('auth/login.php');
    }
    if ($roles && !in_array($_SESSION['user_role'], $roles, true)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;text-align:center;padding:60px;">
            <h2>غير مصرح</h2><p>ليس لديك صلاحية الوصول لهذه الصفحة.</p>
            <a href="' . BASE_URL . '/dashboard/">العودة للرئيسية</a>
        </div>');
    }
}

/**
 * حماية CSRF (F-05) — توكن واحد لكل سيشن، بيتولد مرة واحدة ويفضل ثابت طول
 * ما المستخدم عامل لوج إن. أي طلب POST (سواء فورم عادي أو fetch/AJAX)
 * لازم يبعت نفس التوكن ده، وإلا يترفض.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** يطبع حقل hidden لحط جوه أي <form method="POST"> في الموقع */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(?string $providedToken = null): bool {
    $token = $providedToken ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
    return !empty($_SESSION['csrf_token']) && $token && hash_equals($_SESSION['csrf_token'], $token);
}

/** Check fine-grained permission stored in session JSON */
function hasPermission(string $perm): bool {
    if (($_SESSION['user_role'] ?? '') === 'admin') return true;
    $perms = $_SESSION['user_permissions'] ?? [];
    if (is_string($perms)) $perms = json_decode($perms, true) ?? [];
    return in_array($perm, $perms, true);
}

function requireAccess(array $roles, string $permission): void {
    requireAuth();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true) && !hasPermission($permission)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;text-align:center;padding:60px;">
            <h2>غير مصرح</h2><p>ليس لديك صلاحية الوصول لهذه الصفحة.</p>
            <a href="' . BASE_URL . '/dashboard/">العودة للرئيسية</a>
        </div>');
    }
}

function getAvailablePermissions(): array {
    return [
        'الوصول للشاشات (أي دور يقدر ياخدها)' => [
            'access_pos'         => 'نقطة البيع (POS)',
            'manage_products'    => 'إضافة وتعديل المنتجات',
            'manage_inventory'   => 'المخزون (جرد، استلام، تحويل)',
            'manage_offers'      => 'العروض والخصومات',
            'manage_wholesale'   => 'كشف الجملة',
            'manage_exhibitions' => 'إدارة المعارض والمصاريف',
            'manage_treasury'    => 'الصندوق الرئيسي والمشتريات',
            'view_reports'       => 'التقارير',
            'view_audit_log'     => 'سجل العمليات',
        ],
        'صلاحيات دقيقة داخل الفاتورة والمنتجات' => [
            'delete_product' => 'حذف المنتجات',
            'edit_price'     => 'تعديل الأسعار',
            'view_cost'      => 'رؤية التكلفة للمنتجات',
            'void_invoice'   => 'إلغاء فواتير البيع',
            'apply_discount' => 'تطبيق خصم في الفاتورة',
            'apply_wholesale_price' => 'البيع بسعر الجملة',
        ],
    ];
}

/** Format currency */
function money(float $amount): string {
    return 'AED ' . number_format($amount, 2);
}

function processReturn(PDO $pdo, int $saleId, string $invoiceNumber, array $items, string $reason, int $userId, string $notePrefix = 'مرتجع من فاتورة', ?string $refundMethod = null): array {
    $items = array_values(array_filter($items, fn($it) => floatval($it['qty'] ?? 0) > 0));
    if (empty($items)) {
        throw new Exception('لا توجد بنود صالحة للإرجاع.');
    }

    $refundMethod = in_array($refundMethod, ['cash', 'card', 'transfer'], true) ? $refundMethod : null;

    $refundTotal = 0;
    foreach ($items as $it) {
        $refundTotal += floatval($it['qty']) * floatval($it['unit_price']);
    }

    $stmtReturn = $pdo->prepare("INSERT INTO returns (sale_id, processed_by, reason, refund_method, total) VALUES (?, ?, ?, ?, ?)");
    $stmtReturn->execute([$saleId, $userId, $reason, $refundMethod, $refundTotal]);
    $returnId = $pdo->lastInsertId();

    $stmtRi = $pdo->prepare("INSERT INTO return_items (return_id, sale_item_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
    $stmtMov = $pdo->prepare("
        INSERT INTO inventory_movements (product_id, variant_id, movement_type, quantity, reference_id, notes, user_id)
        VALUES (?, ?, 'return', ?, ?, ?, ?)
    ");

    $returnedSummary = [];
    foreach ($items as $it) {
        $qty = floatval($it['qty']);
        $lineTotal = $qty * floatval($it['unit_price']);
        $stmtRi->execute([$returnId, $it['sale_item_id'], $qty, $it['unit_price'], $lineTotal]);

        if (!empty($it['variant_id'])) {
            $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + ? WHERE id = ?")->execute([$qty, $it['variant_id']]);
        } elseif (!empty($it['product_id'])) {
            $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?")->execute([$qty, $it['product_id']]);
        }

        $stmtMov->execute([
            $it['product_id'] ?? null, $it['variant_id'] ?? null, $qty, $saleId,
            "$notePrefix #$invoiceNumber — السبب: $reason", $userId
        ]);
        $returnedSummary[] = "{$qty}x {$it['product_name_ar']} (" . money($lineTotal) . ")";
    }

    $stmt = $pdo->prepare("
        SELECT si.id, si.quantity, COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0) as already_returned
        FROM sale_items si WHERE si.sale_id = ?
    ");
    $stmt->execute([$saleId]);
    $fullyReturned = true;
    foreach ($stmt->fetchAll() as $row) {
        if (floatval($row['already_returned']) < floatval($row['quantity'])) { $fullyReturned = false; break; }
    }
    $newStatus = $fullyReturned ? 'returned' : 'partial_return';
    $pdo->prepare("UPDATE sales SET status = ? WHERE id = ?")->execute([$newStatus, $saleId]);

    return ['refund_total' => $refundTotal, 'new_status' => $newStatus, 'returned_summary' => $returnedSummary, 'refund_method' => $refundMethod];
}

/** Generate invoice number (Concurrency Safe) */
function nextInvoiceNumber(PDO $pdo): string {
    return 'INV-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

function logAudit(PDO $pdo, string $action, string $entity, int $entityId, $old = null, $new = null): void {
    try {
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, user_name, action, entity_type, entity_id, old_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $_SESSION['user_id']   ?? 0,
            $_SESSION['user_name_ar'] ?? 'System',
            $action,
            $entity,
            $entityId,
            $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    } catch (Throwable $e) { /* silent */ }
}

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $row = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
        $row->execute([$key]);
        return $row->fetchColumn() ?: $default;
    } catch (Throwable $e) { return $default; }
}

/**
 * رصيد الصندوق الرئيسي (كاش فعلي بس) = مجموع حركات الدخول (funding) ناقص
 * مجموع حركات الخروج، وبس اللي طريقة دفعها cash — لأن الصندوق ده صندوق
 * نقدية فعلية، ومصروف اتدفع ببطاقة أو تحويل ماخدش من الكاش الموجود في
 * الدرج فعليًا. الرصيد ده مش مخزّن في أي مكان، بيتحسب لحظيًا من سجل
 * treasury_transactions عشان يفضل مطابق للحركات الفعلية دايمًا (مفيش
 * خطر إن "رقم مخزّن" يختلف عن "الحركات الحقيقية").
 */
function getTreasuryCashBalance(PDO $pdo): float {
    try {
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(CASE WHEN type = 'funding' THEN amount ELSE -amount END), 0) as balance
            FROM treasury_transactions
            WHERE payment_method = 'cash'
        ");
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function barcodeExists(PDO $pdo, string $barcode, ?int $excludeProductId = null, ?int $excludeVariantsOfProductId = null): bool {
    if ($barcode === '') return false;

    $sqlP = "SELECT id FROM products WHERE barcode = ?" . ($excludeProductId ? " AND id != ?" : "");
    $paramsP = $excludeProductId ? [$barcode, $excludeProductId] : [$barcode];
    $stmtP = $pdo->prepare($sqlP);
    $stmtP->execute($paramsP);
    if ($stmtP->fetch()) return true;

    $sqlV = "SELECT id FROM product_variants WHERE barcode = ?" . ($excludeVariantsOfProductId ? " AND product_id != ?" : "");
    $paramsV = $excludeVariantsOfProductId ? [$barcode, $excludeVariantsOfProductId] : [$barcode];
    $stmtV = $pdo->prepare($sqlV);
    $stmtV->execute($paramsV);
    if ($stmtV->fetch()) return true;

    return false;
}

function getActiveOffers(PDO $pdo): array {
    try {
        return $pdo->query("
            SELECT * FROM offers
            WHERE is_active = 1
              AND (start_date IS NULL OR start_date <= CURDATE())
              AND (end_date IS NULL OR end_date >= CURDATE())
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function computeWeightedAverageCost(float $existingQty, ?float $existingCost, float $receivedQty, float $receivedCost): float {
    if ($existingCost === null || $existingQty <= 0) {
        return $receivedCost;
    }
    return (($existingQty * $existingCost) + ($receivedQty * $receivedCost)) / ($existingQty + $receivedQty);
}

function computeOfferPrice(array $activeOffers, float $retailPrice, int $productId, ?int $categoryId): ?float {
    $best = null;
    foreach ($activeOffers as $o) {
        $applies = false;
        if ($o['apply_to'] === 'all') {
            $applies = true;
        } else {
            $targets = json_decode($o['target_ids'] ?? '[]', true) ?: [];
            if ($o['apply_to'] === 'category' && $categoryId !== null && in_array($categoryId, $targets)) {
                $applies = true;
            } elseif ($o['apply_to'] === 'product' && in_array($productId, $targets)) {
                $applies = true;
            }
        }
        if (!$applies) continue;

        $price = match ($o['discount_type']) {
            'percent'   => $retailPrice * (1 - floatval($o['discount_value']) / 100),
            'fixed'     => $retailPrice - floatval($o['discount_value']),
            'set_price' => floatval($o['discount_value']),
            default     => null,
        };
        if ($price === null) continue;
        $price = max(0, round($price, 2));
        if ($best === null || $price < $best) $best = $price;
    }
    return $best;
}

function compressAndSaveImage(string $tmpPath, string $targetDir, string $baseName, int $maxDim = 800, int $quality = 72): string|false {
    if (!function_exists('imagecreatefromjpeg')) return false;

    $info = @getimagesize($tmpPath);
    if (!$info) return false;

    $mime = $info['mime'];
    $srcImage = match ($mime) {
        'image/jpeg'  => @imagecreatefromjpeg($tmpPath),
        'image/png'   => @imagecreatefrompng($tmpPath),
        'image/webp'  => @imagecreatefromwebp($tmpPath),
        'image/gif'   => @imagecreatefromgif($tmpPath),
        default       => false,
    };

    if (!$srcImage) return false;

    $origW = imagesx($srcImage);
    $origH = imagesy($srcImage);

    if ($origW > $maxDim || $origH > $maxDim) {
        $ratio = min($maxDim / $origW, $maxDim / $origH);
        $newW  = (int)round($origW * $ratio);
        $newH  = (int)round($origH * $ratio);
    } else {
        $newW = $origW;
        $newH = $origH;
    }

    $dst = imagecreatetruecolor($newW, $newH);

    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
    imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
    imagealphablending($dst, true);

    imagecopyresampled($dst, $srcImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($srcImage);

    $filename = $baseName . '.webp';
    $saved = @imagewebp($dst, $targetDir . $filename, $quality);
    imagedestroy($dst);

    return $saved ? $filename : false;
}