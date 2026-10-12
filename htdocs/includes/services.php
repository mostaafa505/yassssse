<?php
/**
 * طبقة العمليات (منطق الشغل): فواتير الشراء والبيع، السندات، الخزينة، المخزون، كشوف الحسابات.
 * كل عملية بتتنفذ داخل معاملة واحدة (كله أو لا شيء) وبترمي AppError برسالة عربي لو مرفوضة.
 */
declare(strict_types=1);

// ════════════════════════════════════════════════════════════════════════════
//  إعدادات الأنواع (مشتريات/موردين ↔ مبيعات/عملاء)
// ════════════════════════════════════════════════════════════════════════════
function party_cfg(string $type): array
{
    if ($type === 'customer') {
        return ['type' => 'customer', 'table' => 'customers', 'ledger' => 'customer_ledger', 'fk' => 'customer_id',
                'inv' => 'sales', 'inv_items' => 'sale_items', 'inv_fk' => 'sale_id', 'label' => 'عميل',
                'plural' => 'العملاء', 'pay_label' => 'التحصيلات', 'module' => 'customers', 'dir' => 'customers'];
    }
    if ($type === 'supplier') {
        return ['type' => 'supplier', 'table' => 'suppliers', 'ledger' => 'supplier_ledger', 'fk' => 'supplier_id',
                'inv' => 'purchases', 'inv_items' => 'purchase_items', 'inv_fk' => 'purchase_id', 'label' => 'مورد',
                'plural' => 'الموردين', 'pay_label' => 'المدفوعات', 'module' => 'suppliers', 'dir' => 'suppliers'];
    }
    throw new AppError('نوع حساب غير معروف');
}

function inv_cfg(string $kind): array
{
    if ($kind === 'purchase') {
        return ['kind' => 'purchase', 'table' => 'purchases', 'items' => 'purchase_items', 'fk' => 'purchase_id',
                'party' => 'supplier', 'party_col' => 'supplier_id', 'stock_sign' => 1, 'move_type' => 'purchase',
                'category' => 'شراء بضاعة', 'note_col' => 'receipt_note_no', 'title' => 'فاتورة مشتريات'];
    }
    if ($kind === 'sale') {
        return ['kind' => 'sale', 'table' => 'sales', 'items' => 'sale_items', 'fk' => 'sale_id',
                'party' => 'customer', 'party_col' => 'customer_id', 'stock_sign' => -1, 'move_type' => 'sale',
                'category' => 'مبيعات', 'note_col' => 'issue_note_no', 'title' => 'فاتورة مبيعات'];
    }
    throw new AppError('نوع فاتورة غير معروف');
}

function payment_status(float $total, float $paid): string
{
    if ($total > 0.005 && $paid <= 0.005) { return 'credit'; }
    if ($paid + 0.005 >= $total) { return 'paid'; }
    return 'partial';
}
const PAYMENT_STATUS_LABELS = ['paid' => 'مدفوع', 'partial' => 'جزئي', 'credit' => 'آجل'];
const PAYMENT_STATUS_CLASS  = ['paid' => 'success', 'partial' => 'warning', 'credit' => 'danger'];

// ════════════════════════════════════════════════════════════════════════════
//  الخزينة
// ════════════════════════════════════════════════════════════════════════════
function treasury_balance(int $accountId): float
{
    $acc = db_one('SELECT opening_balance FROM treasury_accounts WHERE id=?', [$accountId]);
    if (!$acc) { return 0.0; }
    return (float)$acc['opening_balance'] + (float)db_val(
        'SELECT COALESCE(SUM(receipt_amount - payment_amount),0) FROM treasury_transactions WHERE account_id=?', [$accountId]);
}

/**
 * يمنع أي عملية تنزّل رصيد حساب الخزينة تحت الصفر (إلا لو الإعداد مسموح).
 * $delta = أثر العملية الجديدة، $excludeTxnId = حركة قديمة هتتحذف/تتعدل.
 */
function treasury_assert(int $accountId, float $delta = 0.0, int $excludeTxnId = 0): void
{
    $acc = db_one('SELECT * FROM treasury_accounts WHERE id=? FOR UPDATE', [$accountId]);
    if (!$acc) { throw new AppError('حساب الخزينة غير موجود'); }
    if (setting('allow_negative_treasury', '0') === '1') { return; }
    $before = (float)$acc['opening_balance'] + (float)db_val(
        'SELECT COALESCE(SUM(receipt_amount - payment_amount),0) FROM treasury_transactions WHERE account_id=?', [$accountId]);
    $without = (float)$acc['opening_balance'] + (float)db_val(
        'SELECT COALESCE(SUM(receipt_amount - payment_amount),0) FROM treasury_transactions WHERE account_id=? AND id<>?', [$accountId, $excludeTxnId]);
    $after = $without + $delta;
    if ($after < -0.005 && $after < $before - 0.005) {
        throw new AppError('رصيد «' . $acc['name'] . '» لا يكفي لإتمام العملية (الرصيد بعد العملية سيكون ' . money($after) . ' ' . CURRENCY . ').');
    }
}

function treasury_add(array $t): int
{
    $rec = round((float)($t['receipt_amount'] ?? 0), 2);
    $pay = round((float)($t['payment_amount'] ?? 0), 2);
    if (($rec > 0) === ($pay > 0)) { throw new AppError('الحركة لازم تكون مقبوضات أو مدفوعات (واحد منهم فقط).'); }
    treasury_assert((int)$t['account_id'], $rec - $pay);
    return db_insert('treasury_transactions', [
        'account_id' => (int)$t['account_id'], 'txn_date' => $t['txn_date'], 'doc_no' => $t['doc_no'],
        'description' => $t['description'], 'category' => $t['category'],
        'receipt_amount' => $rec, 'payment_amount' => $pay,
        'payment_method' => $t['payment_method'] ?? 'نقدي', 'responsible' => $t['responsible'] ?? current_user()['full_name'],
        'notes' => $t['notes'] ?? null, 'ref_type' => $t['ref_type'] ?? null, 'ref_id' => $t['ref_id'] ?? null,
        'created_by' => current_user()['id'] ?: null, 'created_at' => now(),
    ]);
}

/** حذف الحركات المرتبطة بمستند (مع التأكد إن الحذف مش هيخلي رصيد أي حساب سالب) */
function treasury_delete_by_ref(string $refType, int $refId): void
{
    $rows = db_all('SELECT id, account_id, receipt_amount, payment_amount FROM treasury_transactions WHERE ref_type=? AND ref_id=?', [$refType, $refId]);
    $perAcc = [];
    foreach ($rows as $r) { $perAcc[$r['account_id']][] = $r; }
    foreach ($perAcc as $accId => $list) {
        $remove = 0.0;
        foreach ($list as $r) { $remove += (float)$r['receipt_amount'] - (float)$r['payment_amount']; }
        // أثر الحذف على الرصيد = -$remove ؛ نتحقق عن طريق استبعاد كل الحركات دي
        $acc = db_one('SELECT * FROM treasury_accounts WHERE id=? FOR UPDATE', [(int)$accId]);
        if (setting('allow_negative_treasury', '0') !== '1') {
            $before = (float)$acc['opening_balance'] + (float)db_val('SELECT COALESCE(SUM(receipt_amount - payment_amount),0) FROM treasury_transactions WHERE account_id=?', [(int)$accId]);
            $after  = $before - $remove;
            if ($after < -0.005 && $after < $before - 0.005) {
                throw new AppError('لا يمكن الإلغاء لأن رصيد «' . $acc['name'] . '» سيصبح سالباً (' . money($after) . ' ' . CURRENCY . ').');
            }
        }
    }
    db_exec('DELETE FROM treasury_transactions WHERE ref_type=? AND ref_id=?', [$refType, $refId]);
}

function validate_method(string $m): string
{
    if (!in_array($m, PAYMENT_METHODS, true)) { throw new AppError('طريقة الدفع غير صحيحة.'); }
    return $m;
}
function require_account(int $id): array
{
    $a = db_one('SELECT * FROM treasury_accounts WHERE id=?', [$id]);
    if (!$a || !$a['is_active']) { throw new AppError('اختر حساب خزينة صالح.'); }
    return $a;
}

/** تسجيل/تعديل حركة يدوية في الخزينة (مبيعات نقدية أو مصروفات) */
function treasury_manual_save(array $d, ?int $id = null): int
{
    return (int)db_tx(function () use ($d, $id) {
        $date = parse_date($d['txn_date'] ?? '');
        if (!$date) { throw new AppError('التاريخ غير صحيح.'); }
        $docNo = trim((string)($d['doc_no'] ?? ''));
        if ($docNo === '') { throw new AppError('رقم الإيصال / المستند مطلوب — لا تُسجَّل حركة بدون مستند.'); }
        $desc = trim((string)($d['description'] ?? ''));
        if ($desc === '') { throw new AppError('البيان مطلوب.'); }
        $dir = $d['direction'] ?? '';
        $cat = $d['category'] ?? '';
        if ($dir === 'receipt' && !in_array($cat, MANUAL_RECEIPT_CATEGORIES, true)) { throw new AppError('تصنيف غير مسموح للمقبوضات اليدوية.'); }
        if ($dir === 'payment' && !in_array($cat, MANUAL_PAYMENT_CATEGORIES, true)) { throw new AppError('تصنيف غير مسموح للمدفوعات اليدوية.'); }
        if (!in_array($dir, ['receipt', 'payment'], true)) { throw new AppError('حدد هل الحركة مقبوضات أم مدفوعات.'); }
        $amount = round((float)($d['amount'] ?? 0), 2);
        if ($amount <= 0) { throw new AppError('المبلغ لازم يكون أكبر من صفر.'); }
        $acc = require_account((int)($d['account_id'] ?? 0));
        $method = validate_method((string)($d['payment_method'] ?? ''));
        $rec = $dir === 'receipt' ? $amount : 0.0;
        $pay = $dir === 'payment' ? $amount : 0.0;

        if ($id) {
            $old = db_one('SELECT * FROM treasury_transactions WHERE id=? FOR UPDATE', [$id]);
            if (!$old) { throw new AppError('الحركة غير موجودة.'); }
            if ($old['ref_type']) { throw new AppError('الحركة دي مرتبطة بمستند آخر ولا تُعدَّل من هنا.'); }
            if ((int)$old['account_id'] !== (int)$acc['id']) {
                treasury_assert((int)$old['account_id'], 0.0, $id);   // خروج الحركة من الحساب القديم
            }
            treasury_assert((int)$acc['id'], $rec - $pay, $id);
            db_update('treasury_transactions', $id, [
                'account_id' => $acc['id'], 'txn_date' => $date, 'doc_no' => $docNo, 'description' => $desc,
                'category' => $cat, 'receipt_amount' => $rec, 'payment_amount' => $pay, 'payment_method' => $method,
                'responsible' => trim((string)($d['responsible'] ?? '')) ?: null, 'notes' => trim((string)($d['notes'] ?? '')) ?: null,
            ]);
            audit('update', 'treasury', $id, "$cat $amount $docNo");
            return $id;
        }
        $newId = treasury_add([
            'account_id' => $acc['id'], 'txn_date' => $date, 'doc_no' => $docNo, 'description' => $desc, 'category' => $cat,
            'receipt_amount' => $rec, 'payment_amount' => $pay, 'payment_method' => $method,
            'responsible' => trim((string)($d['responsible'] ?? '')) ?: current_user()['full_name'],
            'notes' => trim((string)($d['notes'] ?? '')) ?: null,
        ]);
        audit('create', 'treasury', $newId, "$cat $amount $docNo");
        return $newId;
    });
}

function treasury_manual_delete(int $id): void
{
    db_tx(function () use ($id) {
        $old = db_one('SELECT * FROM treasury_transactions WHERE id=? FOR UPDATE', [$id]);
        if (!$old) { throw new AppError('الحركة غير موجودة.'); }
        if ($old['ref_type']) { throw new AppError('الحركة مرتبطة بمستند (فاتورة/سند/تحويل). ألغِ المستند الأصلي بدلاً من حذفها.'); }
        treasury_assert((int)$old['account_id'], 0.0, $id);
        db_exec('DELETE FROM treasury_transactions WHERE id=?', [$id]);
        audit('delete', 'treasury', $id, $old['category'] . ' ' . $old['doc_no'] . ' ' . ($old['receipt_amount'] + $old['payment_amount']));
    });
}

/** إيداع بنكي (خزينة → بنك) أو سحب بنكي (بنك → خزينة): حركتين مترابطتين */
function treasury_transfer(array $d): int
{
    return (int)db_tx(function () use ($d) {
        $op = $d['operation'] ?? '';
        if (!in_array($op, ['deposit', 'withdraw'], true)) { throw new AppError('حدد نوع العملية (إيداع أو سحب).'); }
        $date = parse_date($d['txn_date'] ?? '');
        if (!$date) { throw new AppError('التاريخ غير صحيح.'); }
        $docNo = trim((string)($d['doc_no'] ?? ''));
        if ($docNo === '') { throw new AppError('رقم الإيصال / المستند مطلوب.'); }
        $amount = round((float)($d['amount'] ?? 0), 2);
        if ($amount <= 0) { throw new AppError('المبلغ لازم يكون أكبر من صفر.'); }
        $cash = require_account((int)($d['cash_account_id'] ?? 0));
        $bank = require_account((int)($d['bank_account_id'] ?? 0));
        if ($cash['id'] === $bank['id']) { throw new AppError('اختر حسابين مختلفين.'); }
        $method = validate_method((string)($d['payment_method'] ?? 'تحويل بنكي'));
        $notes = trim((string)($d['notes'] ?? '')) ?: null;
        $cat = $op === 'deposit' ? 'إيداع بنكي' : 'سحب بنكي';
        [$from, $to] = $op === 'deposit' ? [$cash, $bank] : [$bank, $cash];
        $desc = ($op === 'deposit' ? 'إيداع من «' . $from['name'] . '» إلى «' . $to['name'] . '»' : 'سحب من «' . $from['name'] . '» إلى «' . $to['name'] . '»');
        $base = ['txn_date' => $date, 'doc_no' => $docNo, 'description' => $desc, 'category' => $cat, 'payment_method' => $method,
                 'notes' => $notes, 'responsible' => trim((string)($d['responsible'] ?? '')) ?: current_user()['full_name']];
        $outId = treasury_add($base + ['account_id' => $from['id'], 'payment_amount' => $amount, 'ref_type' => 'transfer']);
        $inId  = treasury_add($base + ['account_id' => $to['id'], 'receipt_amount' => $amount, 'ref_type' => 'transfer']);
        db_exec('UPDATE treasury_transactions SET ref_id=? WHERE id IN (?, ?)', [$outId, $outId, $inId]);
        audit('create', 'treasury', $outId, "$cat $amount $docNo");
        return $outId;
    });
}
function treasury_transfer_delete(int $refId): void
{
    db_tx(function () use ($refId) {
        if (!db_val("SELECT COUNT(*) FROM treasury_transactions WHERE ref_type='transfer' AND ref_id=?", [$refId])) { throw new AppError('التحويل غير موجود.'); }
        treasury_delete_by_ref('transfer', $refId);
        audit('delete', 'treasury', $refId, 'حذف تحويل بنكي');
    });
}

// ════════════════════════════════════════════════════════════════════════════
//  كشوف حسابات العملاء والموردين
// ════════════════════════════════════════════════════════════════════════════
function ledger_add(string $partyType, int $partyId, array $e): int
{
    $c = party_cfg($partyType);
    return db_insert($c['ledger'], [
        $c['fk'] => $partyId, 'entry_date' => $e['entry_date'], 'doc_type' => $e['doc_type'], 'doc_no' => $e['doc_no'] ?? null,
        'invoice_amount' => round((float)($e['invoice_amount'] ?? 0), 2), 'payment_amount' => round((float)($e['payment_amount'] ?? 0), 2),
        'due_date' => $e['due_date'] ?? null, 'ref_type' => $e['ref_type'] ?? null, 'ref_id' => $e['ref_id'] ?? null,
        'notes' => $e['notes'] ?? null, 'created_by' => current_user()['id'] ?: null, 'created_at' => now(),
    ]);
}

function party_balance(string $partyType, int $partyId): float
{
    $c = party_cfg($partyType);
    return (float)db_val("SELECT COALESCE(SUM(invoice_amount - payment_amount),0) FROM {$c['ledger']} WHERE {$c['fk']}=?", [$partyId]);
}

/** الرصيد الافتتاحي: موجب = مستحق على الطرف ده (عميل مدين لنا / نحن مدينين لمورد) */
function party_set_opening(string $partyType, int $partyId, float $amount, string $date): void
{
    $c = party_cfg($partyType);
    $amount = round($amount, 2);
    $row = db_one("SELECT id FROM {$c['ledger']} WHERE {$c['fk']}=? AND doc_type='opening'", [$partyId]);
    if (money_eq($amount, 0.0)) {
        if ($row) { db_exec("DELETE FROM {$c['ledger']} WHERE id=?", [$row['id']]); }
        return;
    }
    $data = ['entry_date' => $date, 'invoice_amount' => $amount > 0 ? $amount : 0, 'payment_amount' => $amount < 0 ? -$amount : 0];
    if ($row) { db_update($c['ledger'], (int)$row['id'], $data); }
    else { ledger_add($partyType, $partyId, $data + ['doc_type' => 'opening', 'doc_no' => 'رصيد افتتاحي', 'notes' => 'رصيد افتتاحي']); }
}
function party_opening(string $partyType, int $partyId): float
{
    $c = party_cfg($partyType);
    $r = db_one("SELECT invoice_amount - payment_amount AS v FROM {$c['ledger']} WHERE {$c['fk']}=? AND doc_type='opening'", [$partyId]);
    return $r ? (float)$r['v'] : 0.0;
}

/** إشعار إضافة (يزيد الرصيد) أو إشعار خصم (ينقصه — مثلاً مرتجع) */
function ledger_note(string $partyType, int $partyId, array $d): int
{
    return (int)db_tx(function () use ($partyType, $partyId, $d) {
        $c = party_cfg($partyType);
        if (!db_val("SELECT id FROM {$c['table']} WHERE id=?", [$partyId])) { throw new AppError('الحساب غير موجود.'); }
        $date = parse_date($d['entry_date'] ?? '');
        if (!$date) { throw new AppError('التاريخ غير صحيح.'); }
        $docNo = trim((string)($d['doc_no'] ?? ''));
        if ($docNo === '') { throw new AppError('رقم الإشعار مطلوب.'); }
        $amount = round((float)($d['amount'] ?? 0), 2);
        if ($amount <= 0) { throw new AppError('المبلغ لازم يكون أكبر من صفر.'); }
        $kind = $d['kind'] ?? '';
        if (!in_array($kind, ['add', 'discount'], true)) { throw new AppError('حدد نوع الإشعار.'); }
        $id = ledger_add($partyType, $partyId, [
            'entry_date' => $date, 'doc_type' => $kind === 'add' ? 'debit_note' : 'credit_note', 'doc_no' => $docNo,
            'invoice_amount' => $kind === 'add' ? $amount : 0, 'payment_amount' => $kind === 'discount' ? $amount : 0,
            'ref_type' => 'note', 'notes' => trim((string)($d['notes'] ?? '')) ?: null,
        ]);
        audit('create', $c['table'] . '_note', $id, ($kind === 'add' ? 'إشعار إضافة ' : 'إشعار خصم ') . "$amount $docNo");
        return $id;
    });
}
function ledger_note_delete(string $partyType, int $ledgerId): void
{
    $c = party_cfg($partyType);
    $row = db_one("SELECT * FROM {$c['ledger']} WHERE id=?", [$ledgerId]);
    if (!$row || $row['ref_type'] !== 'note') { throw new AppError('يمكن حذف الإشعارات اليدوية فقط.'); }
    db_exec("DELETE FROM {$c['ledger']} WHERE id=?", [$ledgerId]);
    audit('delete', $c['table'] . '_note', $ledgerId, (string)$row['doc_no']);
}

/** ملخص الحسابات: الرصيد + المتأخر + حالة الحساب (نشط / مغلق / متأخر) */
function party_summaries(string $partyType, string $where = '1=1', array $params = []): array
{
    $c = party_cfg($partyType);
    $sql = "SELECT p.*, COALESCE(l.bal,0) AS balance, COALESCE(l.cnt,0) AS entries, l.last_date,
                   COALESCE(l.invoiced,0) AS total_invoiced, COALESCE(l.paid,0) AS total_paid, COALESCE(l.opening,0) AS opening_bal,
                   COALESCE(o.overdue,0) AS overdue, o.max_days, COALESCE(q.open_amt,0) AS open_amt, q.next_due
            FROM {$c['table']} p
            LEFT JOIN (SELECT {$c['fk']} AS pid, SUM(invoice_amount - payment_amount) AS bal, COUNT(*) AS cnt, MAX(entry_date) AS last_date,
                              SUM(CASE WHEN doc_type<>'opening' THEN invoice_amount ELSE 0 END) AS invoiced,
                              SUM(payment_amount) AS paid,
                              SUM(CASE WHEN doc_type='opening' THEN invoice_amount - payment_amount ELSE 0 END) AS opening
                       FROM {$c['ledger']} GROUP BY {$c['fk']}) l ON l.pid = p.id
            LEFT JOIN (SELECT {$c['fk']} AS pid, SUM(total - paid_amount) AS overdue, MAX(DATEDIFF(?, due_date)) AS max_days
                       FROM {$c['inv']} WHERE status='active' AND total - paid_amount > 0.005 AND due_date < ? GROUP BY {$c['fk']}) o ON o.pid = p.id
            LEFT JOIN (SELECT {$c['fk']} AS pid, SUM(total - paid_amount) AS open_amt, MIN(due_date) AS next_due
                       FROM {$c['inv']} WHERE status='active' AND total - paid_amount > 0.005 GROUP BY {$c['fk']}) q ON q.pid = p.id
            WHERE $where ORDER BY p.name";
    $rows = db_all($sql, [today(), today(), ...$params]);
    foreach ($rows as &$r) { $r['status_key'] = party_status($r); }
    return $rows;
}
function party_status(array $r): string
{
    if ((int)$r['entries'] === 0) { return 'active'; }
    if ((float)$r['overdue'] > 0.005) { return 'late'; }
    if (abs((float)$r['balance']) < 0.005) { return 'closed'; }
    return 'active';
}
const PARTY_STATUS_LABELS = ['active' => 'نشط', 'closed' => 'مغلق', 'late' => 'متأخر'];
const PARTY_STATUS_CLASS  = ['active' => 'success', 'closed' => 'secondary', 'late' => 'danger'];

/** كشف الحساب بنفس أعمدة الإكسل: الرصيد السابق | الفاتورة | التحصيل/السداد | الرصيد المتبقي */
function party_statement(string $partyType, int $partyId, ?string $from = null, ?string $to = null): array
{
    $c = party_cfg($partyType);
    $prev = 0.0;
    if ($from) {
        // الرصيد الافتتاحي بيتحسب دايماً ضمن "الرصيد السابق" مهما كان تاريخه
        $prev = (float)db_val("SELECT COALESCE(SUM(invoice_amount - payment_amount),0) FROM {$c['ledger']} WHERE {$c['fk']}=? AND (entry_date < ? OR doc_type='opening')", [$partyId, $from]);
    }
    $sql = "SELECT * FROM {$c['ledger']} WHERE {$c['fk']}=?";
    $params = [$partyId];
    if ($from) { $sql .= " AND doc_type<>'opening' AND entry_date >= ?"; $params[] = $from; }
    if ($to)   { $sql .= " AND (entry_date <= ? OR doc_type='opening')"; $params[] = $to; }
    $sql .= " ORDER BY (doc_type='opening') DESC, entry_date, id";
    $rows = db_all($sql, $params);
    $running = $prev;
    foreach ($rows as &$r) {
        $r['prev_balance'] = $running;
        $running = round($running + (float)$r['invoice_amount'] - (float)$r['payment_amount'], 2);
        $r['balance'] = $running;
    }
    return ['opening' => $prev, 'rows' => $rows, 'closing' => $running];
}

// ════════════════════════════════════════════════════════════════════════════
//  المخزون
// ════════════════════════════════════════════════════════════════════════════
function stock_balance(int $itemId, int $warehouseId): float
{
    return (float)db_val('SELECT COALESCE(SUM(qty),0) FROM stock_movements WHERE item_id=? AND warehouse_id=?', [$itemId, $warehouseId]);
}

/** يمنع أي عملية تنزّل رصيد صنف في مخزن تحت الصفر */
function stock_assert(int $itemId, int $warehouseId, float $delta, array $excludeIds = []): void
{
    $item = db_one('SELECT id, name, unit FROM items WHERE id=? FOR UPDATE', [$itemId]);
    if (!$item) { throw new AppError('الصنف غير موجود.'); }
    $before = stock_balance($itemId, $warehouseId);
    $removed = 0.0;
    if ($excludeIds) {
        $in = implode(',', array_fill(0, count($excludeIds), '?'));
        $removed = (float)db_val("SELECT COALESCE(SUM(qty),0) FROM stock_movements WHERE id IN ($in)", array_values($excludeIds));
    }
    $after = $before - $removed + $delta;
    if ($after < -0.0005 && $after < $before - 0.0005) {
        $wh = (string)db_val('SELECT name FROM warehouses WHERE id=?', [$warehouseId]);
        throw new AppError('رصيد الصنف «' . $item['name'] . '» في «' . $wh . '» لا يكفي (المتاح ' . qty($before) . ' ' . $item['unit'] . ').');
    }
}

function stock_add(array $m): int
{
    $q = round((float)$m['qty'], 3);
    if (abs($q) < 0.0005) { throw new AppError('الكمية لازم تكون أكبر من صفر.'); }
    if ($q < 0) { stock_assert((int)$m['item_id'], (int)$m['warehouse_id'], $q); }
    else        { db_val('SELECT id FROM items WHERE id=? FOR UPDATE', [(int)$m['item_id']]); }
    return db_insert('stock_movements', [
        'item_id' => (int)$m['item_id'], 'warehouse_id' => (int)$m['warehouse_id'], 'move_date' => $m['move_date'],
        'move_type' => $m['move_type'], 'qty' => $q, 'unit_cost' => $m['unit_cost'] ?? null, 'doc_no' => $m['doc_no'] ?? null,
        'ref_type' => $m['ref_type'] ?? null, 'ref_id' => $m['ref_id'] ?? null, 'reason' => $m['reason'] ?? null,
        'notes' => $m['notes'] ?? null, 'created_by' => current_user()['id'] ?: null, 'created_at' => now(),
    ]);
}

/** حذف حركات مستند (فاتورة/عملية) مع التأكد إن الحذف مش هيخلي أي رصيد سالب */
function stock_delete_by_ref(string $refType, int $refId): void
{
    $rows = db_all('SELECT id, item_id, warehouse_id, qty FROM stock_movements WHERE ref_type=? AND ref_id=?', [$refType, $refId]);
    $groups = [];
    foreach ($rows as $r) { $groups[$r['item_id'] . '-' . $r['warehouse_id']][] = $r; }
    foreach ($groups as $g) {
        stock_assert((int)$g[0]['item_id'], (int)$g[0]['warehouse_id'], 0.0, array_column($g, 'id'));
    }
    db_exec('DELETE FROM stock_movements WHERE ref_type=? AND ref_id=?', [$refType, $refId]);
}

function default_warehouse_id(): int
{
    $id = db_val('SELECT id FROM warehouses WHERE is_active=1 ORDER BY is_default DESC, id LIMIT 1');
    return (int)$id;
}

/** الرصيد الافتتاحي للصنف (رصيد أول المدة) في مخزن */
function item_set_opening(int $itemId, int $warehouseId, float $qty, string $date): void
{
    $qty = round($qty, 3);
    $row = db_one("SELECT id, qty FROM stock_movements WHERE item_id=? AND warehouse_id=? AND move_type='opening' FOR UPDATE", [$itemId, $warehouseId]);
    if ($row) {
        if (abs($qty - (float)$row['qty']) < 0.0005) { return; }
        stock_assert($itemId, $warehouseId, $qty, [(int)$row['id']]);
        if (abs($qty) < 0.0005) { db_exec('DELETE FROM stock_movements WHERE id=?', [$row['id']]); }
        else { db_update('stock_movements', (int)$row['id'], ['qty' => $qty, 'move_date' => $date]); }
        return;
    }
    if (abs($qty) < 0.0005) { return; }
    if ($qty < 0) { throw new AppError('رصيد أول المدة لا يمكن أن يكون سالباً.'); }
    stock_add(['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'move_date' => $date, 'move_type' => 'opening',
               'qty' => $qty, 'doc_no' => 'رصيد أول المدة', 'ref_type' => 'opening', 'ref_id' => $itemId]);
}

/**
 * عملية مخزنية يدوية: إذن استلام / إذن صرف / تسوية جرد (زيادة أو عجز) / تحويل بين مخزنين.
 */
function stock_operation(array $d): int
{
    return (int)db_tx(function () use ($d) {
        $op = $d['operation'] ?? '';
        $date = parse_date($d['move_date'] ?? '');
        if (!$date) { throw new AppError('التاريخ غير صحيح.'); }
        $item = db_one('SELECT * FROM items WHERE id=?', [(int)($d['item_id'] ?? 0)]);
        if (!$item || !$item['is_active']) { throw new AppError('اختر صنفاً صالحاً.'); }
        $wh = db_one('SELECT * FROM warehouses WHERE id=?', [(int)($d['warehouse_id'] ?? 0)]);
        if (!$wh || !$wh['is_active']) { throw new AppError('اختر مخزناً صالحاً.'); }
        $q = round((float)($d['qty'] ?? 0), 3);
        if ($q <= 0) { throw new AppError('الكمية لازم تكون أكبر من صفر.'); }
        $reason = trim((string)($d['reason'] ?? '')) ?: null;
        $notes = trim((string)($d['notes'] ?? '')) ?: null;
        $docNo = trim((string)($d['doc_no'] ?? ''));

        $map = [
            'receipt'  => ['receipt',    1, 'GR-', 'gr'],
            'issue'    => ['issue',     -1, 'GI-', 'gi'],
            'adj_in'   => ['adjustment', 1, 'ADJ-', 'adj'],
            'adj_out'  => ['adjustment',-1, 'ADJ-', 'adj'],
        ];
        if ($op === 'transfer') {
            $to = db_one('SELECT * FROM warehouses WHERE id=?', [(int)($d['to_warehouse_id'] ?? 0)]);
            if (!$to || !$to['is_active']) { throw new AppError('اختر مخزن الوجهة.'); }
            if ((int)$to['id'] === (int)$wh['id']) { throw new AppError('مخزن الوجهة لازم يختلف عن المخزن المصدر.'); }
            if ($docNo === '') { $docNo = next_number('tr', 'TR-', 5); }
            $out = stock_add(['item_id' => $item['id'], 'warehouse_id' => $wh['id'], 'move_date' => $date, 'move_type' => 'transfer_out',
                              'qty' => -$q, 'doc_no' => $docNo, 'ref_type' => 'transfer', 'reason' => $reason, 'notes' => $notes ?: ('إلى ' . $to['name'])]);
            stock_add(['item_id' => $item['id'], 'warehouse_id' => $to['id'], 'move_date' => $date, 'move_type' => 'transfer_in',
                       'qty' => $q, 'doc_no' => $docNo, 'ref_type' => 'transfer', 'ref_id' => $out, 'reason' => $reason, 'notes' => $notes ?: ('من ' . $wh['name'])]);
            db_exec('UPDATE stock_movements SET ref_id=? WHERE id=?', [$out, $out]);
            audit('create', 'stock', $out, "تحويل {$item['name']} $q");
            return $out;
        }
        if (!isset($map[$op])) { throw new AppError('نوع العملية غير صحيح.'); }
        [$type, $sign, $prefix, $seq] = $map[$op];
        if ($docNo === '') { $docNo = next_number($seq, $prefix, 5); }
        if ($type === 'adjustment' && !$reason) { throw new AppError('سبب التسوية مطلوب (جرد / تلف / مرتجع ...).'); }
        $id = stock_add(['item_id' => $item['id'], 'warehouse_id' => $wh['id'], 'move_date' => $date, 'move_type' => $type,
                         'qty' => $sign * $q, 'doc_no' => $docNo, 'ref_type' => 'manual', 'reason' => $reason, 'notes' => $notes]);
        db_exec('UPDATE stock_movements SET ref_id=? WHERE id=?', [$id, $id]);
        audit('create', 'stock', $id, "$op {$item['name']} $q $docNo");
        return $id;
    });
}
function stock_operation_delete(int $id): void
{
    db_tx(function () use ($id) {
        $m = db_one('SELECT * FROM stock_movements WHERE id=? FOR UPDATE', [$id]);
        if (!$m) { throw new AppError('الحركة غير موجودة.'); }
        if ($m['ref_type'] === 'transfer') {
            $refId = (int)$m['ref_id'];
            stock_delete_by_ref('transfer', $refId);
        } elseif ($m['ref_type'] === 'manual') {
            stock_delete_by_ref('manual', (int)$m['ref_id']);
        } else {
            throw new AppError('الحركة دي ناتجة عن مستند (فاتورة). ألغِ المستند الأصلي بدلاً من حذفها.');
        }
        audit('delete', 'stock', $id, (string)$m['doc_no']);
    });
}

/** ملخص أرصدة الأصناف بنفس منطق شيت المخازن: أول المدة + الوارد − المنصرف = الرصيد الحالي */
function stock_summary(?int $warehouseId = null, ?string $from = null, ?string $to = null, string $search = ''): array
{
    $to = $to ?: today();
    $params = [];
    $joinWh = '';
    $skipTransfers = '';
    if ($warehouseId) { $joinWh = ' AND m.warehouse_id = ?'; }
    else { $skipTransfers = " AND m.move_type NOT IN ('transfer_in','transfer_out')"; }

    $openCond = "(m.move_type='opening' OR (? IS NOT NULL AND m.move_date < ?))";
    $inCond   = "m.move_type<>'opening'$skipTransfers AND m.qty>0 AND (? IS NULL OR m.move_date >= ?)";
    $outCond  = "m.move_type<>'opening'$skipTransfers AND m.qty<0 AND (? IS NULL OR m.move_date >= ?)";
    // الترتيب: opening، in، out ثم شروط الـJOIN والـWHERE
    $sql = "SELECT i.id, i.code, i.name, i.unit, i.reorder_level, i.is_active,
              COALESCE(SUM(CASE WHEN $openCond THEN m.qty END),0) AS opening_qty,
              COALESCE(SUM(CASE WHEN $inCond THEN m.qty END),0) AS in_qty,
              COALESCE(-SUM(CASE WHEN $outCond THEN m.qty END),0) AS out_qty
            FROM items i
            LEFT JOIN stock_movements m ON m.item_id = i.id AND m.move_date <= ?$joinWh
            WHERE 1=1";
    $params = [$from, $from, $from, $from, $from, $from, $to];
    if ($warehouseId) { $params[] = $warehouseId; }
    if ($search !== '') {
        $sql .= ' AND (i.name LIKE ? OR i.code LIKE ?)';
        $params[] = "%$search%"; $params[] = "%$search%";
    }
    $sql .= ' GROUP BY i.id, i.code, i.name, i.unit, i.reorder_level, i.is_active ORDER BY i.name';
    $rows = db_all($sql, $params);
    foreach ($rows as &$r) {
        $r['current_qty'] = round((float)$r['opening_qty'] + (float)$r['in_qty'] - (float)$r['out_qty'], 3);
        $r['stock_status'] = $r['current_qty'] <= (float)$r['reorder_level'] ? 'reorder' : 'ok';
    }
    return $rows;
}

// ════════════════════════════════════════════════════════════════════════════
//  فواتير الشراء والبيع
// ════════════════════════════════════════════════════════════════════════════
/** يحوّل مصفوفات الفورم (item_id[], qty[], unit_price[]) لسطور سليمة */
function normalize_lines(array $post): array
{
    $ids = $post['item_id'] ?? []; $qtys = $post['qty'] ?? []; $prices = $post['unit_price'] ?? [];
    $lines = [];
    foreach ((array)$ids as $i => $itemId) {
        $itemId = (int)$itemId;
        $q = parse_num($qtys[$i] ?? null); $p = parse_num($prices[$i] ?? null);
        if (!$itemId && $q === null && $p === null) { continue; }   // سطر فاضي
        if (!$itemId) { throw new AppError('اختر الصنف في كل سطر.'); }
        if ($q === null || $q <= 0) { throw new AppError('الكمية لازم تكون أكبر من صفر في كل سطر.'); }
        if ($p === null || $p < 0) { throw new AppError('سعر الوحدة غير صحيح.'); }
        $lines[] = ['item_id' => $itemId, 'qty' => round($q, 3), 'unit_price' => round($p, 2), 'line_total' => round($q * $p, 2)];
    }
    return $lines;
}

/**
 * ترحيل فاتورة (شراء أو بيع): السطور + حركة المخزون + قيد في حساب الطرف + دفعة نقدية لو في مدفوع.
 */
function post_invoice(string $kind, array $d, array $lines): int
{
    $c = inv_cfg($kind);
    $pc = party_cfg($c['party']);
    return (int)db_tx(function () use ($c, $pc, $d, $lines) {
        $date = parse_date($d['invoice_date'] ?? '');
        if (!$date) { throw new AppError('تاريخ الفاتورة غير صحيح.'); }
        $party = db_one("SELECT * FROM {$pc['table']} WHERE id=? FOR UPDATE", [(int)($d['party_id'] ?? 0)]);
        if (!$party || !$party['is_active']) { throw new AppError('اختر ' . $pc['label'] . ' صالحاً.'); }
        $wh = db_one('SELECT * FROM warehouses WHERE id=?', [(int)($d['warehouse_id'] ?? 0)]);
        if (!$wh || !$wh['is_active']) { throw new AppError('اختر المخزن.'); }
        if (!$lines) { throw new AppError('أضف صنفاً واحداً على الأقل.'); }

        $docNo = trim((string)($d['doc_no'] ?? ''));
        if ($c['kind'] === 'purchase') {
            if ($docNo === '') { throw new AppError('رقم فاتورة المورد مطلوب — لا تُسجَّل فاتورة بدون مستند.'); }
            if (db_val('SELECT id FROM purchases WHERE supplier_id=? AND doc_no=?', [$party['id'], $docNo])) {
                throw new AppError('رقم الفاتورة «' . $docNo . '» مسجّل قبل كده لنفس المورد — منع تكرار التسجيل.');
            }
        } else {
            $docNo = next_number('sale', 'INV-', 6);
        }

        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $discount = round(max(0, (float)($d['discount'] ?? 0)), 2);
        $transport = round(max(0, (float)($d['transport'] ?? 0)), 2);
        $vat = round(max(0, (float)($d['vat'] ?? 0)), 2);
        $other = round(max(0, (float)($d['other_fees'] ?? 0)), 2);
        if ($discount > $subtotal + 0.005) { throw new AppError('الخصم أكبر من قيمة الأصناف.'); }
        $total = round($subtotal - $discount + $transport + $vat + $other, 2);   // نفس معادلة الإكسل
        $paid = round(max(0, (float)($d['paid_amount'] ?? 0)), 2);
        if ($paid > $total + 0.005) { throw new AppError('المبلغ المدفوع أكبر من إجمالي الفاتورة.'); }

        $due = null;
        if ($paid + 0.005 < $total) {
            $due = parse_date($d['due_date'] ?? '') ?: add_days($date, (int)$party['credit_days']);
        }

        $accountId = 0; $method = 'نقدي';
        if ($paid > 0) {
            $accountId = (int)require_account((int)($d['account_id'] ?? 0))['id'];
            $method = validate_method((string)($d['payment_method'] ?? ''));
        }

        $noteCol = $c['note_col'];
        $invId = db_insert($c['table'], [
            'invoice_date' => $date, 'doc_no' => $docNo, $c['party_col'] => $party['id'], 'warehouse_id' => $wh['id'],
            'subtotal' => $subtotal, 'discount' => $discount, 'transport' => $transport, 'vat' => $vat, 'other_fees' => $other,
            'total' => $total, 'paid_amount' => $paid, 'due_date' => $due, $noteCol => trim((string)($d['note_no'] ?? '')) ?: null,
            'notes' => trim((string)($d['notes'] ?? '')) ?: null, 'status' => 'active',
            'created_by' => current_user()['id'] ?: null, 'created_at' => now(),
        ]);

        foreach ($lines as $l) {
            db_insert($c['items'], [$c['fk'] => $invId, 'item_id' => $l['item_id'], 'qty' => $l['qty'],
                                    'unit_price' => $l['unit_price'], 'line_total' => $l['line_total']]);
            $item = db_one('SELECT is_active FROM items WHERE id=?', [$l['item_id']]);
            if (!$item || !$item['is_active']) { throw new AppError('أحد الأصناف غير صالح.'); }
        }
        // حركة المخزون: تجميع السطور المتكررة لنفس الصنف عشان فحص الرصيد يكون صحيح
        $perItem = [];
        foreach ($lines as $l) { $perItem[$l['item_id']] = ($perItem[$l['item_id']] ?? 0) + $l['qty']; }
        ksort($perItem);   // ترتيب ثابت لتفادي deadlock
        foreach ($perItem as $itemId => $q) {
            stock_add(['item_id' => $itemId, 'warehouse_id' => $wh['id'], 'move_date' => $date, 'move_type' => $c['move_type'],
                       'qty' => $c['stock_sign'] * $q, 'doc_no' => $docNo, 'ref_type' => $c['kind'], 'ref_id' => $invId]);
        }
        // قيد الفاتورة في حساب العميل/المورد
        ledger_add($c['party'], (int)$party['id'], ['entry_date' => $date, 'doc_type' => 'invoice', 'doc_no' => $docNo,
            'invoice_amount' => $total, 'due_date' => $due, 'ref_type' => $c['kind'], 'ref_id' => $invId,
            'notes' => $c['title'] . ($paid > 0 ? ' (مدفوع عند التسجيل ' . money($paid) . ')' : '')]);
        // الدفعة المقدمة (لو في)
        if ($paid > 0) {
            ledger_add($c['party'], (int)$party['id'], ['entry_date' => $date, 'doc_type' => 'payment', 'doc_no' => $docNo,
                'payment_amount' => $paid, 'ref_type' => $c['kind'], 'ref_id' => $invId, 'notes' => 'مدفوع مع الفاتورة']);
            $isPurchase = $c['kind'] === 'purchase';
            treasury_add([
                'account_id' => $accountId, 'txn_date' => $date, 'doc_no' => $docNo, 'category' => $c['category'],
                'description' => ($isPurchase ? 'سداد فاتورة مشتريات ' : 'تحصيل فاتورة مبيعات ') . $docNo . ' — ' . $party['name'],
                'receipt_amount' => $isPurchase ? 0 : $paid, 'payment_amount' => $isPurchase ? $paid : 0,
                'payment_method' => $method, 'ref_type' => $c['kind'], 'ref_id' => $invId,
            ]);
        }
        audit('create', $c['table'], $invId, $c['title'] . " $docNo — {$party['name']} — إجمالي " . money($total));
        return $invId;
    });
}

/** إلغاء فاتورة: يرجّع المخزون ويشيل قيد الفاتورة. الدفعة المقدمة (لو في) بتفضل كرصيد دائن في حساب الطرف. */
function cancel_invoice(string $kind, int $id): void
{
    $c = inv_cfg($kind);
    db_tx(function () use ($c, $id) {
        $inv = db_one("SELECT * FROM {$c['table']} WHERE id=? FOR UPDATE", [$id]);
        if (!$inv) { throw new AppError('الفاتورة غير موجودة.'); }
        if ($inv['status'] === 'cancelled') { throw new AppError('الفاتورة ملغاة بالفعل.'); }
        $linked = (int)db_val('SELECT COUNT(*) FROM voucher_allocations va JOIN vouchers v ON v.id=va.voucher_id WHERE va.invoice_id=? AND v.status=\'active\' AND v.voucher_type=?',
            [$id, $c['kind'] === 'sale' ? 'receipt' : 'payment']);
        if ($linked) { throw new AppError('في سندات مرتبطة بالفاتورة دي. ألغِ السندات أولاً ثم ألغِ الفاتورة.'); }

        stock_delete_by_ref($c['kind'], $id);   // يتأكد إن الإلغاء مش هيسبب رصيد سالب
        $pc = party_cfg($c['party']);
        db_exec("DELETE FROM {$pc['ledger']} WHERE ref_type=? AND ref_id=? AND doc_type='invoice'", [$c['kind'], $id]);
        // الدفعة المقدمة تفضل: تتحول لحركة عادية غير مرتبطة بالفاتورة الملغاة
        $note = ' (فاتورة ' . $inv['doc_no'] . ' ملغاة)';
        db_exec("UPDATE {$pc['ledger']} SET ref_type=NULL, ref_id=NULL, notes=CONCAT(COALESCE(notes,''), ?) WHERE ref_type=? AND ref_id=?", [$note, $c['kind'], $id]);
        db_exec('UPDATE treasury_transactions SET ref_type=NULL, ref_id=NULL, notes=CONCAT(COALESCE(notes,\'\'), ?) WHERE ref_type=? AND ref_id=?', [$note, $c['kind'], $id]);
        db_exec("UPDATE {$c['table']} SET status='cancelled', cancelled_at=? WHERE id=?", [now(), $id]);
        audit('cancel', $c['table'], $id, $c['title'] . ' ' . $inv['doc_no']);
    });
}

function update_invoice_meta(string $kind, int $id, array $d): void
{
    $c = inv_cfg($kind);
    $inv = db_one("SELECT * FROM {$c['table']} WHERE id=?", [$id]);
    if (!$inv) { throw new AppError('الفاتورة غير موجودة.'); }
    $due = parse_date($d['due_date'] ?? '');
    if ($inv['status'] === 'cancelled') { throw new AppError('الفاتورة ملغاة.'); }
    db_tx(function () use ($c, $id, $inv, $d, $due) {
        $pc = party_cfg($c['party']);
        db_update($c['table'], $id, [
            'due_date' => money_eq((float)$inv['total'], (float)$inv['paid_amount']) ? null : $due,
            $c['note_col'] => trim((string)($d['note_no'] ?? '')) ?: null, 'notes' => trim((string)($d['notes'] ?? '')) ?: null,
        ]);
        if (!money_eq((float)$inv['total'], (float)$inv['paid_amount'])) {
            db_exec("UPDATE {$pc['ledger']} SET due_date=? WHERE ref_type=? AND ref_id=? AND doc_type='invoice'", [$due, $c['kind'], $id]);
        }
        audit('update', $c['table'], $id, 'تعديل بيانات إضافية للفاتورة');
    });
}

// ════════════════════════════════════════════════════════════════════════════
//  سندات القبض (عملاء) والصرف (موردين) + توزيعها على الفواتير
// ════════════════════════════════════════════════════════════════════════════
function open_invoices(string $kind, int $partyId): array
{
    $c = inv_cfg($kind);
    return db_all("SELECT id, doc_no, invoice_date, due_date, total, paid_amount, (total - paid_amount) AS remaining
                   FROM {$c['table']} WHERE {$c['party_col']}=? AND status='active' AND total - paid_amount > 0.005
                   ORDER BY COALESCE(due_date, invoice_date), invoice_date, id", [$partyId]);
}

/**
 * سند قبض من عميل (receipt) أو صرف لمورد (payment).
 * alloc: auto = يتوزع على أقدم الفواتير المفتوحة | invoice = فاتورة محددة | none = دفعة على الحساب
 */
function post_voucher(string $type, array $d): int
{
    if (!in_array($type, ['receipt', 'payment'], true)) { throw new AppError('نوع السند غير صحيح.'); }
    $isReceipt = $type === 'receipt';
    $partyType = $isReceipt ? 'customer' : 'supplier';
    $pc = party_cfg($partyType);
    $kind = $isReceipt ? 'sale' : 'purchase';
    $ic = inv_cfg($kind);

    return (int)db_tx(function () use ($type, $d, $isReceipt, $partyType, $pc, $kind, $ic) {
        $date = parse_date($d['voucher_date'] ?? '');
        if (!$date) { throw new AppError('التاريخ غير صحيح.'); }
        $party = db_one("SELECT * FROM {$pc['table']} WHERE id=? FOR UPDATE", [(int)($d['party_id'] ?? 0)]);
        if (!$party) { throw new AppError('اختر ' . $pc['label'] . '.'); }
        $amount = round((float)($d['amount'] ?? 0), 2);
        if ($amount <= 0) { throw new AppError('المبلغ لازم يكون أكبر من صفر.'); }
        $acc = require_account((int)($d['account_id'] ?? 0));
        $method = validate_method((string)($d['payment_method'] ?? ''));
        $docNo = trim((string)($d['doc_no'] ?? ''));
        if ($docNo === '') { $docNo = $isReceipt ? next_number('rv', 'RV-', 6) : next_number('pv', 'PV-', 6); }
        elseif (db_val('SELECT id FROM vouchers WHERE voucher_type=? AND doc_no=?', [$type, $docNo])) {
            throw new AppError('رقم السند «' . $docNo . '» مستخدم قبل كده.');
        }

        // التوزيع على الفواتير
        $alloc = []; $left = $amount;
        $mode = $d['alloc'] ?? 'auto';
        if ($mode === 'invoice') {
            $inv = db_one("SELECT * FROM {$ic['table']} WHERE id=? AND {$ic['party_col']}=? AND status='active' FOR UPDATE", [(int)($d['invoice_id'] ?? 0), $party['id']]);
            if (!$inv) { throw new AppError('اختر فاتورة صحيحة تخص نفس الحساب.'); }
            $rem = round((float)$inv['total'] - (float)$inv['paid_amount'], 2);
            if ($amount > $rem + 0.005) { throw new AppError('المبلغ أكبر من المتبقي على الفاتورة (' . money($rem) . '). اختر «دفعة على الحساب» للزيادة.'); }
            $alloc[] = [(int)$inv['id'], $amount]; $left = 0;
        } elseif ($mode === 'auto') {
            $rows = db_all("SELECT id, total, paid_amount FROM {$ic['table']} WHERE {$ic['party_col']}=? AND status='active' AND total - paid_amount > 0.005
                            ORDER BY COALESCE(due_date, invoice_date), invoice_date, id FOR UPDATE", [$party['id']]);
            foreach ($rows as $r) {
                if ($left <= 0.004) { break; }
                $take = min($left, round((float)$r['total'] - (float)$r['paid_amount'], 2));
                if ($take > 0) { $alloc[] = [(int)$r['id'], $take]; $left = round($left - $take, 2); }
            }
        } elseif ($mode !== 'none') {
            throw new AppError('طريقة التوزيع غير صحيحة.');
        }

        $vid = db_insert('vouchers', [
            'voucher_type' => $type, 'voucher_date' => $date, 'doc_no' => $docNo,
            $pc['fk'] => $party['id'], 'amount' => $amount, 'account_id' => $acc['id'], 'payment_method' => $method,
            'notes' => trim((string)($d['notes'] ?? '')) ?: null, 'status' => 'active',
            'created_by' => current_user()['id'] ?: null, 'created_at' => now(),
        ]);
        foreach ($alloc as [$invId, $amt]) {
            db_insert('voucher_allocations', ['voucher_id' => $vid, 'invoice_id' => $invId, 'amount' => $amt]);
            db_exec("UPDATE {$ic['table']} SET paid_amount = paid_amount + ? WHERE id=?", [$amt, $invId]);
        }
        ledger_add($partyType, (int)$party['id'], ['entry_date' => $date, 'doc_type' => 'payment', 'doc_no' => $docNo,
            'payment_amount' => $amount, 'ref_type' => 'voucher', 'ref_id' => $vid,
            'notes' => ($isReceipt ? 'سند قبض' : 'سند صرف') . ' — ' . $method]);
        treasury_add([
            'account_id' => $acc['id'], 'txn_date' => $date, 'doc_no' => $docNo,
            'category' => $isReceipt ? 'تحصيل عميل' : 'شراء بضاعة',
            'description' => ($isReceipt ? 'تحصيل من العميل ' : 'سداد للمورد ') . $party['name'],
            'receipt_amount' => $isReceipt ? $amount : 0, 'payment_amount' => $isReceipt ? 0 : $amount,
            'payment_method' => $method, 'ref_type' => 'voucher', 'ref_id' => $vid, 'notes' => trim((string)($d['notes'] ?? '')) ?: null,
        ]);
        audit('create', 'vouchers', $vid, ($isReceipt ? 'سند قبض ' : 'سند صرف ') . "$docNo — {$party['name']} — " . money($amount));
        return $vid;
    });
}

function cancel_voucher(int $id): void
{
    db_tx(function () use ($id) {
        $v = db_one('SELECT * FROM vouchers WHERE id=? FOR UPDATE', [$id]);
        if (!$v) { throw new AppError('السند غير موجود.'); }
        if ($v['status'] === 'cancelled') { throw new AppError('السند ملغي بالفعل.'); }
        $isReceipt = $v['voucher_type'] === 'receipt';
        $ic = inv_cfg($isReceipt ? 'sale' : 'purchase');
        $partyType = $isReceipt ? 'customer' : 'supplier';
        $pc = party_cfg($partyType);
        foreach (db_all('SELECT * FROM voucher_allocations WHERE voucher_id=?', [$id]) as $a) {
            db_exec("UPDATE {$ic['table']} SET paid_amount = GREATEST(0, paid_amount - ?) WHERE id=?", [$a['amount'], $a['invoice_id']]);
        }
        db_exec('DELETE FROM voucher_allocations WHERE voucher_id=?', [$id]);
        db_exec("DELETE FROM {$pc['ledger']} WHERE ref_type='voucher' AND ref_id=?", [$id]);
        treasury_delete_by_ref('voucher', $id);
        db_exec("UPDATE vouchers SET status='cancelled', cancelled_at=? WHERE id=?", [now(), $id]);
        audit('cancel', 'vouchers', $id, ($isReceipt ? 'سند قبض ' : 'سند صرف ') . $v['doc_no']);
    });
}

// ════════════════════════════════════════════════════════════════════════════
//  إنشاء/تعديل الأصناف والأطراف
// ════════════════════════════════════════════════════════════════════════════
function save_party(string $partyType, array $d, ?int $id = null): int
{
    $c = party_cfg($partyType);
    return (int)db_tx(function () use ($c, $partyType, $d, $id) {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') { throw new AppError('اسم ' . $c['label'] . ' مطلوب.'); }
        if (db_val("SELECT id FROM {$c['table']} WHERE name=? AND id<>?", [$name, (int)$id])) { throw new AppError('الاسم مسجّل قبل كده.'); }
        $days = (int)($d['credit_days'] ?? 0);
        if ($days < 0 || $days > 3650) { throw new AppError('مدة السداد غير صحيحة.'); }
        $data = [
            'name' => mb_substr($name, 0, 190), 'contact_person' => mb_substr(trim((string)($d['contact_person'] ?? '')), 0, 120) ?: null,
            'phone' => mb_substr(trim((string)($d['phone'] ?? '')), 0, 40) ?: null, 'address' => mb_substr(trim((string)($d['address'] ?? '')), 0, 300) ?: null,
            'tax_no' => mb_substr(trim((string)($d['tax_no'] ?? '')), 0, 40) ?: null, 'credit_days' => $days,
            'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 500) ?: null, 'is_active' => !empty($d['is_active']) ? 1 : 0,
        ];
        $isNew = !$id;
        if ($id) { db_update($c['table'], $id, $data); }
        else { $id = db_insert($c['table'], $data + ['created_at' => now()]); }
        $opening = parse_num($d['opening_balance'] ?? '0') ?? 0.0;
        party_set_opening($partyType, $id, $opening, parse_date($d['opening_date'] ?? '') ?: today());
        audit($isNew ? 'create' : 'update', $c['table'], $id, $name);
        return $id;
    });
}

function save_item(array $d, ?int $id = null): int
{
    return (int)db_tx(function () use ($d, $id) {
        $code = trim((string)($d['code'] ?? ''));
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') { throw new AppError('اسم الصنف مطلوب.'); }
        $unit = (string)($d['unit'] ?? '');
        if (!in_array($unit, UNITS, true)) { throw new AppError('اختر وحدة قياس من القائمة.'); }
        $reorder = parse_num($d['reorder_level'] ?? '0') ?? 0.0;
        if ($reorder < 0) { throw new AppError('حد إعادة الطلب غير صحيح.'); }
        if ($id === null && $code === '') { $code = 'ITM-' . str_pad((string)(1 + (int)db_val('SELECT COALESCE(MAX(id),0) FROM items')), 4, '0', STR_PAD_LEFT); }
        if ($code === '') { throw new AppError('كود الصنف مطلوب.'); }
        if (db_val('SELECT id FROM items WHERE code=? AND id<>?', [$code, (int)$id])) { throw new AppError('كود الصنف مستخدم لصنف آخر.'); }
        if ($id) {
            $old = db_one('SELECT unit FROM items WHERE id=?', [$id]);
            if ($old && $old['unit'] !== $unit && db_val('SELECT COUNT(*) FROM stock_movements WHERE item_id=?', [$id])) {
                throw new AppError('لا يمكن تغيير وحدة صنف عليه حركات — الوحدة لازم تفضل ثابتة للصنف.');
            }
        }
        $data = ['code' => mb_substr($code, 0, 40), 'name' => mb_substr($name, 0, 190), 'unit' => $unit, 'reorder_level' => round($reorder, 3),
                 'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 500) ?: null, 'is_active' => !empty($d['is_active']) ? 1 : 0];
        $isNew = !$id;
        if ($id) { db_update('items', $id, $data); }
        else { $id = db_insert('items', $data + ['created_at' => now()]); }
        $wh = (int)($d['warehouse_id'] ?? 0);
        if ($wh && db_val('SELECT id FROM warehouses WHERE id=?', [$wh]) && isset($d['opening_qty'])) {
            item_set_opening($id, $wh, parse_num($d['opening_qty']) ?? 0.0, parse_date($d['opening_date'] ?? '') ?: today());
        }
        audit($isNew ? 'create' : 'update', 'items', $id, "$code $name");
        return $id;
    });
}

/** نص الخطأ اللي يظهر للمستخدم: رسائل AppError زي ما هي، وأي خطأ تاني رسالة عامة + تسجيل في اللوج */
function user_error_message(Throwable $e): string
{
    if ($e instanceof AppError) { return $e->getMessage(); }
    error_log('[mb] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) == 1062) { return 'القيمة دي مسجّلة قبل كده (تكرار).'; }
    return DEBUG ? $e->getMessage() : 'حصل خطأ غير متوقع. حاول مرة أخرى، ولو تكرر بلّغ المسؤول.';
}


// ════════════════════════════════════════════════════════════════════════════
//  ترقية هيكل قاعدة البيانات تلقائياً (بدون phpMyAdmin)
// ════════════════════════════════════════════════════════════════════════════
function column_exists(string $table, string $column): bool
{
    return (int)db_val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) > 0;
}
function run_migrations(): void
{
    try {
        $v = (int)setting('schema_version', '1');
        if ($v >= SCHEMA_VERSION) { return; }
        if ($v < 2) {
            // رمز استرداد كلمة المرور لكل مستخدم
            if (!column_exists('users', 'recovery_hash')) { db_exec('ALTER TABLE users ADD COLUMN recovery_hash varchar(255) DEFAULT NULL'); }
            if (!column_exists('users', 'recovery_set_at')) { db_exec('ALTER TABLE users ADD COLUMN recovery_set_at datetime DEFAULT NULL'); }
            // الاسم الإنجليزي الجديد (لو لسه على القيمة القديمة الافتراضية)
            db_exec("UPDATE settings SET svalue='Maher El Bahery Import & Export' WHERE skey='company_name_en' AND svalue IN ('Maher El-Beheiry Import & Export','')");
        }
        if ($v < 3) {
            // دور «مدير حسابات»
            db_exec("ALTER TABLE users MODIFY role enum('admin','accounts_manager','accountant','warehouse','sales','purchases','viewer') NOT NULL DEFAULT 'viewer'");
        }
        set_setting('schema_version', (string)SCHEMA_VERSION);
    } catch (Throwable $e) {
        error_log('[mb] migration failed: ' . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  رمز استرداد كلمة المرور (للحالات اللي بتنسى فيها الباسوورد بدون إيميل)
// ════════════════════════════════════════════════════════════════════════════
function generate_recovery_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // من غير الحروف المتشابهة (O/0 و I/1)
    $parts = [];
    for ($g = 0; $g < 4; $g++) {
        $p = '';
        for ($i = 0; $i < 4; $i++) { $p .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $parts[] = $p;
    }
    return implode('-', $parts);
}
function normalize_recovery_code(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); }
/** يولّد رمز جديد للمستخدم ويرجّعه (النص الصريح بيظهر مرة واحدة فقط — المخزّن هاش) */
function set_recovery_code(int $userId): string
{
    $code = generate_recovery_code();
    db_exec('UPDATE users SET recovery_hash=?, recovery_set_at=? WHERE id=?', [password_hash(normalize_recovery_code($code), PASSWORD_DEFAULT), now(), $userId]);
    return $code;
}
/** يعرض الرمز للمستخدم مرة واحدة في الصفحة الجاية */
function show_recovery_once(string $code, string $forName): void
{
    $_SESSION['_recovery_notice'] = ['code' => $code, 'name' => $forName];
}

/** استرجاع كلمة المرور برمز الاسترداد. بيرجّع الرمز الجديد. بيستخدم نفس قفل المحاولات الخاطئة بتاع الدخول */
function reset_password_with_code(string $username, string $code, string $newPass, string $newPass2): string
{
    $generic = 'اسم المستخدم أو رمز الاسترداد غير صحيح.';
    $u = db_one('SELECT * FROM users WHERE username = ?', [$username]);
    if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
        throw new AppError('تم إيقاف المحاولات مؤقتاً. حاول بعد ' . max(1, (int)ceil((strtotime($u['locked_until']) - time()) / 60)) . ' دقيقة.');
    }
    $ok = $u && $u['recovery_hash'] && password_verify(normalize_recovery_code($code), $u['recovery_hash']);
    if (!$u || !$ok) {
        if (!$u || !$u['recovery_hash']) { password_verify('x', '$2y$10$SRXbUvqzagQyHnSyrUda.eY6PqBpTkh8XMKUSCP5KZ66WXk2WAbka'); }
        if ($u) {
            $fails = (int)$u['failed_logins'] + 1;
            db_exec('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?', [$fails >= 5 ? 0 : $fails, $fails >= 5 ? date('Y-m-d H:i:s', time() + 600) : null, $u['id']]);
        }
        usleep(300000);
        throw new AppError($generic);
    }
    if (!$u['is_active']) { throw new AppError('هذا الحساب موقوف. تواصل مع مدير النظام.'); }
    if (mb_strlen($newPass) < 8) { throw new AppError('كلمة المرور الجديدة 8 أحرف على الأقل.'); }
    if ($newPass !== $newPass2) { throw new AppError('تأكيد كلمة المرور غير مطابق.'); }
    return db_tx(function () use ($u, $newPass) {
        db_exec('UPDATE users SET password_hash=?, failed_logins=0, locked_until=NULL WHERE id=?', [password_hash($newPass, PASSWORD_DEFAULT), $u['id']]);
        $new = set_recovery_code((int)$u['id']);
        db_insert('audit_logs', ['user_id' => $u['id'], 'user_name' => $u['full_name'], 'action' => 'update', 'entity' => 'users', 'entity_id' => $u['id'],
            'details' => 'استرجاع كلمة المرور برمز الاسترداد', 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null, 'created_at' => now()]);
        return $new;
    });
}

// ════════════════════════════════════════════════════════════════════════════
//  عرض الرصيد بوضوح: «عليه كام» / «له كام»
// ════════════════════════════════════════════════════════════════════════════
/** [نص, كلاس لون] — للعميل: موجب = عليه (مستحق لنا) ؛ للمورد: موجب = له علينا */
function balance_phrase(string $partyType, float $bal): array
{
    if (abs($bal) < 0.005) { return ['لا يوجد رصيد', 'zero']; }
    if ($partyType === 'customer') { return $bal > 0 ? ['عليه ' . money($bal) . ' ' . CURRENCY, 'owes'] : ['له رصيد ' . money(-$bal) . ' ' . CURRENCY, 'credit']; }
    return $bal > 0 ? ['له علينا ' . money($bal) . ' ' . CURRENCY, 'owes'] : ['لنا عنده ' . money(-$bal) . ' ' . CURRENCY, 'credit'];
}

/** أكتر الأصناف مبيعاً (بالكمية) في فترة */
function top_selling_items(?string $from, ?string $to, int $limit = 5): array
{
    $where = "s.status='active'"; $params = [];
    if ($from) { $where .= ' AND s.invoice_date >= ?'; $params[] = $from; }
    if ($to)   { $where .= ' AND s.invoice_date <= ?'; $params[] = $to; }
    return db_all("SELECT it.id, it.name, it.unit, SUM(si.qty) AS qty_sold, SUM(si.line_total) AS revenue, COUNT(DISTINCT s.id) AS invoices
                   FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items it ON it.id = si.item_id
                   WHERE $where GROUP BY it.id, it.name, it.unit ORDER BY qty_sold DESC, revenue DESC LIMIT " . (int)$limit, $params);
}

// ════════════════════════════════════════════════════════════════════════════
//  المصروفات من الفواتير + الفواتير المتأخرة
// ════════════════════════════════════════════════════════════════════════════
/** مصروفات الفواتير فقط: النقل + الرسوم الأخرى في فواتير المشتريات الفعّالة (المشتريات نفسها بتتحسب بالإجمالي لوحدها) */
function invoice_expenses(?string $from, ?string $to): array
{
    $where = "status='active'"; $params = [];
    if ($from) { $where .= ' AND invoice_date >= ?'; $params[] = $from; }
    if ($to)   { $where .= ' AND invoice_date <= ?'; $params[] = $to; }
    $r = db_one("SELECT COALESCE(SUM(transport),0) AS transport, COALESCE(SUM(other_fees),0) AS other_fees FROM purchases WHERE $where", $params) ?: [];
    $t = (float)($r['transport'] ?? 0); $o = (float)($r['other_fees'] ?? 0);
    return ['transport' => $t, 'other_fees' => $o, 'total' => $t + $o];
}

/** الفواتير المتأخرة (مبيعات + مشتريات) الأقدم تأخيراً أولاً */
function overdue_invoices(int $limit = 8, bool $sales = true, bool $purchases = true): array
{
    $parts = [];
    if ($sales)     { $parts[] = "SELECT 'sales' AS kind, s.id, s.doc_no, s.due_date, s.total - s.paid_amount AS remaining, c.id AS party_id, c.name AS party_name FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.status='active' AND s.total - s.paid_amount > 0.005 AND s.due_date < CURDATE()"; }
    if ($purchases) { $parts[] = "SELECT 'purchases' AS kind, p.id, p.doc_no, p.due_date, p.total - p.paid_amount AS remaining, su.id AS party_id, su.name AS party_name FROM purchases p JOIN suppliers su ON su.id=p.supplier_id WHERE p.status='active' AND p.total - p.paid_amount > 0.005 AND p.due_date < CURDATE()"; }
    if (!$parts) { return []; }
    $rows = db_all('SELECT *, DATEDIFF(CURDATE(), due_date) AS days_late FROM (' . implode(' UNION ALL ', $parts) . ') x ORDER BY days_late DESC, remaining DESC LIMIT ' . max(1, $limit));
    foreach ($rows as &$r) { $r['days_late'] = (int)$r['days_late']; $r['remaining'] = (float)$r['remaining']; } unset($r);
    return $rows;
}
