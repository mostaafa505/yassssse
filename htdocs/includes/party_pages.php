<?php
/** صفحات العملاء والموردين (قائمة، نموذج، كشف حساب) — مشتركة بنفس الكود لأن الهيكل واحد */
declare(strict_types=1);
require_once __DIR__ . '/layout.php';

function party_list_page(string $type): void
{
    $c = party_cfg($type);
    require_module($c['module']);
    $q = get_str('q'); $st = get_str('status'); $showInactive = !empty($_GET['inactive']);
    $where = '1=1'; $params = [];
    if ($q !== '') { $where .= ' AND (p.name LIKE ? OR p.phone LIKE ? OR p.contact_person LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
    if (!$showInactive) { $where .= ' AND p.is_active = 1'; }
    $rows = party_summaries($type, $where, $params);
    if (isset(PARTY_STATUS_LABELS[$st])) { $rows = array_values(array_filter($rows, fn($r) => $r['status_key'] === $st)); }
    $totalBal = array_sum(array_column($rows, 'balance'));
    $totalOver = array_sum(array_column($rows, 'overdue'));

    if (isset($_GET['export'])) {
        csv_download('كشف-' . $c['plural'], ['الاسم', 'المسؤول', 'الهاتف', 'إجمالي الفواتير', $type === 'customer' ? 'إجمالي المحصَّل' : 'إجمالي المسدَّد', $type === 'customer' ? 'المتبقي عليه' : 'المتبقي له علينا', 'أقرب استحقاق', 'المتأخر', 'آخر حركة', 'حالة الحساب'],
            array_map(fn($r) => [$r['name'], $r['contact_person'], $r['phone'], $r['total_invoiced'], $r['total_paid'], $r['balance'], $r['next_due'], $r['overdue'], $r['last_date'], PARTY_STATUS_LABELS[$r['status_key']]], $rows));
    }
    page_start($c['plural'], $c['module']);
    $isCust = $type === 'customer';
    ?>
    <div class="filters no-print">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-4"><label class="form-label">بحث</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="الاسم / الهاتف / المسؤول"></div>
        <div class="col-md-3"><label class="form-label">حالة الحساب</label>
            <select class="form-select" name="status"><option value="">— الكل —</option>
            <?php foreach (PARTY_STATUS_LABELS as $k => $l): ?><option value="<?= $k ?>"<?= $st === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="inactive" value="1" id="inact"<?= $showInactive ? ' checked' : '' ?>><label class="form-check-label" for="inact">إظهار الموقوفين</label></div></div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-brand"><i class="bi bi-search"></i> بحث</button>
            <a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a class="btn btn-gold ms-auto" href="form.php"><i class="bi bi-plus-lg"></i> <?= $isCust ? 'عميل جديد' : 'مورد جديد' ?></a>
        </div>
      </form>
    </div>
    <div class="cardx">
      <div class="head"><i class="bi bi-people"></i> <?= e($c['plural']) ?> <span class="text-muted small fw-normal">(<?= count($rows) ?>)</span>
        <span class="ms-auto d-flex gap-2 flex-wrap align-items-center">
          <span class="badge-soft danger"><?= $isCust ? 'إجمالي ما عليهم' : 'إجمالي ما لهم علينا' ?>: <b class="num"><?= egp(array_sum(array_map(fn($r) => max(0, (float)$r['balance']), $rows))) ?></b></span>
        </span></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>الاسم</th><th class="num">إجمالي الفواتير</th><th class="num"><?= $isCust ? 'إجمالي المحصَّل' : 'إجمالي المسدَّد' ?></th><th><?= $isCust ? 'المتبقي عليه' : 'المتبقي له علينا' ?></th><th>أقرب استحقاق</th><th class="num">المتأخر</th><th>الحالة</th><th class="no-print"></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): [$balTxt, $balCls] = balance_phrase($type, (float)$r['balance']); ?>
            <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
              <td><a class="fw-bold text-decoration-none" href="statement.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><?= $r['is_active'] ? '' : ' ' . badge('موقوف') ?>
                  <div class="small text-muted"><?= e($r['contact_person'] ?? '') ?><?= $r['phone'] ? ' · <span dir="ltr">' . e($r['phone']) . '</span>' : '' ?></div></td>
              <td class="num"><?= egp($r['total_invoiced']) ?></td>
              <td class="num"><?= egp($r['total_paid']) ?></td>
              <td><span class="bal <?= $balCls ?>"><small><?= $balCls === 'owes' ? ($isCust ? 'عليه' : 'له علينا') : ($balCls === 'credit' ? ($isCust ? 'له رصيد' : 'لنا عنده') : 'الرصيد') ?></small><b><?= $balCls === 'zero' ? '0.00' : egp(abs((float)$r['balance'])) ?></b></span></td>
              <td><?php if ($r['next_due']): $late = $r['next_due'] < today(); ?><span class="<?= $late ? 'amount-neg' : '' ?>"><?= fdate($r['next_due']) ?></span><div class="small text-muted num">متبقي فواتير <?= egp($r['open_amt']) ?></div><?php else: ?>—<?php endif; ?></td>
              <td class="num"><?= (float)$r['overdue'] > 0.005 ? '<span class="amount-neg">' . egp($r['overdue']) . '</span>' : '—' ?></td>
              <td><?= badge(PARTY_STATUS_LABELS[$r['status_key']], PARTY_STATUS_CLASS[$r['status_key']]) ?></td>
              <td class="no-print text-nowrap">
                <a class="btn btn-sm btn-outline-brand" href="statement.php?id=<?= (int)$r['id'] ?>"><i class="bi bi-journal-text"></i> كشف حساب</a>
                <a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int)$r['id'] ?>" title="تعديل"><i class="bi bi-pencil"></i></a>
              </td>
            </tr>
          <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">لا توجد حسابات</td></tr><?php endif; ?>
          </tbody>
          <?php if ($rows): ?><tfoot><tr><td>الإجمالي</td><td class="num"><?= egp(array_sum(array_column($rows, 'total_invoiced'))) ?></td><td class="num"><?= egp(array_sum(array_column($rows, 'total_paid'))) ?></td><td class="num"><?= egp($totalBal) ?></td><td></td><td class="num"><?= egp($totalOver) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
        </table>
      </div>
    </div>
    <?php
    page_end();
}

function party_form_page(string $type): void
{
    $c = party_cfg($type);
    require_module($c['module']);
    $id = (int)($_GET['id'] ?? 0);
    $p = $id ? db_one("SELECT * FROM {$c['table']} WHERE id=?", [$id]) : null;
    if ($id && !$p) { flash('error', 'الحساب غير موجود.'); redirect($c['dir'] . '/'); }
    $f = $p ?: ['name' => '', 'contact_person' => '', 'phone' => '', 'address' => '', 'tax_no' => '', 'credit_days' => 0, 'notes' => '', 'is_active' => 1];
    $f['opening_balance'] = $id ? party_opening($type, $id) : 0;
    $f['opening_date'] = $id ? (string)db_val("SELECT entry_date FROM {$c['ledger']} WHERE {$c['fk']}=? AND doc_type='opening'", [$id]) : today();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_guard();
        $f = array_merge($f, $_POST);
        try {
            $newId = save_party($type, $_POST + ['is_active' => 0], $id ?: null);
            flash('success', 'تم حفظ بيانات ' . $c['label'] . '.');
            redirect($c['dir'] . '/statement.php?id=' . $newId);
        } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
    }
    page_start($id ? 'تعديل ' . $c['label'] : $c['label'] . ' جديد', $c['module']);
    ?>
    <div class="cardx" style="max-width:860px">
      <div class="head"><i class="bi bi-person-vcard"></i> بيانات <?= e($c['label']) ?></div>
      <form method="post" class="body">
        <?= csrf_field() ?>
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label required">الاسم</label><input class="form-control" name="name" value="<?= e((string)$f['name']) ?>" required maxlength="190"></div>
          <div class="col-md-4"><label class="form-label">الشخص المسؤول</label><input class="form-control" name="contact_person" value="<?= e((string)$f['contact_person']) ?>"></div>
          <div class="col-md-4"><label class="form-label">الهاتف</label><input class="form-control" name="phone" value="<?= e((string)$f['phone']) ?>" dir="ltr"></div>
          <div class="col-md-5"><label class="form-label">العنوان</label><input class="form-control" name="address" value="<?= e((string)$f['address']) ?>"></div>
          <div class="col-md-3"><label class="form-label">الرقم الضريبي</label><input class="form-control" name="tax_no" value="<?= e((string)$f['tax_no']) ?>" dir="ltr"></div>
          <div class="col-md-4"><label class="form-label">مدة <?= $type === 'customer' ? 'التحصيل' : 'السداد' ?> الافتراضية (يوم)</label>
              <input class="form-control" type="number" min="0" max="3650" name="credit_days" value="<?= (int)$f['credit_days'] ?>">
              <div class="form-text">بتحدد موعد الاستحقاق تلقائياً في الفواتير الآجلة.</div></div>
          <div class="col-md-4"><label class="form-label">الرصيد الافتتاحي (<?= CURRENCY ?>)</label>
              <input class="form-control num" name="opening_balance" value="<?= e((string)$f['opening_balance']) ?>" inputmode="decimal">
              <div class="form-text">لو في مبلغ مستحق قبل بداية استخدام النظام (<?= $type === 'customer' ? 'على العميل' : 'للمورد' ?>). صفر لو لا يوجد.</div></div>
          <div class="col-md-4"><label class="form-label">تاريخ الرصيد الافتتاحي</label><input class="form-control" type="date" name="opening_date" value="<?= e((string)$f['opening_date']) ?>"></div>
          <div class="col-12"><label class="form-label">ملاحظات (اتفاق السداد، مسؤول الحساب...)</label><input class="form-control" name="notes" value="<?= e((string)$f['notes']) ?>" maxlength="500"></div>
          <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act"<?= !empty($f['is_active']) ? ' checked' : '' ?>><label class="form-check-label" for="act">الحساب نشط (يظهر في القوائم)</label></div></div>
        </div>
        <div class="mt-4 d-flex gap-2"><button class="btn btn-brand px-4"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="<?= $id ? 'statement.php?id=' . $id : './' ?>">إلغاء</a></div>
      </form>
    </div>
    <?php
    page_end();
}

function party_statement_page(string $type): void
{
    $c = party_cfg($type);
    require_module($c['module']);
    $id = (int)($_GET['id'] ?? 0);
    $p = db_one("SELECT * FROM {$c['table']} WHERE id=?", [$id]);
    if (!$p) { flash('error', 'الحساب غير موجود.'); redirect($c['dir'] . '/'); }
    $isCust = $type === 'customer';
    $kind = $isCust ? 'sale' : 'purchase';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_guard();
        try {
            $act = $_POST['action'] ?? '';
            if ($act === 'add_note') { ledger_note($type, $id, $_POST); flash('success', 'تم تسجيل الإشعار.'); }
            elseif ($act === 'del_note') { ledger_note_delete($type, (int)($_POST['ledger_id'] ?? 0)); flash('success', 'تم حذف الإشعار.'); }
        } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
        redirect($c['dir'] . '/statement.php?id=' . $id . '&' . http_build_query(array_intersect_key($_GET, ['from' => 1, 'to' => 1])));
    }

    $from = parse_date($_GET['from'] ?? '') ?: null; $to = parse_date($_GET['to'] ?? '') ?: null;
    $stm = party_statement($type, $id, $from, $to);
    $sum = party_summaries($type, 'p.id = ?', [$id])[0];
    $open = open_invoices($kind, $id);

    if (isset($_GET['export'])) {
        csv_download('كشف-حساب-' . $p['name'], ['التاريخ', 'رقم المستند', 'البيان', 'الرصيد السابق', 'الفواتير', $c['pay_label'], 'الرصيد المتبقي', 'موعد الاستحقاق'],
            array_map(fn($r) => [$r['entry_date'], $r['doc_no'], $r['notes'], $r['prev_balance'], $r['invoice_amount'], $r['payment_amount'], $r['balance'], $r['due_date']], $stm['rows']));
    }
    $docLabels = ['opening' => 'رصيد افتتاحي', 'invoice' => $isCust ? 'فاتورة مبيعات' : 'فاتورة مشتريات', 'payment' => $isCust ? 'تحصيل' : 'سداد', 'debit_note' => 'إشعار إضافة', 'credit_note' => 'إشعار خصم'];

    page_start('كشف حساب: ' . $p['name'], $c['module']);
    print_header('كشف حساب ' . $c['label'] . ': ' . $p['name'] . ($from || $to ? ' — الفترة: ' . ($from ? fdate($from) : '…') . ' إلى ' . ($to ? fdate($to) : '…') : ''));
    ?>
    <?php [$balTxt, $balCls] = balance_phrase($type, (float)$sum['balance']); ?>
    <div class="cardx mb-3"><div class="body">
      <div class="party-head">
        <div class="avatar lg"><?= e(mb_substr($p['name'], 0, 1)) ?></div>
        <div class="info">
          <h2><?= e($p['name']) ?> <?= badge(PARTY_STATUS_LABELS[$sum['status_key']], PARTY_STATUS_CLASS[$sum['status_key']]) ?></h2>
          <div class="meta">
            <?php if ($p['contact_person']): ?><span><i class="bi bi-person"></i><?= e($p['contact_person']) ?></span><?php endif; ?>
            <?php if ($p['phone']): ?><span dir="ltr"><i class="bi bi-telephone"></i><?= e($p['phone']) ?></span><?php endif; ?>
            <?php if ($p['address']): ?><span><i class="bi bi-geo-alt"></i><?= e($p['address']) ?></span><?php endif; ?>
            <span><i class="bi bi-hourglass-split"></i>مدة <?= $isCust ? 'التحصيل' : 'السداد' ?> <?= (int)$p['credit_days'] ?> يوم</span>
          </div>
        </div>
        <div class="bal bal-hero <?= $balCls ?>"><small><?= $balCls === 'owes' ? ($isCust ? 'المتبقي عليه' : 'المتبقي له علينا') : ($balCls === 'credit' ? ($isCust ? 'له رصيد دائن' : 'لنا عنده') : 'الرصيد') ?> (<?= CURRENCY ?>)</small><b><?= $balCls === 'zero' ? '0.00' : egp(abs((float)$sum['balance'])) ?></b></div>
      </div>
    </div></div>
    <div class="row g-3 mb-3">
      <div class="col-md-3 col-6"><div class="kpi"><div class="label">إجمالي الفواتير</div><div class="value num"><?= egp($sum['total_invoiced']) ?></div><div class="sub">+ رصيد افتتاحي <?= egp($sum['opening_bal']) ?></div><i class="bi bi-receipt bg"></i></div></div>
      <div class="col-md-3 col-6"><div class="kpi green"><div class="label"><?= $isCust ? 'إجمالي ما دفعه' : 'إجمالي ما سُدِّد له' ?></div><div class="value num"><?= egp($sum['total_paid']) ?></div><div class="sub"><?= $isCust ? 'تحصيلات وخصومات' : 'مدفوعات وخصومات' ?></div><i class="bi bi-cash-coin bg"></i></div></div>
      <div class="col-md-3 col-6"><div class="kpi gold"><div class="label">أقرب موعد استحقاق</div><div class="value fs-4"><?= $sum['next_due'] ? fdate($sum['next_due']) : '—' ?></div><div class="sub"><?= $sum['next_due'] ? 'متبقي فواتير ' . egp($sum['open_amt']) : 'لا توجد فواتير مفتوحة' ?></div><i class="bi bi-calendar-event bg"></i></div></div>
      <div class="col-md-3 col-6"><div class="kpi red"><div class="label">المتأخر عن الاستحقاق</div><div class="value num"><?= egp($sum['overdue']) ?></div><div class="sub"><?= $sum['max_days'] ? 'أقدم تأخير ' . (int)$sum['max_days'] . ' يوم' : 'لا يوجد تأخير' ?></div><i class="bi bi-exclamation-octagon bg"></i></div></div>
    </div>

    <div class="filters no-print">
      <form class="row g-2 align-items-end" method="get">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="col-md-3"><label class="form-label">من تاريخ</label><input class="form-control" type="date" name="from" value="<?= e((string)$from) ?>"></div>
        <div class="col-md-3"><label class="form-label">إلى تاريخ</label><input class="form-control" type="date" name="to" value="<?= e((string)$to) ?>"></div>
        <div class="col-md-6 d-flex gap-2 flex-wrap">
          <button class="btn btn-brand"><i class="bi bi-funnel"></i> عرض</button>
          <a class="btn btn-outline-brand" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
          <button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
          <a class="btn btn-outline-secondary" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> تعديل البيانات</a>
        </div>
      </form>
      <div class="mt-2 d-flex gap-2 flex-wrap">
        <?php if (can('vouchers')): ?><a class="btn btn-gold btn-sm" href="<?= e(url('vouchers/form.php?type=' . ($isCust ? 'receipt' : 'payment') . '&party=' . $id)) ?>"><i class="bi bi-cash-coin"></i> <?= $isCust ? 'سند قبض' : 'سند صرف' ?></a><?php endif; ?>
        <?php if (can($isCust ? 'sales' : 'purchases')): ?><a class="btn btn-brand btn-sm" href="<?= e(url(($isCust ? 'sales' : 'purchases') . '/form.php?party=' . $id)) ?>"><i class="bi bi-plus-lg"></i> <?= $isCust ? 'فاتورة مبيعات' : 'فاتورة مشتريات' ?></a><?php endif; ?>
        <button class="btn btn-outline-brand btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#noteModal"><i class="bi bi-journal-plus"></i> إشعار إضافة / خصم</button>
      </div>
    </div>

    <div class="cardx mb-3">
      <div class="head"><i class="bi bi-journal-text"></i> كشف الحساب</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>التاريخ</th><th>رقم المستند</th><th>البيان</th><th class="num">الرصيد السابق</th><th class="num"><?= $isCust ? 'الفاتورة / المبيعات' : 'الفاتورة / المشتريات' ?></th><th class="num"><?= e($c['pay_label']) ?></th><th class="num">الرصيد المتبقي</th><th>الاستحقاق</th><th class="no-print"></th></tr></thead>
          <tbody>
          <?php if ($from): ?><tr class="table-light"><td colspan="3">رصيد مرحَّل قبل <?= fdate($from) ?></td><td></td><td></td><td></td><td class="num fw-bold"><?= egp($stm['opening']) ?></td><td colspan="2"></td></tr><?php endif; ?>
          <?php foreach ($stm['rows'] as $r): ?>
            <tr>
              <td><?= fdate($r['entry_date']) ?></td>
              <td class="doc-no"><?php
                  if ($r['ref_type'] === $kind && $r['ref_id']) { echo '<a href="' . e(url($c['inv'] . '/view.php?id=' . (int)$r['ref_id'])) . '">' . e((string)$r['doc_no']) . '</a>'; }
                  else { echo e((string)$r['doc_no']); } ?></td>
              <td><span class="badge-soft info"><?= e($docLabels[$r['doc_type']] ?? '') ?></span> <span class="small text-muted"><?= e((string)$r['notes']) ?></span></td>
              <td class="num"><?= egp($r['prev_balance']) ?></td>
              <td class="num"><?= (float)$r['invoice_amount'] ? egp($r['invoice_amount']) : '' ?></td>
              <td class="num"><?= (float)$r['payment_amount'] ? egp($r['payment_amount']) : '' ?></td>
              <td class="num fw-bold"><?= egp($r['balance']) ?></td>
              <td><?= fdate($r['due_date']) ?></td>
              <td class="no-print"><?php if ($r['ref_type'] === 'note'): ?>
                <form method="post" data-confirm="حذف الإشعار؟" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="del_note"><input type="hidden" name="ledger_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; if (!$stm['rows']): ?><tr><td colspan="9" class="text-center text-muted py-4">لا توجد حركات في الفترة</td></tr><?php endif; ?>
          </tbody>
          <tfoot><tr><td colspan="4">الإجمالي</td>
            <td class="num"><?= egp(array_sum(array_column($stm['rows'], 'invoice_amount'))) ?></td>
            <td class="num"><?= egp(array_sum(array_column($stm['rows'], 'payment_amount'))) ?></td>
            <td class="num"><?= egp($stm['closing']) ?></td><td colspan="2"></td></tr></tfoot>
        </table>
      </div>
    </div>

    <?php if ($open): ?>
    <div class="cardx">
      <div class="head"><i class="bi bi-exclamation-circle"></i> فواتير مفتوحة (غير مسددة بالكامل)</div>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>الاستحقاق</th><th class="num">الإجمالي</th><th class="num">المسدد</th><th class="num">المتبقي</th><th>الحالة</th></tr></thead>
        <tbody><?php foreach ($open as $o): $late = $o['due_date'] && $o['due_date'] < today(); ?>
          <tr><td><a href="<?= e(url($c['inv'] . '/view.php?id=' . (int)$o['id'])) ?>"><?= e($o['doc_no']) ?></a></td><td><?= fdate($o['invoice_date']) ?></td><td><?= fdate($o['due_date']) ?></td>
              <td class="num"><?= egp($o['total']) ?></td><td class="num"><?= egp($o['paid_amount']) ?></td><td class="num fw-bold"><?= egp($o['remaining']) ?></td>
              <td><?= $late ? badge('متأخر ' . (int)((strtotime(today()) - strtotime($o['due_date'])) / 86400) . ' يوم', 'danger') : badge('في الميعاد', 'success') ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="modal fade no-print" id="noteModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_note">
      <div class="modal-header"><h5 class="modal-title">إشعار إضافة / خصم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-3">
        <div class="col-12"><label class="form-label required">النوع</label>
          <select class="form-select" name="kind" required><option value="discount">إشعار خصم (ينقص الرصيد — مثل مرتجع أو تسوية)</option><option value="add">إشعار إضافة (يزيد الرصيد)</option></select></div>
        <div class="col-6"><label class="form-label required">رقم الإشعار</label><input class="form-control" name="doc_no" required></div>
        <div class="col-6"><label class="form-label required">التاريخ</label><input class="form-control" type="date" name="entry_date" value="<?= e(today()) ?>" required></div>
        <div class="col-12"><label class="form-label required">المبلغ (<?= CURRENCY ?>)</label><input class="form-control num" name="amount" inputmode="decimal" required></div>
        <div class="col-12"><label class="form-label">البيان / السبب</label><input class="form-control" name="notes" maxlength="500"></div>
        <div class="form-text">إشعار الخصم/الإضافة بيعدّل رصيد الحساب فقط، ولا يؤثر على المخزون أو الخزينة. لو في بضاعة مرتجعة سجّلها في المخزن بعملية «تسوية مخزون - مرتجع».</div>
      </div>
      <div class="modal-footer"><button class="btn btn-brand">حفظ الإشعار</button></div>
    </form></div></div>
    <?php
    page_end();
}
