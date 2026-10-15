<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('treasury');

$id = (int)($_GET['id'] ?? 0);
$t = $id ? db_one('SELECT * FROM treasury_transactions WHERE id=?', [$id]) : null;
if ($id && (!$t || $t['ref_type'])) { flash('error', 'الحركة غير موجودة أو مرتبطة بمستند (تُعدَّل من المستند الأصلي).'); redirect('treasury/'); }
$dir = $t ? ((float)$t['receipt_amount'] > 0 ? 'receipt' : 'payment') : (($_GET['dir'] ?? 'payment') === 'receipt' ? 'receipt' : 'payment');
$f = $t ? ['txn_date' => $t['txn_date'], 'doc_no' => $t['doc_no'], 'description' => $t['description'], 'category' => $t['category'], 'direction' => $dir,
           'amount' => (float)$t['receipt_amount'] + (float)$t['payment_amount'], 'account_id' => $t['account_id'], 'payment_method' => $t['payment_method'], 'responsible' => $t['responsible'], 'notes' => $t['notes']]
         : ['txn_date' => today(), 'doc_no' => '', 'description' => '', 'category' => '', 'direction' => $dir, 'amount' => '', 'account_id' => 0, 'payment_method' => 'نقدي', 'responsible' => current_user()['full_name'], 'notes' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $f = array_merge($f, $_POST);
    try {
        $d = $_POST; $d['amount'] = parse_num($_POST['amount'] ?? '') ?? 0.0;
        treasury_manual_save($d, $id ?: null);
        flash('success', 'تم حفظ الحركة.');
        redirect('treasury/');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
$accounts = db_all('SELECT id, name FROM treasury_accounts WHERE is_active=1 OR id=? ORDER BY id', [(int)$f['account_id']]);
if (!$f['account_id']) { $f['account_id'] = $accounts[0]['id'] ?? 0; }
page_start($id ? 'تعديل حركة خزينة' : 'حركة خزينة جديدة', 'treasury');
?>
<div class="cardx" style="max-width:820px"><div class="head"><i class="bi bi-safe2"></i> حركة خزينة</div>
<form method="post" class="body"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label required">نوع الحركة</label><select class="form-select" name="direction" id="dirSel"><option value="receipt"<?= $f['direction'] === 'receipt' ? ' selected' : '' ?>>مقبوضات (دخل)</option><option value="payment"<?= $f['direction'] === 'payment' ? ' selected' : '' ?>>مدفوعات (مصروف)</option></select></div>
    <div class="col-md-4"><label class="form-label required">التصنيف</label><select class="form-select" name="category" id="catSel" required></select></div>
    <div class="col-md-4"><label class="form-label required">التاريخ</label><input class="form-control" type="date" name="txn_date" value="<?= e($f['txn_date']) ?>" required></div>
    <div class="col-md-4"><label class="form-label required">رقم الإيصال / المستند</label><input class="form-control" name="doc_no" value="<?= e($f['doc_no']) ?>" required maxlength="60"><div class="form-text">لا تسجل حركة بدون مستند.</div></div>
    <div class="col-md-8"><label class="form-label required">البيان</label><input class="form-control" name="description" value="<?= e($f['description']) ?>" required maxlength="300" placeholder="مثال: دفع نقل بضاعة / إيجار شهر أكتوبر"></div>
    <div class="col-md-4"><label class="form-label required">المبلغ (<?= CURRENCY ?>)</label><input class="form-control num" name="amount" value="<?= e((string)$f['amount']) ?>" inputmode="decimal" required></div>
    <div class="col-md-4"><label class="form-label required">الحساب</label><select class="form-select" name="account_id"><?= select_options($accounts, 'id', 'name', $f['account_id'], false) ?></select></div>
    <div class="col-md-4"><label class="form-label required">طريقة الدفع</label><select class="form-select" name="payment_method"><?= simple_options(PAYMENT_METHODS, $f['payment_method']) ?></select></div>
    <div class="col-md-6"><label class="form-label">المسؤول</label><input class="form-control" name="responsible" value="<?= e((string)$f['responsible']) ?>" maxlength="120"></div>
    <div class="col-md-6"><label class="form-label">ملاحظات</label><input class="form-control" name="notes" value="<?= e((string)$f['notes']) ?>" maxlength="500"></div>
  </div>
  <div class="alert alert-info small mt-3 mb-0">تحصيل العملاء وسداد الموردين بيتسجلوا من <b>سندات القبض والصرف</b>، والإيداع/السحب البنكي من شاشة <b>الإيداع والسحب</b> — عشان يتحدّث حساب العميل/المورد تلقائياً.</div>
  <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="./">إلغاء</a></div>
</form></div>
<script>
(function(){var cats={receipt:<?= json_encode(MANUAL_RECEIPT_CATEGORIES, JSON_UNESCAPED_UNICODE) ?>,payment:<?= json_encode(MANUAL_PAYMENT_CATEGORIES, JSON_UNESCAPED_UNICODE) ?>},cur=<?= json_encode((string)$f['category'], JSON_UNESCAPED_UNICODE) ?>,d=document.getElementById('dirSel'),c=document.getElementById('catSel');
function f(){c.innerHTML='';cats[d.value].forEach(function(x){var o=document.createElement('option');o.textContent=x;if(x===cur)o.selected=true;c.appendChild(o);});}
d.addEventListener('change',function(){cur='';f();});f();})();
</script>
<?php page_end();
