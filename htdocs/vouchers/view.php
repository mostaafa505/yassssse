<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('vouchers');

$id = (int)($_GET['id'] ?? 0);
$v = db_one('SELECT v.*, a.name AS acc_name, u.full_name AS creator FROM vouchers v JOIN treasury_accounts a ON a.id=v.account_id LEFT JOIN users u ON u.id=v.created_by WHERE v.id=?', [$id]);
if (!$v) { flash('error', 'السند غير موجود.'); redirect('vouchers/'); }
$isReceipt = $v['voucher_type'] === 'receipt';
$pc = party_cfg($isReceipt ? 'customer' : 'supplier');
$party = db_one("SELECT * FROM {$pc['table']} WHERE id=?", [$v[$pc['fk']]]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try { cancel_voucher($id); flash('success', 'تم إلغاء السند وعكس أثره على الحساب والخزينة.'); }
    catch (Throwable $ex) { flash('error', user_error_message($ex)); }
    redirect('vouchers/view.php?id=' . $id);
}
$ic = inv_cfg($isReceipt ? 'sale' : 'purchase');
$allocs = db_all("SELECT a.amount, i.id, i.doc_no FROM voucher_allocations a JOIN {$ic['table']} i ON i.id=a.invoice_id WHERE a.voucher_id=?", [$id]);
$allocated = array_sum(array_column($allocs, 'amount'));
page_start(($isReceipt ? 'سند قبض ' : 'سند صرف ') . $v['doc_no'], 'vouchers');
print_header(($isReceipt ? 'سند قبض رقم ' : 'سند صرف رقم ') . $v['doc_no']);
?>
<div class="d-flex gap-2 mb-3 no-print">
  <a class="btn btn-light" href="./"><i class="bi bi-arrow-right"></i> القائمة</a>
  <button class="btn btn-outline-brand" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
  <a class="btn btn-outline-secondary" href="<?= e(url($pc['dir'] . '/statement.php?id=' . (int)$party['id'])) ?>"><i class="bi bi-journal-text"></i> كشف الحساب</a>
  <?php if ($v['status'] === 'active'): ?><form method="post" class="ms-auto" data-confirm="إلغاء السند؟ هيتم عكس أثره على حساب <?= e($pc['label']) ?> والخزينة والفواتير."><?= csrf_field() ?><button class="btn btn-outline-danger"><i class="bi bi-x-octagon"></i> إلغاء السند</button></form><?php endif; ?>
</div>
<div class="cardx" style="max-width:760px"><div class="body">
  <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="text-muted small"><?= $isReceipt ? 'استلمنا من السيد / السادة' : 'صرفنا إلى السيد / السادة' ?></div><div class="fs-4 fw-bold"><?= e($party['name']) ?></div></div>
    <div class="text-end"><?= $v['status'] === 'active' ? badge('فعّال', 'success') : badge('ملغي', 'danger') ?><div class="fs-3 fw-bold num text-navy mt-1"><?= egp($v['amount']) ?> <small><?= CURRENCY ?></small></div></div></div>
  <div class="row g-3">
    <div class="col-6 col-md-3"><div class="text-muted small">رقم السند</div><b><?= e($v['doc_no']) ?></b></div>
    <div class="col-6 col-md-3"><div class="text-muted small">التاريخ</div><b><?= fdate($v['voucher_date']) ?></b></div>
    <div class="col-6 col-md-3"><div class="text-muted small"><?= $isReceipt ? 'الإيداع في' : 'الصرف من' ?></div><b><?= e($v['acc_name']) ?></b></div>
    <div class="col-6 col-md-3"><div class="text-muted small">طريقة الدفع</div><b><?= e($v['payment_method']) ?></b></div>
    <div class="col-12"><div class="text-muted small">التوزيع على الفواتير</div>
      <?php foreach ($allocs as $a): ?><div><a href="<?= e(url($ic['table'] . '/view.php?id=' . (int)$a['id'])) ?>"><?= e($a['doc_no']) ?></a> — <span class="num"><?= egp($a['amount']) ?></span></div><?php endforeach; ?>
      <?php if ((float)$v['amount'] - $allocated > 0.004): ?><div class="text-muted">دفعة على الحساب: <span class="num"><?= egp((float)$v['amount'] - $allocated) ?></span></div><?php endif; ?></div>
    <?php if ($v['notes']): ?><div class="col-12"><div class="text-muted small">ملاحظات</div><?= e($v['notes']) ?></div><?php endif; ?>
    <div class="col-12 small text-muted">سجّله: <?= e((string)$v['creator']) ?> — <?= e((string)$v['created_at']) ?></div>
  </div>
  <div class="row mt-5 print-only text-center"><div class="col-6">توقيع المستلم</div><div class="col-6">توقيع المحاسب</div></div>
</div></div>
<?php page_end();
