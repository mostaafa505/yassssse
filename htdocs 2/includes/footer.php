<!-- شريط حالة الاتصال العالمي — يظهر في كل الصفحات -->
<div id="globalOfflineBar" style="display:none; position:fixed; top:0; left:0; right:0; z-index:99999; background:#b45309; color:#fff; text-align:center; padding:10px 20px; font-weight:700; font-size:0.88rem; font-family:'Cairo',sans-serif; direction:rtl; box-shadow:0 2px 8px rgba(0,0,0,0.3);">
    <i class="bi bi-wifi-off me-2"></i>
    <span>وضع بدون اتصال — البيانات المعروضة من آخر زيارة · الفواتير الجديدة ستُحفظ محلياً وترفع تلقائياً</span>
</div>
<script>
// إظهار الشريط لو مفيش انترنت عند تحميل الصفحة
if (!navigator.onLine) {
    const bar = document.getElementById('globalOfflineBar');
    if (bar) bar.style.display = 'block';
}
</script>

    </main>

    <!-- Footer -->
    <footer class="bg-white border-top py-3 px-4 text-center text-muted small mt-auto no-print">
        <div class="d-flex justify-content-between align-items-center">
            <span><?= APP_NAME ?> &copy; <?= date('Y') ?> — جميع الحقوق محفوظة</span>
            <span>العملة: <strong>درهم إماراتي (AED)</strong></span>
        </div>
    </footer>
</div>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom App JS -->
<?php
// نفس مبدأ app.css بالظبط (راجع header.php) — رقم إصدار ثابت مبني على وقت
// آخر تعديل فعلي للملف، مش وقت الطلب، عشان أي تحديث لـ app.js (زي حماية
// CSRF الجديدة) يوصل فورًا لكل متصفح من غير ما يستنى الكاش القديم ينتهي،
// من غير ما يكسر تخزين Service Worker الأوفلاين لنسخة قديمة كانت شغالة
$appJsFile = dirname(__DIR__) . '/assets/js/app.js';
$appJsVer = file_exists($appJsFile) ? filemtime($appJsFile) : '1';
?>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= $appJsVer ?>"></script>
</body>
</html>
