<?php
declare(strict_types=1);
/** تفعيل التطبيق (PWA): ميتا في الـ head + نافذة مساعدة التثبيت + سكربت مشترك قبل نهاية الصفحة */
function pwa_head(): void
{
    ?><link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<meta name="application-name" content="<?= e(APP_NAME) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ماهر البحيري">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<?php
}

function pwa_footer(): void
{
    ?><div class="modal fade" id="installHelp" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-download"></i> تثبيت التطبيق</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
  <div class="modal-body small lh-lg">
    <p class="mb-2"><b>آيفون / آيباد (Safari):</b> اضغط زر المشاركة <i class="bi bi-box-arrow-up"></i> ← «إضافة إلى الشاشة الرئيسية».</p>
    <p class="mb-2"><b>أندرويد (Chrome):</b> القائمة ⋮ ← «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».</p>
    <p class="mb-0"><b>كمبيوتر (Chrome / Edge):</b> أيقونة التثبيت في شريط العنوان ← «تثبيت».</p>
    <p class="text-muted mt-3 mb-0">التثبيت بيشتغل بس لو الموقع على <b>https://</b>.</p>
  </div></div></div></div>
<script src="<?= e(url('assets/js/common.js')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/common.js') ?>"></script>
<?php
}
