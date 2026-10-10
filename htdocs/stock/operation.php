<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('stock');

$ops = ['receipt' => 'إذن استلام (وارد بدون فاتورة)', 'issue' => 'إذن صرف (منصرف بدون فاتورة)', 'adj_in' => 'تسوية جرد — زيادة / مرتجع وارد', 'adj_out' => 'تسوية جرد — عجز / تلف / مرتجع صادر', 'transfer' => 'تحويل بين مخزنين'];
$f = ['operation' => $_GET['op'] ?? 'receipt', 'move_date' => today(), 'item_id' => (int)($_GET['item'] ?? 0), 'warehouse_id' => default_warehouse_id(), 'to_warehouse_id' => 0, 'qty' => '', 'reason' => '', 'doc_no' => '', 'notes' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $f = array_merge($f, $_POST);
    try {
        $d = $_POST; $d['qty'] = parse_num($_POST['qty'] ?? '') ?? 0.0;
        stock_operation($d);
        flash('success', 'تم تسجيل العملية المخزنية.');
        redirect('stock/movements.php');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
$items = db_all('SELECT id, name, unit FROM items WHERE is_active=1 ORDER BY name');
$whs = db_all('SELECT id, name FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, name');
page_start('عملية مخزنية', 'stock');
?>
<div class="cardx" style="max-width:820px"><div class="head"><i class="bi bi-arrow-left-right"></i> تسجيل عملية مخزنية</div>
<form method="post" class="body" id="opForm"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-8"><label class="form-label required">نوع العملية</label><select class="form-select" name="operation" id="opSel"><?php foreach ($ops as $k => $l): ?><option value="<?= $k ?>"<?= $f['operation'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label required">التاريخ</label><input class="form-control" type="date" name="move_date" value="<?= e($f['move_date']) ?>" required></div>
    <div class="col-md-6"><label class="form-label required">الصنف</label><select class="form-select" name="item_id" required><option value="">— اختر —</option>
        <?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>"<?= (int)$f['item_id'] === (int)$it['id'] ? ' selected' : '' ?>><?= e($it['name']) ?> (<?= e($it['unit']) ?>)</option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label required" id="whLabel">المخزن</label><select class="form-select" name="warehouse_id" required><?= select_options($whs, 'id', 'name', $f['warehouse_id'], false) ?></select></div>
    <div class="col-md-3" id="toWh" style="display:none"><label class="form-label required">إلى مخزن</label><select class="form-select" name="to_warehouse_id"><?= select_options($whs, 'id', 'name', $f['to_warehouse_id'], true) ?></select></div>
    <div class="col-md-4"><label class="form-label required">الكمية</label><input class="form-control num" name="qty" value="<?= e((string)$f['qty']) ?>" inputmode="decimal" required></div>
    <div class="col-md-4" id="reasonBox"><label class="form-label">السبب</label><input class="form-control" name="reason" value="<?= e((string)$f['reason']) ?>" list="reasons" maxlength="60" placeholder="جرد / تلف / مرتجع ..."><datalist id="reasons"><option>جرد</option><option>تلف</option><option>مرتجع</option><option>عينة</option></datalist><div class="form-text" id="reasonHelp"></div></div>
    <div class="col-md-4"><label class="form-label">رقم المستند</label><input class="form-control" name="doc_no" value="<?= e((string)$f['doc_no']) ?>" maxlength="60" placeholder="تلقائي لو فارغ"><div class="form-text">رقم إذن الاستلام/الصرف.</div></div>
    <div class="col-12"><label class="form-label">ملاحظات</label><input class="form-control" name="notes" value="<?= e((string)$f['notes']) ?>" maxlength="500"></div>
  </div>
  <div class="alert alert-info small mt-3 mb-0">الأرصدة لا تتعدّل عشوائياً — كل تغيير بيتسجل بحركة موثّقة. لو الاستلام أو الصرف مرتبط بفاتورة، سجّله من شاشة المشتريات / المبيعات بدل هنا.</div>
  <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> تسجيل</button><a class="btn btn-light" href="./">إلغاء</a></div>
</form></div>
<script>
(function(){var s=document.getElementById('opSel');function u(){var t=s.value==='transfer',a=s.value.indexOf('adj')===0;
document.getElementById('toWh').style.display=t?'':'none';
document.getElementById('whLabel').textContent=t?'من مخزن':'المخزن';
var r=document.querySelector('[name=reason]');r.required=a;
document.getElementById('reasonHelp').textContent=a?'مطلوب في التسوية.':'';}
s.addEventListener('change',u);u();})();
</script>
<?php page_end();
