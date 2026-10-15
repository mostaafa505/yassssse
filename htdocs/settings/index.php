<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('settings');
$keys = ['company_name', 'company_name_en', 'company_phone', 'company_address', 'company_tax_no', 'company_commercial_no', 'invoice_footer', 'vat_rate'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        $vat = parse_num($_POST['vat_rate'] ?? '14');
        if ($vat === null || $vat < 0 || $vat > 100) { throw new AppError('نسبة الضريبة غير صحيحة.'); }
        if (post_str('company_name', 200) === '') { throw new AppError('اسم الشركة مطلوب.'); }
        db_tx(function () use ($keys, $vat) {
            foreach ($keys as $k) { set_setting($k, $k === 'vat_rate' ? (string)$vat : post_str($k, 300)); }
            set_setting('allow_negative_treasury', empty($_POST['allow_negative_treasury']) ? '0' : '1');
            audit('update', 'settings', null, 'تعديل إعدادات الشركة');
        });
        flash('success', 'تم حفظ الإعدادات.');
        redirect('settings/');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
$v = []; foreach ($keys as $k) { $v[$k] = setting($k); }
page_start('الإعدادات', 'settings');
?>
<div class="cardx" style="max-width:860px"><div class="head"><i class="bi bi-gear"></i> بيانات الشركة والإعدادات</div>
<form method="post" class="body"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required">اسم الشركة (عربي)</label><input class="form-control" name="company_name" value="<?= e($v['company_name']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">اسم الشركة (إنجليزي)</label><input class="form-control" name="company_name_en" value="<?= e($v['company_name_en']) ?>" dir="ltr"></div>
    <div class="col-md-6"><label class="form-label">الهاتف</label><input class="form-control" name="company_phone" value="<?= e($v['company_phone']) ?>" dir="ltr"></div>
    <div class="col-md-6"><label class="form-label">العنوان</label><input class="form-control" name="company_address" value="<?= e($v['company_address']) ?>"></div>
    <div class="col-md-6"><label class="form-label">الرقم الضريبي</label><input class="form-control" name="company_tax_no" value="<?= e($v['company_tax_no']) ?>" dir="ltr"></div>
    <div class="col-md-6"><label class="form-label">السجل التجاري</label><input class="form-control" name="company_commercial_no" value="<?= e($v['company_commercial_no']) ?>" dir="ltr"></div>
    <div class="col-md-8"><label class="form-label">عبارة أسفل الفاتورة</label><input class="form-control" name="invoice_footer" value="<?= e($v['invoice_footer']) ?>"></div>
    <div class="col-md-4"><label class="form-label">نسبة الضريبة الافتراضية %</label><input class="form-control num" name="vat_rate" value="<?= e($v['vat_rate']) ?>" inputmode="decimal"><div class="form-text">للزر المساعد في الفواتير فقط — الضريبة بتتكتب يدوياً.</div></div>
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="allow_negative_treasury" value="1" id="neg"<?= setting('allow_negative_treasury') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="neg">السماح بأن يصبح رصيد الخزينة سالباً <span class="text-muted small">(غير مُوصى به — الافتراضي منع أي دفع أكبر من الرصيد)</span></label></div></div>
  </div>
  <div class="mt-4"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> حفظ</button></div>
</form></div>
<?php page_end();
