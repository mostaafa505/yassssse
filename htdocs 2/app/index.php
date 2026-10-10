<?php
$pageTitle = 'تثبيت التطبيق / Install App';
require_once dirname(__DIR__) . '/includes/header.php';

$brandInfo = getActiveBrandInfo();
?>

<div class="row justify-content-center py-4">
    <div class="col-lg-9 col-xl-8">
        
        <!-- App Header Card -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4" style="background: linear-gradient(135deg, #0f1117 0%, #1e293b 100%);">
            <div class="card-body p-4 text-white">
                <div class="d-flex flex-column flex-md-row align-items-center gap-4 text-center text-md-start">
                    <div class="position-relative">
                        <img src="<?= $brandInfo['logo'] ?>" alt="<?= e($brandInfo['name_ar']) ?>" 
                             class="bg-white p-2 rounded-4 shadow" 
                             style="width: 100px; height: 100px; object-fit: contain;">
                        <span class="position-absolute top-0 start-0 translate-middle p-2 bg-success border border-light rounded-circle" title="جاهز للتثبيت والعمل بدون إنترنت">
                            <span class="visually-hidden">أوفلاين جاهز</span>
                        </span>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-2 mb-1">
                            <h3 class="fw-bold mb-0 text-white"><?= e($brandInfo['name_ar']) ?> POS App</h3>
                            <span class="badge bg-primary rounded-pill px-3 py-1 fs-7">WebView & PWA</span>
                        </div>
                        <p class="text-slate-300 mb-2 small">تطبيق المبيعات وإدارة المخزون السريع — يعمل بالكامل أوفلاين بدون إنترنت كبرنامج مخصص لفتح وتوليد الفواتير.</p>
                        <div class="d-flex flex-wrap justify-content-center justify-content-md-start gap-3 small text-slate-400">
                            <span><i class="bi bi-shield-check text-success me-1"></i> إصدار آمن 2.5</span>
                            <span><i class="bi bi-wifi-off text-warning me-1"></i> وضع Offline كامل</span>
                            <span><i class="bi bi-lightning-charge-fill text-info me-1"></i> سرعة عالية</span>
                        </div>
                    </div>
                    <div>
                        <button id="mainInstallAppBtn" onclick="triggerAppInstall()" class="btn btn-primary btn-lg rounded-3 px-4 py-3 fw-bold text-nowrap shadow-lg d-flex align-items-center gap-2">
                            <i class="bi bi-download fs-5"></i>
                            <span>تثبيت التطبيق الآن</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Installation Methods Tabs / Guides -->
        <div class="row g-4 mb-4">
            
            <!-- Method 1: Android / Webview -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="bg-success-subtle text-success p-3 rounded-3 fs-3">
                                <i class="bi bi-android2"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">أجهزة أندرويد و شاشات POS</h6>
                                <small class="text-muted">Android & Chrome WebView</small>
                            </div>
                        </div>
                        <ol class="ps-3 mb-0 small text-secondary lh-lg">
                            <li>اضغط على زر <strong>"تثبيت التطبيق الآن"</strong> بالأعلى.</li>
                            <li>ستظهر لك نافذة منبثقة من المتصفح تفيد بتثبيت <strong><?= e($brandInfo['name_ar']) ?> POS</strong>.</li>
                            <li>انقر على <strong>"تثبيت / Install"</strong> لربط التطبيق بالشاشة الرئيسية.</li>
                            <li>سيظهر رمز التطبيق بأيقونة المتجر الخاصة على الشاشة.</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Method 2: iOS / iPad -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="bg-dark text-white p-3 rounded-3 fs-3">
                                <i class="bi bi-apple"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">أجهزة آيفون وآيباد (iOS / iPadOS)</h6>
                                <small class="text-muted">Safari WebView App</small>
                            </div>
                        </div>
                        <ol class="ps-3 mb-0 small text-secondary lh-lg">
                            <li>افتح هذا الرابط باستخدام متصفح <strong>Safari</strong>.</li>
                            <li>اضغط على زر المشاركة <i class="bi bi-box-arrow-up text-primary fw-bold"></i> أسفل الشاشة.</li>
                            <li>اختر <strong>"إضافة إلى الشاشة الرئيسية" (Add to Home Screen)</strong>.</li>
                            <li>اضغط على <strong>"إضافة"</strong> لتوليد التطبيق بأيقونة وشاشة مستقلة.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Offline Features & Advantages Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-stars text-warning me-2"></i> مميزات تطبيق الـ WebView والأوفلاين</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 border rounded-3 bg-light h-100">
                        <i class="bi bi-wifi-off fs-3 text-primary d-block mb-2"></i>
                        <strong class="d-block mb-1 text-dark">يعمل بدون إنترنت</strong>
                        <p class="text-muted small mb-0">يمكنك إجراء عمليات البيع وتوليد الفواتير وطباعتها حتى عند انقطاع شبكة الإنترنت بالكامل.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded-3 bg-light h-100">
                        <i class="bi bi-arrow-repeat fs-3 text-success d-block mb-2"></i>
                        <strong class="d-block mb-1 text-dark">مزامنة تلقائية فورية</strong>
                        <p class="text-muted small mb-0">بمجرد عودة الإنترنت، يرفع التطبيق جميع الفواتير المعلقة إلى قاعدة البيانات دون أي تدخل.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded-3 bg-light h-100">
                        <i class="bi bi-phone-vibrate fs-3 text-purple d-block mb-2"></i>
                        <strong class="d-block mb-1 text-dark">تجربة تطبيق شاشة كاملة</strong>
                        <p class="text-muted small mb-0">يختفي شريط المتصفح ويعمل البرنامج كتطبيق مستقل على الجهاز كأنك قمت بتنزيله من المتجر.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
async function triggerAppInstall() {
    // لو التطبيق شغال بالفعل كتطبيق مستقل (يعني اتثبت وبيتفتح من الأيقونة)
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        alert('✅ التطبيق مثبت بالفعل على جهازك. افتحه من أيقونته على الشاشة الرئيسية بدل ما تفتحه من المتصفح.');
        return;
    }

    if (deferredPrompt) {
        deferredPrompt.prompt();
        const choiceResult = await deferredPrompt.userChoice;
        if (choiceResult.outcome === 'accepted') {
            console.log('User accepted the install prompt');
        }
        deferredPrompt = null;
        return;
    }

    // مفيش برومبت تثبيت متاح من المتصفح — شخّص السبب الحقيقي بدل رسالة عامة
    const diag = await diagnoseInstallability();
    showInstallDiagnostics(diag);
}

async function diagnoseInstallability() {
    const result = { secure: false, manifestOk: false, swOk: false, isIOS: false, manifestError: '' };

    result.isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    result.secure = location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname);

    try {
        const res = await fetch('<?= BASE_URL ?>/manifest.json', { cache: 'no-store' });
        const m = await res.json();
        result.manifestOk = res.ok && Array.isArray(m.icons) && m.icons.length > 0 && !!m.start_url;
    } catch (e) {
        result.manifestOk = false;
        result.manifestError = e.message;
    }

    try {
        const regs = await navigator.serviceWorker.getRegistrations();
        result.swOk = regs.some(r => r.active);
    } catch (e) {
        result.swOk = false;
    }

    return result;
}

function showInstallDiagnostics(d) {
    if (d.isIOS) {
        alert('📱 على آيفون/آيباد، التثبيت بيتم يدوياً بس عن طريق Safari:\n\nالمشاركة (Share) ⬆️ ← "إضافة إلى الشاشة الرئيسية" (Add to Home Screen).\n\nده قيد من Apple نفسها على كل مواقع الويب، مفيش زر تلقائي بديل له على iOS.');
        return;
    }
    if (!d.secure) {
        alert('⚠️ السبب: التطبيق مش شغال حالياً على رابط آمن (HTTPS) أو على "localhost" بالظبط.\n\nالمتصفح (Chrome) بيرفض يفعّل خاصية "التثبيت الحقيقي" (Full App Install) إلا على رابط آمن — وده قيد أمان من جوجل نفسها مش عيب في التطبيق.\n\nالحل: لازم دومين حقيقي عليه شهادة SSL (HTTPS)، أو تفتح الموقع من نفس جهاز السيرفر عن طريق "localhost".');
        return;
    }
    if (!d.manifestOk) {
        alert('⚠️ السبب: ملف إعدادات التطبيق (manifest.json) مش بيتحمّل صح أو ناقصه بيانات.\nجرب تعمل تحديث كامل للصفحة (Ctrl+Shift+R) وحاول تاني.' + (d.manifestError ? '\n\nتفاصيل تقنية: ' + d.manifestError : ''));
        return;
    }
    if (!d.swOk) {
        alert('⚠️ السبب: الـ Service Worker (المسؤول عن التثبيت والعمل بدون إنترنت) لسه بيتسجل. جرب تنتظر ثواني وتعمل تحديث للصفحة (Refresh) ثم اضغط "تثبيت التطبيق" تاني.');
        return;
    }
    alert('المتصفح لسه مستني تتفاعل أكتر مع الموقع قبل ما يورّي زر التثبيت تلقائياً (سياسة من جوجل). جرب:\n\n1) تصفح صفحتين تلاتة في الموقع وارجع هنا وحاول تاني.\n2) أو افتح قائمة المتصفح (⋮ فوق يمين Chrome) واختر "تثبيت التطبيق" أو "Add to Home screen" يدوياً من هناك.');
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
