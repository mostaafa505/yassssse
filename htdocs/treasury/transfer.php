<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('treasury');

$accounts = db_all('SELECT id, name, acc_type FROM treasury_accounts WHERE is_active=1 ORDER BY id');
$cash = array_values(array_filter($accounts, fn($a) => $a['acc_type'] === 'cash'));
$bank = array_values(array_filter($accounts, fn($a) => $a['acc_type'] !== 'cash'));
$f = ['operation' => 'deposit', 'txn_date' => today(), 'doc_no' => '', 'amount' => '', 'cash_account_id' => $cash[0]['id'] ?? 0, 'bank_account_id' => $bank[0]['id'] ?? 0, 'payment_method' => 'تحويل بنكي', 'notes' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $f = array_merge($f, $_POST);
    try {
        $d = $_POST; $d['amount'] = parse_num($_POST['amount'] ?? '') ?? 0.0;
        treasury_transfer($d);
        flash('success', 'تم تسجيل العملية.');
        redirect('treasury/');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
page_start('إيداع / سحب بنكي', 'treasury');
?>
<div class="cardx" style="max-width:760px"><div class="head"><i class="bi bi-bank"></i> إيداع في البنك أو سحب منه</div>
<form method="post" class="body"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required">العملية</label><select class="form-select" name="operation"><option value="deposit"<?= $f['operation'] === 'deposit' ? ' selected' : '' ?>>إيداع بنكي (من الخزينة إلى البنك)</option><option value="withdraw"<?= $f['operation'] === 'withdraw' ? ' selected' : '' ?>>سحب بنكي (من البنك إلى الخزينة)</option></select></div>
    <div class="col-md-3"><label class="form-label required">التاريخ</label><input class="form-control" type="date" name="txn_date" value="<?= e($f['txn_date']) ?>" required></div>
    <div class="col-md-3"><label class="form-label required">المبلغ</label><input class="form-control num" name="amount" value="<?= e((string)$f['amount']) ?>" inputmode="decimal" required></div>
    <div class="col-md-6"><label class="form-label required">الخزينة النقدية</label><select class="form-select" name="cash_account_id"><?= select_options($cash, 'id', 'name', $f['cash_account_id'], false) ?></select></div>
    <div class="col-md-6"><label class="form-label required">الحساب البنكي / المحفظة</label><select class="form-select" name="bank_account_id"><?= select_options($bank, 'id', 'name', $f['bank_account_id'], false) ?></select></div>
    <div class="col-md-4"><label class="form-label required">رقم الإيصال</label><input class="form-control" name="doc_no" value="<?= e($f['doc_no']) ?>" required maxlength="60"></div>
    <div class="col-md-4"><label class="form-label required">طريقة الدفع</label><select class="form-select" name="payment_method"><?= simple_options(PAYMENT_METHODS, $f['payment_method']) ?></select></div>
    <div class="col-md-4"><label class="form-label">ملاحظات</label><input class="form-control" name="notes" value="<?= e($f['notes']) ?>" maxlength="500"></div>
  </div>
  <?php if (!$cash || !$bank): ?><div class="alert alert-warning mt-3 mb-0">محتاج حساب خزينة نقدية وحساب بنكي على الأقل — أضفهم من <a href="accounts.php">إدارة الحسابات</a>.</div><?php endif; ?>
  <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> تسجيل</button><a class="btn btn-light" href="./">إلغاء</a></div>
</form></div>
<?php page_end();
