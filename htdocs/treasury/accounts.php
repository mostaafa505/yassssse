<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_module('treasury');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    try {
        db_tx(function () {
            $id = (int)($_POST['id'] ?? 0);
            $name = post_str('name', 120);
            if ($name === '') { throw new AppError('اسم الحساب مطلوب.'); }
            if (db_val('SELECT id FROM treasury_accounts WHERE name=? AND id<>?', [$name, $id])) { throw new AppError('اسم الحساب مستخدم.'); }
            $type = $_POST['acc_type'] ?? 'cash';
            if (!isset(ACCOUNT_TYPES[$type])) { throw new AppError('نوع الحساب غير صحيح.'); }
            $opening = parse_num($_POST['opening_balance'] ?? '0') ?? 0.0;
            $active = empty($_POST['is_active']) ? 0 : 1;
            $data = ['name' => $name, 'acc_type' => $type, 'opening_balance' => round($opening, 2), 'notes' => post_str('notes', 300) ?: null, 'is_active' => $active];
            if ($id) {
                if (!$active && abs(treasury_balance($id)) > 0.004) { throw new AppError('لا يمكن إيقاف حساب رصيده غير صفر.'); }
                $old = db_one('SELECT opening_balance FROM treasury_accounts WHERE id=? FOR UPDATE', [$id]);
                db_update('treasury_accounts', $id, $data);
                if (setting('allow_negative_treasury', '0') !== '1' && treasury_balance($id) < -0.005) { throw new AppError('الرصيد الافتتاحي ده هيخلي رصيد الحساب سالباً.'); }
                if (abs((float)$old['opening_balance'] - $opening) > 0.004) { audit('update', 'treasury_accounts', $id, "رصيد افتتاحي {$old['opening_balance']} → $opening"); }
            } else { $id = db_insert('treasury_accounts', $data); audit('create', 'treasury_accounts', $id, $name); }
        });
        flash('success', 'تم حفظ الحساب.');
    } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
    redirect('treasury/accounts.php');
}
$rows = db_all('SELECT * FROM treasury_accounts ORDER BY id');
$edit = isset($_GET['id']) ? db_one('SELECT * FROM treasury_accounts WHERE id=?', [(int)$_GET['id']]) : null;
page_start('حسابات الخزينة', 'treasury');
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="cardx"><div class="head"><i class="bi bi-wallet2"></i> الحسابات <span class="small text-muted fw-normal">(الخزينة النقدية منفصلة عن البنك)</span></div>
  <div class="table-responsive"><table class="table align-middle"><thead><tr><th>الحساب</th><th>النوع</th><th class="num">الرصيد الافتتاحي</th><th class="num">الرصيد الحالي</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td class="fw-bold"><?= e($r['name']) ?><?= $r['is_active'] ? '' : ' ' . badge('موقوف') ?></td><td><?= e(ACCOUNT_TYPES[$r['acc_type']]) ?></td>
    <td class="num"><?= money($r['opening_balance']) ?></td><td class="num fw-bold"><?= money(treasury_balance((int)$r['id'])) ?></td>
    <td><a class="btn btn-sm btn-outline-secondary" href="?id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a> <a class="btn btn-sm btn-outline-brand" href="./?acc=<?= (int)$r['id'] ?>">الحركة</a></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><td colspan="3">إجمالي كل الحسابات</td><td class="num"><?= money(array_sum(array_map(fn($r) => treasury_balance((int)$r['id']), $rows))) ?></td><td></td></tr></tfoot></table></div></div></div>
  <div class="col-lg-5"><div class="cardx"><div class="head"><?= $edit ? 'تعديل حساب' : 'حساب جديد' ?></div>
  <form method="post" class="body row g-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-12"><label class="form-label required">اسم الحساب</label><input class="form-control" name="name" required maxlength="120" value="<?= e((string)($edit['name'] ?? '')) ?>" placeholder="مثال: بنك مصر - جاري"></div>
    <div class="col-md-6"><label class="form-label required">النوع</label><select class="form-select" name="acc_type"><?php foreach (ACCOUNT_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= ($edit['acc_type'] ?? 'cash') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">الرصيد الافتتاحي</label><input class="form-control num" name="opening_balance" value="<?= e((string)($edit['opening_balance'] ?? '0')) ?>" inputmode="decimal"><div class="form-text">النقدية/الرصيد الفعلي قبل بداية النظام (راجعه فعلياً).</div></div>
    <div class="col-12"><label class="form-label">ملاحظات</label><input class="form-control" name="notes" maxlength="300" value="<?= e((string)($edit['notes'] ?? '')) ?>"></div>
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="aa"<?= !$edit || $edit['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="aa">نشط</label></div></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-brand">حفظ</button><?php if ($edit): ?><a class="btn btn-light" href="accounts.php">جديد</a><?php endif; ?></div>
  </form></div></div>
</div>
<?php page_end();
