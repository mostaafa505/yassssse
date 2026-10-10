<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('items');

$id = (int)($_GET['id'] ?? 0);
$it = $id ? db_one('SELECT * FROM items WHERE id=?', [$id]) : null;
if ($id && !$it) { flash('error', 'الصنف غير موجود.'); redirect('items/'); }
$whs = db_all('SELECT id, name FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, name');
$f = $it ?: ['code' => '', 'name' => '', 'unit' => 'شيكارة', 'reorder_level' => '0', 'notes' => '', 'is_active' => 1];
$f['warehouse_id'] = default_warehouse_id();
$f['opening_qty'] = $id ? stock_balance_opening($id, (int)$f['warehouse_id']) : '0';
$f['opening_date'] = today();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $f = array_merge($f, $_POST);
    try {
        $newId = save_item($_POST + ['is_active' => 0], $id ?: null);
        flash('success', 'تم حفظ الصنف.');
        redirect('items/');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
}
function stock_balance_opening(int $itemId, int $wh): string
{
    $v = db_val("SELECT qty FROM stock_movements WHERE item_id=? AND warehouse_id=? AND move_type='opening'", [$itemId, $wh]);
    return $v === null ? '0' : qty($v);
}
page_start($id ? 'تعديل صنف' : 'صنف جديد', 'items');
?>
<div class="cardx" style="max-width:820px"><div class="head"><i class="bi bi-box-seam"></i> بيانات الصنف</div>
<form method="post" class="body"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">كود الصنف</label><input class="form-control" name="code" value="<?= e((string)$f['code']) ?>" maxlength="40" dir="ltr" placeholder="يُولَّد تلقائياً لو تركته فارغ"><div class="form-text">رقم داخلي مميز لتجنب الخلط بين أصناف متشابهة.</div></div>
    <div class="col-md-8"><label class="form-label required">اسم الصنف</label><input class="form-control" name="name" value="<?= e((string)$f['name']) ?>" required maxlength="190" placeholder="مثال: دقيق فاخر 50 كجم"></div>
    <div class="col-md-4"><label class="form-label required">الوحدة</label><select class="form-select" name="unit"><?= simple_options(UNITS, $f['unit']) ?></select><div class="form-text">لازم تفضل ثابتة للصنف.</div></div>
    <div class="col-md-4"><label class="form-label">حد إعادة الطلب</label><input class="form-control num" name="reorder_level" value="<?= e(qty($f['reorder_level'])) ?>" inputmode="decimal"><div class="form-text">لما الرصيد يوصل له أو أقل يظهر «اطلب بضاعة».</div></div>
    <div class="col-md-4"></div>
    <div class="col-12"><hr class="my-1"><div class="fw-bold text-navy mb-2">رصيد أول المدة</div></div>
    <div class="col-md-4"><label class="form-label">المخزن</label><select class="form-select" name="warehouse_id"><?= select_options($whs, 'id', 'name', $f['warehouse_id'], false) ?></select></div>
    <div class="col-md-4"><label class="form-label">الكمية الافتتاحية</label><input class="form-control num" name="opening_qty" value="<?= e((string)$f['opening_qty']) ?>" inputmode="decimal"></div>
    <div class="col-md-4"><label class="form-label">التاريخ</label><input class="form-control" type="date" name="opening_date" value="<?= e((string)$f['opening_date']) ?>"></div>
    <div class="col-12 form-text mt-0">الكمية الموجودة فعلاً قبل بداية استخدام النظام. بعد كده كل التغيير في الرصيد بيتم بمستندات (مشتريات / مبيعات / إذن استلام وصرف / تسوية).</div>
    <div class="col-12"><label class="form-label">ملاحظات</label><input class="form-control" name="notes" value="<?= e((string)$f['notes']) ?>" maxlength="500"></div>
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act"<?= !empty($f['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="act">الصنف نشط (يظهر في الفواتير)</label></div></div>
  </div>
  <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="./">إلغاء</a></div>
</form></div>
<?php page_end();
