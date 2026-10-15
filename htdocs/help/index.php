<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
page_start('دليل الاستخدام', '');
$map = [
    ['شيت المشتريات', 'المشتريات', 'purchases/', 'فاتورة بأكتر من صنف. الإجمالي بيتحسب تلقائياً = الكمية × السعر − الخصم + النقل + الضريبة + الرسوم. حالة السداد (مدفوع / جزئي / آجل) بتتحدد تلقائياً من المبلغ المدفوع.'],
    ['شيت العملاء', 'العملاء + المبيعات + سندات القبض', 'customers/', 'لكل عميل كشف حساب مستقل: الرصيد السابق + الفواتير − التحصيلات = الرصيد المتبقي، مع موعد الاستحقاق وحالة الحساب (نشط / مغلق / متأخر) تلقائياً.'],
    ['شيت الموردين', 'الموردين + سندات الصرف', 'suppliers/', 'نفس فكرة العملاء لكن منفصل تماماً: المشتريات الآجلة تزيد المستحق للمورد، والسداد ينقصه.'],
    ['شيت المخازن', 'أرصدة المخازن + حركة المخزون', 'stock/', 'أول المدة + الوارد − المنصرف = الرصيد الحالي، وحالة «اطلب بضاعة» لما الرصيد يوصل لحد إعادة الطلب. الوارد والمنصرف بيتسجلوا تلقائياً من الفواتير، أو بإذن استلام / صرف / تسوية / تحويل بين المخازن.'],
    ['شيت الخزينة والمصروفات', 'الخزينة والمصروفات', 'treasury/', 'كل حركة بمستند، والرصيد بعد كل حركة بيتحسب تلقائياً (ما بيتكتبش يدوياً). الخزينة النقدية منفصلة عن البنك، والإيداع/السحب البنكي بينقل بين الحسابين.'],
];
$steps = [
    ['إعدادات الشركة', 'settings/', 'اسم الشركة، الرقم الضريبي، العنوان (بتظهر في الطباعة).'],
    ['حسابات الخزينة', 'treasury/accounts.php', 'راجع النقدية الفعلية وسجّل الرصيد الافتتاحي للخزينة والبنك.'],
    ['المخازن', 'warehouses/', 'أضف المخازن (فيه «المخزن الرئيسي» جاهز).'],
    ['الأصناف', 'items/form.php', 'أضف الأصناف بوحدتها وحد إعادة الطلب ورصيد أول المدة.'],
    ['الموردين والعملاء', 'suppliers/form.php', 'أضف كل جهة بحساب مستقل ومدة السداد/التحصيل، وسجّل الرصيد الافتتاحي لو في مديونية سابقة.'],
    ['التشغيل اليومي', 'purchases/form.php', 'فواتير الشراء والبيع، ثم سندات القبض والصرف، والمصروفات من الخزينة.'],
];
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="cardx"><div class="head"><i class="bi bi-signpost-split"></i> خطوات البداية (بالترتيب)</div>
    <ol class="list-group list-group-numbered list-group-flush">
    <?php foreach ($steps as [$t, $href, $d]): ?><li class="list-group-item d-flex justify-content-between align-items-start"><div class="ms-2 me-auto"><div class="fw-bold"><?= e($t) ?></div><div class="small text-muted"><?= e($d) ?></div></div><a class="btn btn-sm btn-outline-brand" href="<?= e(url($href)) ?>">فتح</a></li><?php endforeach; ?>
    </ol></div></div>
  <div class="col-lg-5"><div class="cardx h-100"><div class="head"><i class="bi bi-shield-check"></i> قواعد مهمة</div><div class="body small">
    <ul class="mb-0 lh-lg">
      <li><b>مستند لكل حركة:</b> رقم فاتورة المورد ورقم إيصال الخزينة مطلوبين ولا يتكررا.</li>
      <li><b>لا تعديل عشوائي:</b> الفاتورة والسند ما بيتعدلوش — بتلغيه وتسجله من جديد، والإلغاء بيتسجل في سجل العمليات.</li>
      <li><b>الأرصدة تلقائية:</b> رصيد الخزينة والمخزن والعميل والمورد بيتحسب من الحركات، مفيش رصيد بيتكتب يدوياً.</li>
      <li><b>منع الرصيد السالب:</b> مفيش بيع أو صرف أكتر من رصيد المخزن، ولا دفع أكتر من رصيد الخزينة.</li>
      <li><b>الضريبة:</b> بتتكتب كمبلغ حسب الفاتورة، ما بيفترضش النظام إنها داخلة في السعر.</li>
      <li><b>المراجعة:</b> طابق رصيد الخزينة والبنك والجرد الفعلي بشكل دوري. النظام مساعد تشغيلي وليس بديلاً عن مراجعة محاسب في القيود والضرائب والإقفال.</li>
    </ul></div></div></div>
  <div class="col-12"><div class="cardx"><div class="head"><i class="bi bi-table"></i> مقابل كل شيت إكسل في النظام</div>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>شيت الإكسل</th><th>الشاشة في النظام</th><th>ملاحظات</th></tr></thead><tbody>
    <?php foreach ($map as [$sheet, $screen, $href, $note]): ?><tr><td class="fw-bold"><?= e($sheet) ?></td><td><a href="<?= e(url($href)) ?>"><?= e($screen) ?></a></td><td class="small text-muted"><?= e($note) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <div class="col-12"><div class="cardx"><div class="head"><i class="bi bi-file-earmark-excel"></i> التصدير إلى Excel</div><div class="body small">
    كل قائمة وتقرير فيها زر <b>Excel</b> بينزّل ملف CSV بنفس أعمدة الشيت الأصلي (بيفتح في Excel بالعربي مباشرة): المشتريات، المخازن، الخزينة، كشوف الحسابات، السندات، والتقارير.</div></div></div>
</div>
<?php page_end();
