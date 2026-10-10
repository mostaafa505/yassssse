<?php
/** صفحات فواتير المشتريات والمبيعات (قائمة، نموذج، عرض/طباعة) — مشتركة لأن الهيكل واحد */
declare(strict_types=1);
require_once __DIR__ . '/layout.php';

function invoice_module(string $kind): string { return $kind === 'purchase' ? 'purchases' : 'sales'; }

function invoice_status_key(array $r): string
{
    if ($r['status'] === 'cancelled') { return 'cancelled'; }
    return payment_status((float)$r['total'], (float)$r['paid_amount']);
}
function invoice_status_badge(array $r): string
{
    $k = invoice_status_key($r);
    if ($k === 'cancelled') { return badge('ملغاة', 'secondary'); }
    $late = $k !== 'paid' && $r['due_date'] && $r['due_date'] < today();
    return badge(PAYMENT_STATUS_LABELS[$k], PAYMENT_STATUS_CLASS[$k]) . ($late ? ' ' . badge('متأخرة', 'danger') : '');
}

function invoice_list_page(string $kind): void
{
    $c = inv_cfg($kind); $pc = party_cfg($c['party']);
    $module = invoice_module($kind);
    require_module($module);
    $isPurchase = $kind === 'purchase';

    $from = parse_date($_GET['from'] ?? '') ?: ''; $to = parse_date($_GET['to'] ?? '') ?: '';
    $partyId = (int)($_GET['party'] ?? 0); $status = get_str('status'); $q = get_str('q');
    $where = '1=1'; $params = [];
    if ($from) { $where .= ' AND i.invoice_date >= ?'; $params[] = $from; }
    if ($to)   { $where .= ' AND i.invoice_date <= ?'; $params[] = $to; }
    if ($partyId) { $where .= " AND i.{$c['party_col']} = ?"; $params[] = $partyId; }
    if ($q !== '') { $where .= ' AND (i.doc_no LIKE ? OR p.name LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
    if ($status === 'cancelled') { $where .= " AND i.status='cancelled'"; }
    elseif ($status === 'paid') { $where .= " AND i.status='active' AND i.paid_amount + 0.005 >= i.total"; }
    elseif ($status === 'credit') { $where .= " AND i.status='active' AND i.paid_amount <= 0.005 AND i.total > 0.005"; }
    elseif ($status === 'partial') { $where .= " AND i.status='active' AND i.paid_amount > 0.005 AND i.paid_amount + 0.005 < i.total"; }
    elseif ($status === 'late') { $where .= " AND i.status='active' AND i.total - i.paid_amount > 0.005 AND i.due_date < '" . today() . "'"; }
    $base = "FROM {$c['table']} i JOIN {$pc['table']} p ON p.id = i.{$c['party_col']} WHERE $where";

    if (isset($_GET['export'])) {
        // نفس أعمدة شيت المشتريات في الإكسل: سطر لكل صنف (بيانات الفاتورة الإجمالية على أول سطر)
        $heads = ['التاريخ', 'رقم الفاتورة', $pc['label'], 'الصنف', 'الكمية', 'الوحدة', 'سعر الوحدة', 'الخصم', 'مصاريف النقل', 'ضريبة القيمة المضافة', 'رسوم أخرى', 'إجمالي الفاتورة', 'المدفوع', 'حالة السداد', 'ملاحظات'];
        $out = [];
        $invs = db_all("SELECT i.*, p.name AS party_name $base ORDER BY i.invoice_date, i.id", $params);
        foreach ($invs as $r) {
            $lines = db_all("SELECT l.*, it.name, it.unit FROM {$c['items']} l JOIN items it ON it.id=l.item_id WHERE l.{$c['fk']}=? ORDER BY l.id", [$r['id']]);
            foreach ($lines as $n => $l) {
                $first = $n === 0;
                $sk = invoice_status_key($r);
                $out[] = [$r['invoice_date'], $r['doc_no'], $r['party_name'], $l['name'], $l['qty'], $l['unit'], $l['unit_price'],
                    $first ? $r['discount'] : '', $first ? $r['transport'] : '', $first ? $r['vat'] : '', $first ? $r['other_fees'] : '',
                    $first ? $r['total'] : '', $first ? $r['paid_amount'] : '', $sk === 'cancelled' ? 'ملغاة' : PAYMENT_STATUS_LABELS[$sk], $first ? $r['notes'] : ''];
            }
        }
        csv_download($isPurchase ? 'شيت-المشتريات' : 'شيت-المبيعات', $heads, $out);
    }

    $perPage = 25; $page = page_num();
    $total = (int)db_val("SELECT COUNT(*) $base", $params);
    $agg = db_one("SELECT COALESCE(SUM(CASE WHEN i.status='active' THEN i.total END),0) AS t, COALESCE(SUM(CASE WHEN i.status='active' THEN i.paid_amount END),0) AS p $base", $params);
    $rows = db_all("SELECT i.*, p.name AS party_name,
                    (SELECT GROUP_CONCAT(it.name SEPARATOR '، ') FROM {$c['items']} l JOIN items it ON it.id = l.item_id WHERE l.{$c['fk']} = i.id) AS item_names
                    $base ORDER BY i.invoice_date DESC, i.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
    $parties = db_all("SELECT id, name FROM {$pc['table']} ORDER BY name");

    page_start($isPurchase ? 'المشتريات' : 'المبيعات', $module);
    ?>
    <div class="filters no-print">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-2"><label class="form-label">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="col-md-2"><label class="form-label">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="col-md-2"><label class="form-label"><?= e($pc['label']) ?></label><select class="form-select" name="party"><?= select_options($parties, 'id', 'name', $partyId, true, '— الكل —') ?></select></div>
        <div class="col-md-2"><label class="form-label">حالة السداد</label><select class="form-select" name="status"><option value="">— الكل —</option>
            <?php foreach (['paid' => 'مدفوع', 'partial' => 'جزئي', 'credit' => 'آجل', 'late' => 'متأخرة عن الاستحقاق', 'cancelled' => 'ملغاة'] as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">بحث برقم الفاتورة</label><input class="form-control" name="q" value="<?= e($q) ?>"></div>
        <div class="col-md-2 d-flex gap-1"><button class="btn btn-brand flex-fill"><i class="bi bi-search"></i></button>
            <a class="btn btn-outline-brand" title="تصدير Excel" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="bi bi-file-earmark-excel"></i></a>
            <a class="btn btn-gold" href="form.php"><i class="bi bi-plus-lg"></i></a></div>
      </form>
    </div>
    <div class="cardx">
      <div class="head"><i class="bi <?= $isPurchase ? 'bi-cart-plus' : 'bi-receipt' ?>"></i> <?= $isPurchase ? 'فواتير المشتريات' : 'فواتير المبيعات' ?> <span class="text-muted small fw-normal">(<?= $total ?>)</span>
        <a class="btn btn-gold btn-sm ms-auto" href="form.php"><i class="bi bi-plus-lg"></i> <?= $isPurchase ? 'فاتورة مشتريات جديدة' : 'فاتورة مبيعات جديدة' ?></a></div>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>التاريخ</th><th>رقم الفاتورة</th><th><?= e($pc['label']) ?></th><th>الأصناف</th><th class="num">الإجمالي</th><th class="num">المدفوع</th><th class="num">المتبقي</th><th>الحالة</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $cancelled = $r['status'] === 'cancelled'; ?>
          <tr class="<?= $cancelled ? 'text-muted' : '' ?>">
            <td><?= fdate($r['invoice_date']) ?></td>
            <td class="doc-no"><a class="fw-bold" href="view.php?id=<?= (int)$r['id'] ?>"><?= e($r['doc_no']) ?></a></td>
            <td><?= e($r['party_name']) ?></td>
            <td class="small text-muted" style="max-width:260px"><?= e(mb_strimwidth((string)$r['item_names'], 0, 70, '…')) ?></td>
            <td class="num <?= $cancelled ? 'text-decoration-line-through' : '' ?>"><?= money($r['total']) ?></td>
            <td class="num"><?= money($r['paid_amount']) ?></td>
            <td class="num fw-bold"><?= $cancelled ? '—' : money((float)$r['total'] - (float)$r['paid_amount']) ?></td>
            <td><?= invoice_status_badge($r) ?></td>
          </tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">لا توجد فواتير</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?><tfoot><tr><td colspan="4">إجمالي الفواتير الفعّالة (كل النتائج)</td><td class="num"><?= money($agg['t']) ?></td><td class="num"><?= money($agg['p']) ?></td><td class="num"><?= money((float)$agg['t'] - (float)$agg['p']) ?></td><td></td></tr></tfoot><?php endif; ?>
      </table></div>
    </div>
    <?= pager($total, $perPage, $page) ?>
    <?php
    page_end();
}

function invoice_form_page(string $kind): void
{
    $c = inv_cfg($kind); $pc = party_cfg($c['party']);
    $module = invoice_module($kind);
    require_module($module);
    $isPurchase = $kind === 'purchase';

    $f = ['invoice_date' => today(), 'doc_no' => '', 'party_id' => (int)($_GET['party'] ?? 0), 'warehouse_id' => default_warehouse_id(),
          'discount' => '', 'transport' => '', 'vat' => '', 'other_fees' => '', 'paid_amount' => '', 'account_id' => 0,
          'payment_method' => 'نقدي', 'due_date' => '', 'note_no' => '', 'notes' => ''];
    $postedLines = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_guard();
        $f = array_merge($f, array_intersect_key($_POST, $f));
        $f['party_id'] = (int)($_POST['party_id'] ?? 0);
        foreach ((array)($_POST['item_id'] ?? []) as $i => $iid) {
            $postedLines[] = ['item_id' => (int)$iid, 'qty' => $_POST['qty'][$i] ?? '', 'unit_price' => $_POST['unit_price'][$i] ?? ''];
        }
        try {
            $lines = normalize_lines($_POST);
            $data = $_POST + [];
            foreach (['discount', 'transport', 'vat', 'other_fees', 'paid_amount'] as $k) {
                $n = parse_num($_POST[$k] ?? ''); if (($_POST[$k] ?? '') !== '' && $n === null) { throw new AppError('قيمة غير صحيحة في حقل مالي.'); }
                $data[$k] = $n ?? 0.0;
            }
            $data['party_id'] = (int)($_POST['party_id'] ?? 0);
            $id = post_invoice($kind, $data, $lines);
            flash('success', 'تم تسجيل ' . $c['title'] . ' بنجاح.');
            redirect($c['table'] . '/view.php?id=' . $id);
        } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
    }

    $parties = db_all("SELECT id, name, credit_days FROM {$pc['table']} WHERE is_active = 1 OR id = ? ORDER BY name", [(int)$f['party_id']]);
    $whs = db_all('SELECT id, name FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, name');
    $accounts = db_all('SELECT id, name FROM treasury_accounts WHERE is_active=1 ORDER BY id');
    $items = db_all('SELECT id, code, name, unit FROM items WHERE is_active=1 ORDER BY name');
    if (!$items) { flash('warning', 'لازم تضيف أصناف أولاً قبل تسجيل الفواتير.'); redirect('items/form.php'); }
    if (!$parties) { flash('warning', 'لازم تضيف ' . $pc['label'] . ' أولاً.'); redirect($pc['dir'] . '/form.php'); }
    if (!$f['account_id']) { $f['account_id'] = $accounts[0]['id'] ?? 0; }
    $itemOpts = '<option value="">— اختر الصنف —</option>';
    foreach ($items as $it) { $itemOpts .= '<option value="' . (int)$it['id'] . '" data-unit="' . e($it['unit']) . '">' . e($it['name']) . ' (' . e($it['code']) . ')</option>'; }
    if (!$postedLines) { $postedLines = [['item_id' => 0, 'qty' => '', 'unit_price' => '']]; }

    page_start($isPurchase ? 'فاتورة مشتريات جديدة' : 'فاتورة مبيعات جديدة', $module);
    ?>
    <form method="post" id="invoiceForm" autocomplete="off">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-lg-12"><div class="cardx"><div class="head"><i class="bi bi-file-earmark-text"></i> بيانات الفاتورة</div><div class="body"><div class="row g-3">
        <div class="col-md-3"><label class="form-label required">التاريخ</label><input class="form-control" type="date" name="invoice_date" value="<?= e($f['invoice_date']) ?>" required></div>
        <?php if ($isPurchase): ?>
        <div class="col-md-3"><label class="form-label required">رقم فاتورة المورد</label><input class="form-control" name="doc_no" value="<?= e($f['doc_no']) ?>" required maxlength="60"><div class="form-text">الرقم المكتوب على فاتورة المورد (بيمنع التكرار).</div></div>
        <?php else: ?>
        <div class="col-md-3"><label class="form-label">رقم الفاتورة</label><input class="form-control" value="يُولَّد تلقائياً (INV-…)" disabled></div>
        <?php endif; ?>
        <div class="col-md-3"><label class="form-label required"><?= e($pc['label']) ?></label>
            <select class="form-select" name="party_id" required><option value="">— اختر —</option>
            <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$f['party_id'] === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label required"><?= $isPurchase ? 'مخزن الاستلام' : 'مخزن الصرف' ?></label><select class="form-select" name="warehouse_id" required><?= select_options($whs, 'id', 'name', $f['warehouse_id'], false) ?></select></div>
      </div></div></div></div>

      <div class="col-lg-12"><div class="cardx"><div class="head"><i class="bi bi-list-ul"></i> الأصناف
          <button type="button" class="btn btn-sm btn-outline-brand ms-auto" id="addLine"><i class="bi bi-plus-lg"></i> إضافة سطر</button></div>
        <div class="table-responsive"><table class="table lines-table align-middle">
          <thead><tr><th style="min-width:260px">الصنف</th><th style="width:140px">الكمية</th><th style="width:90px">الوحدة</th><th style="width:160px">سعر الوحدة</th><th class="num" style="width:150px">الإجمالي</th><th style="width:44px"></th></tr></thead>
          <tbody id="linesBody">
          <?php foreach ($postedLines as $l): ?>
            <tr>
              <td><select class="form-select" name="item_id[]" required><?= str_replace('value="' . (int)$l['item_id'] . '"', 'value="' . (int)$l['item_id'] . '" selected', $itemOpts) ?></select></td>
              <td><input class="form-control q num" name="qty[]" value="<?= e((string)$l['qty']) ?>" inputmode="decimal" required></td>
              <td class="unit text-muted"></td>
              <td><input class="form-control p num" name="unit_price[]" value="<?= e((string)$l['unit_price']) ?>" inputmode="decimal" required></td>
              <td class="num fw-bold lt">0.00</td>
              <td><button type="button" class="btn btn-sm text-danger del-line" title="حذف السطر"><i class="bi bi-x-lg"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <template id="lineTpl"><tr>
              <td><select class="form-select" name="item_id[]" required><?= $itemOpts ?></select></td>
              <td><input class="form-control q num" name="qty[]" inputmode="decimal" required></td>
              <td class="unit text-muted"></td>
              <td><input class="form-control p num" name="unit_price[]" inputmode="decimal" required></td>
              <td class="num fw-bold lt">0.00</td>
              <td><button type="button" class="btn btn-sm text-danger del-line" title="حذف السطر"><i class="bi bi-x-lg"></i></button></td>
        </tr></template>
      </div></div>

      <div class="col-lg-7"><div class="cardx h-100"><div class="head"><i class="bi bi-percent"></i> الخصم والرسوم والسداد</div><div class="body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label">الخصم (<?= CURRENCY ?>)</label><input class="form-control num" name="discount" value="<?= e((string)$f['discount']) ?>" inputmode="decimal" placeholder="0.00"></div>
        <div class="col-md-6"><label class="form-label">مصاريف النقل (<?= CURRENCY ?>)</label><input class="form-control num" name="transport" value="<?= e((string)$f['transport']) ?>" inputmode="decimal" placeholder="0.00"></div>
        <div class="col-md-6"><label class="form-label">ضريبة القيمة المضافة (<?= CURRENCY ?>)</label>
            <div class="input-group"><input class="form-control num" name="vat" value="<?= e((string)$f['vat']) ?>" inputmode="decimal" placeholder="0.00">
            <button type="button" class="btn btn-outline-secondary" id="calcVat" data-rate="<?= e(setting('vat_rate', '14')) ?>" title="احسب بالنسبة الافتراضية"><?= e(setting('vat_rate', '14')) ?>%</button></div>
            <div class="form-text">اكتب المبلغ الظاهر في الفاتورة. الزر اختياري ويحسب النسبة الافتراضية فقط.</div></div>
        <div class="col-md-6"><label class="form-label">رسوم أخرى (<?= CURRENCY ?>)</label><input class="form-control num" name="other_fees" value="<?= e((string)$f['other_fees']) ?>" inputmode="decimal" placeholder="0.00"><div class="form-text">أي رسوم غير مسجلة ضمن النقل (لتجنب التكرار).</div></div>
        <div class="col-md-6"><label class="form-label">المدفوع الآن (<?= CURRENCY ?>)</label>
            <div class="input-group"><input class="form-control num" name="paid_amount" value="<?= e((string)$f['paid_amount']) ?>" inputmode="decimal" placeholder="0.00 = آجل"><button type="button" class="btn btn-outline-secondary" id="payAll">الكل</button></div>
            <div class="form-text"><?= $isPurchase ? 'صفر = آجل بالكامل. أي مبلغ يتسجل تلقائياً كمدفوعات في الخزينة.' : 'صفر = آجل بالكامل. أي مبلغ يتسجل تلقائياً كمقبوضات في الخزينة.' ?></div></div>
        <div class="col-md-6" id="dueBox"><label class="form-label">موعد الاستحقاق</label><input class="form-control" type="date" name="due_date" value="<?= e($f['due_date']) ?>"><div class="form-text">لو فاضي: بيتحسب من مدة <?= $isPurchase ? 'السداد' : 'التحصيل' ?> الافتراضية.</div></div>
        <div id="payBox" class="col-12" style="display:none"><div class="row g-3">
          <div class="col-md-6"><label class="form-label">حساب الخزينة</label><select class="form-select" name="account_id"><?= select_options($accounts, 'id', 'name', $f['account_id'], false) ?></select></div>
          <div class="col-md-6"><label class="form-label">طريقة الدفع</label><select class="form-select" name="payment_method"><?= simple_options(PAYMENT_METHODS, $f['payment_method']) ?></select></div>
        </div></div>
        <div class="col-md-5"><label class="form-label"><?= $isPurchase ? 'رقم إذن الاستلام' : 'رقم إذن الصرف' ?></label><input class="form-control" name="note_no" value="<?= e($f['note_no']) ?>" maxlength="60"></div>
        <div class="col-md-7"><label class="form-label">ملاحظات (موعد التسليم...)</label><input class="form-control" name="notes" value="<?= e($f['notes']) ?>" maxlength="500"></div>
      </div></div></div></div>

      <div class="col-lg-5"><div class="totals-box">
        <div class="row-t"><span>إجمالي الأصناف</span><b class="num" id="tSub">0.00</b></div>
        <div class="row-t grand"><span>إجمالي الفاتورة</span><b class="num" id="tTotal">0.00</b></div>
        <div class="row-t mt-2"><span>المتبقي (آجل)</span><b class="num text-danger" id="tRemain">0.00</b></div>
        <div class="small text-muted mt-2">الإجمالي = الكمية × السعر − الخصم + النقل + الضريبة + الرسوم الأخرى.</div>
        <button class="btn btn-brand w-100 mt-3 py-2"><i class="bi bi-check2-circle"></i> حفظ وترحيل الفاتورة</button>
        <a class="btn btn-light w-100 mt-2" href="./">إلغاء</a>
      </div></div>
    </div>
    </form>
    <?php
    page_end();
}

function invoice_view_page(string $kind): void
{
    $c = inv_cfg($kind); $pc = party_cfg($c['party']);
    $module = invoice_module($kind);
    require_module($module);
    $isPurchase = $kind === 'purchase';
    $id = (int)($_GET['id'] ?? 0);
    $inv = db_one("SELECT i.*, w.name AS wh_name, u.full_name AS creator FROM {$c['table']} i
                   JOIN warehouses w ON w.id=i.warehouse_id LEFT JOIN users u ON u.id=i.created_by WHERE i.id=?", [$id]);
    if (!$inv) { flash('error', 'الفاتورة غير موجودة.'); redirect($c['table'] . '/'); }
    $party = db_one("SELECT * FROM {$pc['table']} WHERE id=?", [$inv[$c['party_col']]]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_guard();
        try {
            $act = $_POST['action'] ?? '';
            if ($act === 'cancel') { cancel_invoice($kind, $id); flash('success', 'تم إلغاء الفاتورة وإرجاع المخزون.'); }
            elseif ($act === 'meta') { update_invoice_meta($kind, $id, $_POST); flash('success', 'تم تحديث البيانات.'); }
        } catch (Throwable $ex) { flash('error', user_error_message($ex)); }
        redirect($c['table'] . '/view.php?id=' . $id);
    }

    $lines = db_all("SELECT l.*, it.name, it.code, it.unit FROM {$c['items']} l JOIN items it ON it.id=l.item_id WHERE l.{$c['fk']}=? ORDER BY l.id", [$id]);
    $vouchers = db_all("SELECT v.id, v.doc_no, v.voucher_date, v.status, a.amount FROM voucher_allocations a JOIN vouchers v ON v.id=a.voucher_id WHERE a.invoice_id=? AND v.voucher_type=? ORDER BY v.voucher_date", [$id, $isPurchase ? 'payment' : 'receipt']);
    $remaining = $inv['status'] === 'cancelled' ? 0.0 : round((float)$inv['total'] - (float)$inv['paid_amount'], 2);

    page_start($c['title'] . ' ' . $inv['doc_no'], $module);
    print_header($c['title'] . ' رقم ' . $inv['doc_no']);
    ?>
    <div class="d-flex gap-2 flex-wrap mb-3 no-print">
      <a class="btn btn-light" href="./"><i class="bi bi-arrow-right"></i> القائمة</a>
      <button class="btn btn-outline-brand" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
      <?php if ($inv['status'] === 'active' && $remaining > 0.004 && can('vouchers')): ?>
        <a class="btn btn-gold" href="<?= e(url('vouchers/form.php?type=' . ($isPurchase ? 'payment' : 'receipt') . '&party=' . (int)$party['id'] . '&invoice=' . $id)) ?>"><i class="bi bi-cash-coin"></i> <?= $isPurchase ? 'سداد دفعة' : 'تحصيل دفعة' ?></a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="<?= e(url($pc['dir'] . '/statement.php?id=' . (int)$party['id'])) ?>"><i class="bi bi-journal-text"></i> كشف حساب <?= e($pc['label']) ?></a>
      <?php if ($inv['status'] === 'active'): ?>
      <form method="post" class="ms-auto" data-confirm="إلغاء الفاتورة؟ هيتم إرجاع المخزون وشطب قيد الفاتورة من حساب <?= e($pc['label']) ?>.<?= (float)$inv['paid_amount'] > 0 ? ' المبلغ المدفوع هيفضل مسجّل كرصيد دائن.' : '' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger"><i class="bi bi-x-octagon"></i> إلغاء الفاتورة</button></form>
      <?php endif; ?>
    </div>

    <div class="cardx mb-3"><div class="body">
      <div class="row g-3">
        <div class="col-md-3"><div class="text-muted small">رقم الفاتورة</div><div class="fs-5 fw-bold"><?= e($inv['doc_no']) ?></div></div>
        <div class="col-md-3"><div class="text-muted small">التاريخ</div><div class="fw-bold"><?= fdate($inv['invoice_date']) ?></div></div>
        <div class="col-md-3"><div class="text-muted small"><?= e($pc['label']) ?></div><div class="fw-bold"><?= e($party['name']) ?></div><div class="small text-muted" dir="ltr"><?= e($party['phone'] ?? '') ?></div><?php if ($party['tax_no']): ?><div class="small text-muted">رقم ضريبي: <?= e($party['tax_no']) ?></div><?php endif; ?></div>
        <div class="col-md-3"><div class="text-muted small">الحالة</div><div><?= invoice_status_badge($inv) ?></div></div>
        <div class="col-md-3"><div class="text-muted small"><?= $isPurchase ? 'مخزن الاستلام' : 'مخزن الصرف' ?></div><div><?= e($inv['wh_name']) ?></div></div>
        <div class="col-md-3"><div class="text-muted small"><?= $isPurchase ? 'إذن الاستلام' : 'إذن الصرف' ?></div><div><?= e((string)$inv[$c['note_col']]) ?: '—' ?></div></div>
        <div class="col-md-3"><div class="text-muted small">موعد الاستحقاق</div><div><?= fdate($inv['due_date']) ?></div></div>
        <div class="col-md-3"><div class="text-muted small">سجّلها</div><div><?= e((string)$inv['creator']) ?: '—' ?></div></div>
      </div>
      <?php if ($inv['notes']): ?><div class="mt-2 small"><span class="text-muted">ملاحظات:</span> <?= e($inv['notes']) ?></div><?php endif; ?>
    </div></div>

    <div class="row g-3">
      <div class="col-lg-8"><div class="cardx">
        <div class="table-responsive"><table class="table align-middle">
          <thead><tr><th>#</th><th>الصنف</th><th class="num">الكمية</th><th>الوحدة</th><th class="num">سعر الوحدة</th><th class="num">الإجمالي</th></tr></thead>
          <tbody><?php foreach ($lines as $n => $l): ?><tr><td><?= $n + 1 ?></td><td><?= e($l['name']) ?> <span class="text-muted small">(<?= e($l['code']) ?>)</span></td><td class="num"><?= qty($l['qty']) ?></td><td><?= e($l['unit']) ?></td><td class="num"><?= money($l['unit_price']) ?></td><td class="num"><?= money($l['line_total']) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
      </div>
      <?php if (setting('invoice_footer') && !$isPurchase): ?><p class="text-center text-muted mt-3 print-only"><?= e(setting('invoice_footer')) ?></p><?php endif; ?>
      </div>
      <div class="col-lg-4">
        <div class="totals-box">
          <div class="row-t"><span>إجمالي الأصناف</span><b class="num"><?= money($inv['subtotal']) ?></b></div>
          <?php if ((float)$inv['discount']): ?><div class="row-t"><span>الخصم</span><b class="num">− <?= money($inv['discount']) ?></b></div><?php endif; ?>
          <?php if ((float)$inv['transport']): ?><div class="row-t"><span>مصاريف النقل</span><b class="num">+ <?= money($inv['transport']) ?></b></div><?php endif; ?>
          <?php if ((float)$inv['vat']): ?><div class="row-t"><span>ضريبة القيمة المضافة</span><b class="num">+ <?= money($inv['vat']) ?></b></div><?php endif; ?>
          <?php if ((float)$inv['other_fees']): ?><div class="row-t"><span>رسوم أخرى</span><b class="num">+ <?= money($inv['other_fees']) ?></b></div><?php endif; ?>
          <div class="row-t grand"><span>إجمالي الفاتورة</span><b class="num"><?= money($inv['total']) ?> <?= CURRENCY ?></b></div>
          <div class="row-t mt-2"><span>المسدد</span><b class="num amount-pos"><?= money($inv['paid_amount']) ?></b></div>
          <div class="row-t"><span>المتبقي</span><b class="num <?= $remaining > 0.004 ? 'amount-neg' : '' ?>"><?= money($remaining) ?></b></div>
        </div>
        <?php if ($vouchers): ?>
        <div class="cardx mt-3 no-print"><div class="head">السندات المرتبطة</div><ul class="list-group list-group-flush">
          <?php foreach ($vouchers as $v): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('vouchers/view.php?id=' . (int)$v['id'])) ?>"><?= e($v['doc_no']) ?></a><span class="num"><?= money($v['amount']) ?> <?= $v['status'] === 'cancelled' ? badge('ملغي') : '' ?></span></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
        <?php if ($inv['status'] === 'active'): ?>
        <div class="cardx mt-3 no-print"><div class="head">تعديل بيانات إضافية</div><form method="post" class="body row g-2">
          <?= csrf_field() ?><input type="hidden" name="action" value="meta">
          <?php if ($remaining > 0.004): ?><div class="col-12"><label class="form-label">موعد الاستحقاق</label><input class="form-control" type="date" name="due_date" value="<?= e((string)$inv['due_date']) ?>"></div><?php endif; ?>
          <div class="col-12"><label class="form-label"><?= $isPurchase ? 'رقم إذن الاستلام' : 'رقم إذن الصرف' ?></label><input class="form-control" name="note_no" value="<?= e((string)$inv[$c['note_col']]) ?>"></div>
          <div class="col-12"><label class="form-label">ملاحظات</label><input class="form-control" name="notes" value="<?= e((string)$inv['notes']) ?>"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-brand">حفظ</button> <span class="small text-muted">الأصناف والمبالغ لا تُعدَّل — ألغِ الفاتورة وسجّلها من جديد.</span></div>
        </form></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page_end();
}
