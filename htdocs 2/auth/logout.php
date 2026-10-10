<?php
require_once dirname(__DIR__) . '/config/config.php';

if (isset($_SESSION['user_id'])) {
    logAudit($pdo, 'logout', 'user', $_SESSION['user_id']);
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

@session_destroy();

$loginUrl = BASE_URL . '/auth/login.php';

// لازم نمسح كاش صفحات الـ HTML المخزنة أوفلاين هنا كمان — لو حد تاني فتح
// الجهاز بعد تسجيل الخروج وهو أوفلاين، أي صفحة كانت متخزنة (زي لوحة تحكم
// المستخدم اللي عمل logout) تقدر تتفتح من الكاش مباشرة من غير ما تعدي على
// السيرفر أصلاً، يعني من غير أي تحقق تسجيل دخول. لازم تفريغ الكاش يحصل
// فعليًا هنا (redirect() العادي بيعمل header() مباشرة من غير ما JS يشتغل).
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>
<script>
(async () => {
    try {
        if ('caches' in window) {
            const keys = await caches.keys();
            await Promise.all(keys.filter(k => !k.includes('assets')).map(k => caches.delete(k)));
        }
    } catch(e) {}
    try { localStorage.removeItem('pos_user'); } catch(e) {}
    window.location.href = " . json_encode($loginUrl) . ";
})();
</script>
<noscript><meta http-equiv='refresh' content='0;url={$loginUrl}'></noscript>
</body></html>";
exit;
