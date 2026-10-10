<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('vouchers');

$type = ($_POST['type'] ?? $_GET['type'] ?? 'receipt') === 'payment' ? 'payment' : 'receipt';
$isReceipt = $type === 'receipt';
$pc = party_cfg($isReceipt ? 'customer' : 'supplier');
$f = ['voucher_date' => today(), 'doc_no' => '', 'party_id' => (int)($_GET['party'] ?? 0), 'amount' => '', 'account_id' => 0, 'payment_method' => 'نقدي',
      'alloc' => !empty($_GET['invoice']) ? 'invoice' : 'auto', 'invoice_id' => (int)($_GET['invoice'] ?? 0), 'notes' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $f = array_merge($f, $_POST);
    try {
        $d = $_POST; $d['amount'] = parse_num($_POST['amount'] ?? '') ?? 0.0;
        $id = post_voucher($type, $d);
        flash('success', 'تم تسجيل ' . ($isReceipt ? 'سند القبض' : 'سند الصرف') . '.');
        redirect('vouchers/view.php?id=' . $id);
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
$parties = db_all("SELECT id, name FROM {$pc['table']} WHERE is_active=1 OR id=? ORDER BY name", [(int)$f['party_id']]);
$accounts = db_all('SELECT id, name, acc_type FROM treasury_accounts WHERE is_active=1 ORDER BY id');
if (!$f['account_id']) { $f['account_id'] = $accounts[0]['id'] ?? 0; }
// الفواتير المفتوحة لكل طرف (للاختيار المباشر)
$kind = $isReceipt ? 'sale' : 'purchase'; $ic = inv_cfg($kind);
$openMap = [];
foreach (db_all("SELECT id, {$ic['party_col']} AS pid, doc_no, invoice_date, total, paid_amount FROM {$ic['table']} WHERE status='active' AND total - paid_amount > 0.005 ORDER BY invoice_date, id") as $o) {
    $openMap[$o['pid']][] = ['id' => (int)$o['id'], 'label' => $o['doc_no'] . ' — ' . fdate($o['invoice_date']) . ' — متبقي ' . money((float)$o['total'] - (float)$o['paid_amount']), 'remaining' => round((float)$o['total'] - (float)$o['paid_amount'], 2)];
}
page_start($isReceipt ? 'سند قبض من عميل' : 'سند صرف لمورد', 'vouchers');
?>
<div class="cardx" style="max-width:820px"><div class="head"><i class="bi bi-cash-coin"></i> <?= $isReceipt ? 'سند قبض' : 'سند صرف' ?>
  <span class="ms-auto d-flex gap-1"><a class="btn btn-sm <?= $isReceipt ? 'btn-gold' : 'btn-outline-brand' ?>" href="?type=receipt">قبض</a><a class="btn btn-sm <?= !$isReceipt ? 'btn-gold' : 'btn-outline-brand' ?>" href="?type=payment">صرف</a></span></div>
<form method="post" class="body" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>">
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label required">التاريخ</label><input class="form-control" type="date" name="voucher_date" value="<?= e($f['voucher_date']) ?>" required></div>
    <div class="col-md-4"><label class="form-label">رقم السند</label><input class="form-control" name="doc_no" value="<?= e($f['doc_no']) ?>" maxlength="60" placeholder="تلقائي (<?= $isReceipt ? 'RV' : 'PV' ?>-…) لو فارغ"></div>
    <div class="col-md-4"><label class="form-label required">المبلغ (<?= CURRENCY ?>)</label><input class="form-control num" name="amount" value="<?= e((string)$f['amount']) ?>" inputmode="decimal" required></div>
    <div class="col-md-12"><label class="form-label required"><?= $isReceipt ? 'العميل' : 'المورد' ?></label><select class="form-select" name="party_id" id="partySel" required><option value="">— اختر —</option>
      <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$f['party_id'] === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
      <div class="form-text" id="balHint"></div></div>
    <div class="col-md-6"><label class="form-label required"><?= $isReceipt ? 'يُودَع في' : 'يُصرف من' ?></label><select class="form-select" name="account_id"><?= select_options($accounts, 'id', 'name', $f['account_id'], false) ?></select></div>
    <div class="col-md-6"><label class="form-label required">طريقة الدفع</label><select class="form-select" name="payment_method"><?= simple_options(PAYMENT_METHODS, $f['payment_method']) ?></select></div>
    <div class="col-md-6"><label class="form-label">توزيع المبلغ</label><select class="form-select" name="alloc" id="allocSel">
        <option value="auto"<?= $f['alloc'] === 'auto' ? ' selected' : '' ?>>تلقائي على أقدم الفواتير المفتوحة</option>
        <option value="invoice"<?= $f['alloc'] === 'invoice' ? ' selected' : '' ?>>على فاتورة محددة</option>
        <option value="none"<?= $f['alloc'] === 'none' ? ' selected' : '' ?>>دفعة على الحساب (بدون فاتورة)</option></select></div>
    <div class="col-md-6" id="invBox" style="display:none"><label class="form-label">الفاتورة</label><select class="form-select" name="invoice_id" id="invSel"></select></div>
    <div class="col-12"><label class="form-label">ملاحظات / رقم مرجعي للتحويل</label><input class="form-control" name="notes" value="<?= e($f['notes']) ?>" maxlength="500"></div>
  </div>
  <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> حفظ السند</button><a class="btn btn-light" href="./">إلغاء</a></div>
</form></div>
<script>
(function(){
  var open = <?= json_encode($openMap, JSON_UNESCAPED_UNICODE) ?>, sel = document.getElementById('invSel'), ps = document.getElementById('partySel'), as = document.getElementById('allocSel'),
      want = <?= (int)$f['invoice_id'] ?>;
  function fill(){ var list = open[ps.value] || []; sel.innerHTML = '';
    list.forEach(function(o){ var op = document.createElement('option'); op.value = o.id; op.textContent = o.label; if (o.id === want) op.selected = true; sel.appendChild(op); });
    if (!list.length) { var op = document.createElement('option'); op.value=''; op.textContent='لا توجد فواتير مفتوحة'; sel.appendChild(op); }
    document.getElementById('balHint').textContent = list.length ? 'عدد الفواتير المفتوحة: ' + list.length : (ps.value ? 'لا توجد فواتير مفتوحة لهذا الحساب.' : ''); }
  function vis(){ document.getElementById('invBox').style.display = as.value === 'invoice' ? '' : 'none'; }
  ps.addEventListener('change', fill); as.addEventListener('change', vis); fill(); vis();
})();
</script>
<?php page_end();
