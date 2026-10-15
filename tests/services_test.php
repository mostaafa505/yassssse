<?php
/**
 * اختبارات منطق المحاسبة والمخزون:   php tests/services_test.php
 * بتستخدم قاعدة بيانات اختبار منفصلة (tests/env.test.php) وبتمسحها وتبنيها من جديد في كل تشغيل.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
putenv('MB_ENV_FILE=' . __DIR__ . '/env.test.php');
require_once dirname(__DIR__) . '/htdocs/includes/bootstrap.php';

// ── إعادة بناء قاعدة الاختبار ──
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(dirname(__DIR__) . '/htdocs/database/schema.sql'));
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $s) { $pdo->exec($s); }
db_exec("INSERT INTO users (username, full_name, password_hash, role, is_active, created_at) VALUES ('t','مختبر','x','admin',1,NOW())");
$_SESSION['user'] = ['id' => 1, 'full_name' => 'مختبر', 'role' => 'admin', 'modules' => []];
db_exec("INSERT INTO warehouses (name, is_default, is_active) VALUES ('الرئيسي',1,1),('الفرعي',0,1)");
db_exec("INSERT INTO treasury_accounts (name, acc_type, opening_balance, is_active) VALUES ('الخزينة','cash',1000,1),('البنك','bank',0,1)");

$pass = 0; $fail = 0;
function ok(bool $cond, string $name): void { global $pass, $fail; if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $fail++; echo "  ✗ FAIL: $name\n"; } }
function eqf($a, float $b, string $name): void { $a = (float)$a; ok(abs($a - $b) < 0.005, "$name (المتوقع " . round($b, 3) . "، الفعلي " . round($a, 3) . ')'); }
function throws(callable $fn, string $needle, string $name): void
{
    try { $fn(); ok(false, "$name — لم يرمِ خطأ"); }
    catch (AppError $e) { ok($needle === '' || str_contains($e->getMessage(), $needle), "$name [{$e->getMessage()}]"); }
    catch (Throwable $e) { ok(false, "$name — خطأ غير متوقع: " . $e->getMessage()); }
}

echo "▶ الأصناف والأطراف\n";
$flour = save_item(['name' => 'دقيق', 'unit' => 'شيكارة', 'reorder_level' => '20', 'warehouse_id' => 1, 'opening_qty' => '50', 'is_active' => 1]);
$rice  = save_item(['name' => 'أرز', 'unit' => 'طن', 'reorder_level' => '2', 'warehouse_id' => 1, 'opening_qty' => '0', 'is_active' => 1]);
eqf(stock_balance($flour, 1), 50, 'رصيد أول المدة للدقيق');
ok(db_val('SELECT code FROM items WHERE id=?', [$flour]) === 'ITM-0001', 'كود تلقائي للصنف');
throws(fn() => save_item(['name' => 'x', 'unit' => 'متر']), 'وحدة', 'وحدة خارج القائمة مرفوضة');
$sup = save_party('supplier', ['name' => 'مورد الدقيق', 'credit_days' => 30, 'opening_balance' => '500', 'is_active' => 1]);
$cus = save_party('customer', ['name' => 'عميل الجملة', 'credit_days' => 15, 'opening_balance' => '0', 'is_active' => 1]);
throws(fn() => save_party('customer', ['name' => 'عميل الجملة']), 'مسجّل', 'منع تكرار اسم العميل');
eqf(party_balance('supplier', $sup), 500, 'رصيد المورد الافتتاحي');
eqf(party_opening('supplier', $sup), 500, 'قراءة الرصيد الافتتاحي');

echo "▶ فاتورة مشتريات (جزئي)\n";
$lines = normalize_lines(['item_id' => [$flour, $rice], 'qty' => ['100', '3'], 'unit_price' => ['200', '5000']]);
// الأصناف 20000 + 15000 = 35000 − خصم 1000 + نقل 500 + ضريبة 4760 + رسوم 240 = 39500
$p1 = post_invoice('purchase', ['invoice_date' => '2026-10-01', 'doc_no' => 'SUP-001', 'party_id' => $sup, 'warehouse_id' => 1,
    'discount' => 1000, 'transport' => 500, 'vat' => 4760, 'other_fees' => 240, 'paid_amount' => 500, 'account_id' => 1, 'payment_method' => 'نقدي'], $lines);
$inv = db_one('SELECT * FROM purchases WHERE id=?', [$p1]);
eqf($inv['total'], 39500, 'إجمالي الفاتورة بنفس معادلة الإكسل');
ok($inv['due_date'] === '2026-10-31', 'تاريخ الاستحقاق = تاريخ الفاتورة + مدة السداد (30 يوم)');
eqf(stock_balance($flour, 1), 150, 'المخزون زاد بالمشتريات (50+100)');
eqf(stock_balance($rice, 1), 3, 'رصيد الأرز');
eqf(party_balance('supplier', $sup), 500 + 39500 - 500, 'رصيد المورد = افتتاحي + فاتورة − مدفوع');
eqf(treasury_balance(1), 500, 'رصيد الخزينة نقص بالمدفوع (1000−500)');
ok(payment_status(39500, 500) === 'partial', 'حالة السداد: جزئي');
ok(payment_status(100, 0) === 'credit' && payment_status(100, 100) === 'paid', 'حالات السداد: آجل / مدفوع');
throws(fn() => post_invoice('purchase', ['invoice_date' => '2026-10-02', 'doc_no' => 'SUP-001', 'party_id' => $sup, 'warehouse_id' => 1], $lines), 'منع تكرار', 'منع تكرار رقم فاتورة المورد');
throws(fn() => post_invoice('purchase', ['invoice_date' => '2026-10-02', 'doc_no' => 'SUP-X', 'party_id' => $sup, 'warehouse_id' => 1, 'paid_amount' => 999999999, 'account_id' => 1, 'payment_method' => 'نقدي'], $lines), 'أكبر من إجمالي', 'منع دفع أكتر من الإجمالي');
throws(fn() => post_invoice('purchase', ['invoice_date' => '2026-10-02', 'doc_no' => 'SUP-Y', 'party_id' => $sup, 'warehouse_id' => 1, 'paid_amount' => 5000, 'account_id' => 1, 'payment_method' => 'نقدي'], $lines), 'لا يكفي', 'منع الدفع من خزينة رصيدها لا يكفي');
throws(fn() => post_invoice('purchase', ['invoice_date' => '2026-10-02', 'doc_no' => '', 'party_id' => $sup, 'warehouse_id' => 1], $lines), 'مستند', 'منع فاتورة مشتريات بدون رقم مستند');
throws(fn() => post_invoice('purchase', ['invoice_date' => '2026-10-02', 'doc_no' => 'Z', 'party_id' => $sup, 'warehouse_id' => 1, 'discount' => 999999], $lines), 'الخصم', 'منع خصم أكبر من قيمة الأصناف');
eqf(db_val('SELECT COUNT(*) FROM purchases'), 1, 'المعاملات الفاشلة ما سابتش فواتير ناقصة');
eqf(stock_balance($flour, 1), 150, 'ولا حركات مخزون');

echo "▶ سند صرف للمورد (توزيع تلقائي)\n";
$v1 = post_voucher('payment', ['voucher_date' => '2026-10-05', 'party_id' => $sup, 'amount' => '300', 'account_id' => 1, 'payment_method' => 'نقدي', 'alloc' => 'auto']);
eqf(db_val('SELECT paid_amount FROM purchases WHERE id=?', [$p1]), 800, 'السند اتوزع على الفاتورة (500+300)');
eqf(treasury_balance(1), 200, 'رصيد الخزينة بعد السند');
eqf(party_balance('supplier', $sup), 39500 + 500 - 800, 'رصيد المورد بعد السند');
throws(fn() => cancel_invoice('purchase', $p1), 'سندات مرتبطة', 'لا يمكن إلغاء فاتورة عليها سندات');
cancel_voucher($v1);
eqf(db_val('SELECT paid_amount FROM purchases WHERE id=?', [$p1]), 500, 'إلغاء السند رجّع المدفوع');
eqf(treasury_balance(1), 500, 'إلغاء السند رجّع الخزينة');
throws(fn() => cancel_voucher($v1), 'ملغي بالفعل', 'منع إلغاء السند مرتين');

echo "▶ فاتورة مبيعات + مخزون\n";
$sl = normalize_lines(['item_id' => [$flour], 'qty' => ['40'], 'unit_price' => ['300']]);
$s1 = post_invoice('sale', ['invoice_date' => '2026-10-06', 'party_id' => $cus, 'warehouse_id' => 1, 'discount' => 200, 'paid_amount' => 2000, 'account_id' => 1, 'payment_method' => 'نقدي'], $sl);
$sale = db_one('SELECT * FROM sales WHERE id=?', [$s1]);
ok(str_starts_with($sale['doc_no'], 'INV-'), 'ترقيم تلقائي لفاتورة البيع: ' . $sale['doc_no']);
eqf($sale['total'], 11800, 'إجمالي البيع 12000 − 200');
eqf(stock_balance($flour, 1), 110, 'المخزون نقص بالبيع');
eqf(party_balance('customer', $cus), 11800 - 2000, 'رصيد العميل');
eqf(treasury_balance(1), 2500, 'الخزينة زادت بالتحصيل المقدم');
throws(fn() => post_invoice('sale', ['invoice_date' => '2026-10-06', 'party_id' => $cus, 'warehouse_id' => 1], normalize_lines(['item_id' => [$flour], 'qty' => ['1000'], 'unit_price' => ['1']])), 'لا يكفي', 'منع البيع بأكتر من رصيد المخزن');
throws(fn() => post_invoice('sale', ['invoice_date' => '2026-10-06', 'party_id' => $cus, 'warehouse_id' => 2], $sl), 'لا يكفي', 'منع البيع من مخزن رصيده صفر');
throws(fn() => normalize_lines(['item_id' => [$flour], 'qty' => ['0'], 'unit_price' => ['5']]), 'الكمية', 'منع كمية صفر');

echo "▶ سند قبض من العميل\n";
$r1 = post_voucher('receipt', ['voucher_date' => '2026-10-07', 'party_id' => $cus, 'amount' => '4000', 'account_id' => 2, 'payment_method' => 'تحويل بنكي', 'alloc' => 'invoice', 'invoice_id' => $s1]);
eqf(db_val('SELECT paid_amount FROM sales WHERE id=?', [$s1]), 6000, 'تخصيص على فاتورة محددة');
throws(fn() => post_voucher('receipt', ['voucher_date' => '2026-10-07', 'party_id' => $cus, 'amount' => '9999', 'account_id' => 2, 'payment_method' => 'نقدي', 'alloc' => 'invoice', 'invoice_id' => $s1]), 'أكبر من المتبقي', 'منع تخصيص أكبر من المتبقي');
$r2 = post_voucher('receipt', ['voucher_date' => '2026-10-08', 'party_id' => $cus, 'amount' => '1000', 'account_id' => 1, 'payment_method' => 'نقدي', 'alloc' => 'none']);
eqf(db_val('SELECT paid_amount FROM sales WHERE id=?', [$s1]), 6000, 'دفعة على الحساب ما بتغيرش الفاتورة');
eqf(party_balance('customer', $cus), 11800 - 2000 - 4000 - 1000, 'رصيد العميل بعد السندات');
eqf(treasury_balance(2), 4000, 'رصيد البنك بعد التحصيل');
$stm = party_statement('customer', $cus);
eqf($stm['closing'], party_balance('customer', $cus), 'كشف الحساب: الرصيد الختامي = رصيد الحساب');
eqf($stm['rows'][0]['prev_balance'], 0, 'كشف الحساب: الرصيد السابق لأول حركة');
eqf($stm['rows'][1]['prev_balance'], $stm['rows'][0]['balance'], 'كشف الحساب: الرصيد السابق = الرصيد المتبقي للحركة قبلها');
$stm2 = party_statement('customer', $cus, '2026-10-07', null);
eqf($stm2['opening'], 11800 - 2000, 'كشف بفترة: الرصيد السابق يشمل ما قبل الفترة (فاتورة 10/6 − مقدم)');

echo "▶ إلغاء فاتورة بيع\n";
throws(fn() => cancel_invoice('sale', $s1), 'سندات مرتبطة', 'فاتورة عليها سند قبض مرتبط');
cancel_voucher($r1);
cancel_invoice('sale', $s1);
eqf(stock_balance($flour, 1), 150, 'المخزون رجع بعد إلغاء البيع');
eqf(party_balance('customer', $cus), -2000 - 1000, 'العميل عليه رصيد دائن بما دفعه (مقدم + على الحساب)');
eqf(treasury_balance(1), 3500, 'الخزينة ما رجّعتش المقبوض (الفلوس اتقبضت فعلاً)');
ok(db_val('SELECT status FROM sales WHERE id=?', [$s1]) === 'cancelled', 'حالة الفاتورة ملغاة');
throws(fn() => cancel_invoice('sale', $s1), 'ملغاة بالفعل', 'منع إلغاء الفاتورة مرتين');

echo "▶ إلغاء مشتريات يسبب رصيد سالب\n";
$s2 = post_invoice('sale', ['invoice_date' => '2026-10-09', 'party_id' => $cus, 'warehouse_id' => 1], normalize_lines(['item_id' => [$flour], 'qty' => ['140'], 'unit_price' => ['300']]));
eqf(party_balance('customer', $cus), -3000 + 42000, 'رصيد العميل بعد فاتورة آجلة');
throws(fn() => cancel_invoice('purchase', $p1), 'لا يكفي', 'لا يمكن إلغاء مشتريات تم بيع بضاعتها');

echo "▶ الخزينة: مصروفات / تحويل بنكي / رصيد سالب\n";
$before = treasury_balance(1);
$t1 = treasury_manual_save(['txn_date' => '2026-10-10', 'doc_no' => 'EXP-1', 'description' => 'إيجار المخزن', 'category' => 'إيجار', 'direction' => 'payment', 'amount' => '800', 'account_id' => 1, 'payment_method' => 'نقدي']);
eqf(treasury_balance(1), $before - 800, 'مصروف إيجار');
throws(fn() => treasury_manual_save(['txn_date' => '2026-10-10', 'doc_no' => '', 'description' => 'x', 'category' => 'إيجار', 'direction' => 'payment', 'amount' => '1', 'account_id' => 1, 'payment_method' => 'نقدي']), 'مستند', 'منع حركة بدون رقم مستند');
throws(fn() => treasury_manual_save(['txn_date' => '2026-10-10', 'doc_no' => 'X1', 'description' => 'x', 'category' => 'تحصيل عميل', 'direction' => 'receipt', 'amount' => '1', 'account_id' => 1, 'payment_method' => 'نقدي']), 'غير مسموح', 'منع تصنيف تحصيل عميل اليدوي');
throws(fn() => treasury_manual_save(['txn_date' => '2026-10-10', 'doc_no' => 'X2', 'description' => 'x', 'category' => 'مرتبات', 'direction' => 'payment', 'amount' => '99999', 'account_id' => 1, 'payment_method' => 'نقدي']), 'لا يكفي', 'منع مصروف أكبر من رصيد الخزينة');
treasury_manual_save(['txn_date' => '2026-10-10', 'doc_no' => 'EXP-1', 'description' => 'إيجار المخزن (معدّل)', 'category' => 'إيجار', 'direction' => 'payment', 'amount' => '900', 'account_id' => 1, 'payment_method' => 'نقدي'], $t1);
eqf(treasury_balance(1), $before - 900, 'تعديل المصروف');
$tr = treasury_transfer(['operation' => 'deposit', 'txn_date' => '2026-10-10', 'doc_no' => 'DEP-1', 'amount' => '1000', 'cash_account_id' => 1, 'bank_account_id' => 2, 'payment_method' => 'تحويل بنكي']);
eqf(treasury_balance(2), 1000, 'إيداع بنكي زاد البنك (سند القبض ملغي)');
eqf(treasury_balance(1), $before - 900 - 1000, 'إيداع بنكي نقّص الخزينة');
throws(fn() => treasury_manual_delete($tr), 'مرتبطة', 'حركة التحويل ما تتحذفش منفردة');
treasury_transfer_delete($tr);
eqf(treasury_balance(2), 0, 'حذف الإيداع رجّع البنك');
treasury_manual_delete($t1);
eqf(treasury_balance(1), $before, 'حذف المصروف رجّع الخزينة');
$big = treasury_manual_save(['txn_date' => '2026-10-10', 'doc_no' => 'EXP-2', 'description' => 'مرتبات', 'category' => 'مرتبات', 'direction' => 'payment', 'amount' => '3000', 'account_id' => 1, 'payment_method' => 'نقدي']);
throws(fn() => cancel_voucher($r2), 'سيصبح سالباً', 'منع إلغاء سند قبض لو هيخلي الخزينة سالبة');
treasury_manual_delete($big);
eqf(party_balance('customer', $cus), 39000, 'رصيد العميل لم يتغير بعد محاولة الإلغاء الفاشلة');

echo "▶ إشعارات الخصم/الإضافة\n";
$n1 = ledger_note('customer', $cus, ['entry_date' => '2026-10-10', 'doc_no' => 'CN-1', 'amount' => '500', 'kind' => 'discount', 'notes' => 'مرتجع']);
eqf(party_balance('customer', $cus), 39000 - 500, 'إشعار خصم نقّص الرصيد');
ledger_note_delete('customer', $n1);
eqf(party_balance('customer', $cus), 39000, 'حذف الإشعار');

echo "▶ المخزون: عمليات يدوية وتحويل\n";
$before = stock_balance($flour, 1);
stock_operation(['operation' => 'receipt', 'move_date' => '2026-10-10', 'item_id' => $rice, 'warehouse_id' => 1, 'qty' => '2']);
eqf(stock_balance($rice, 1), 5, 'إذن استلام');
throws(fn() => stock_operation(['operation' => 'issue', 'move_date' => '2026-10-10', 'item_id' => $rice, 'warehouse_id' => 1, 'qty' => '50']), 'لا يكفي', 'منع صرف أكبر من الرصيد');
throws(fn() => stock_operation(['operation' => 'adj_out', 'move_date' => '2026-10-10', 'item_id' => $rice, 'warehouse_id' => 1, 'qty' => '1']), 'سبب', 'التسوية تتطلب سبب');
stock_operation(['operation' => 'adj_out', 'move_date' => '2026-10-10', 'item_id' => $rice, 'warehouse_id' => 1, 'qty' => '1', 'reason' => 'تلف']);
eqf(stock_balance($rice, 1), 4, 'تسوية تلف');
$tid = stock_operation(['operation' => 'transfer', 'move_date' => '2026-10-10', 'item_id' => $rice, 'warehouse_id' => 1, 'to_warehouse_id' => 2, 'qty' => '3']);
eqf(stock_balance($rice, 1), 1, 'تحويل: المصدر');
eqf(stock_balance($rice, 2), 3, 'تحويل: الوجهة');
throws(fn() => stock_operation(['operation' => 'transfer', 'move_date' => '2026-10-10', 'item_id' => $rice, 'warehouse_id' => 1, 'to_warehouse_id' => 1, 'qty' => '1']), 'يختلف', 'منع التحويل لنفس المخزن');
stock_operation_delete($tid);
eqf(stock_balance($rice, 1), 4, 'حذف التحويل رجّع المصدر');
eqf(stock_balance($rice, 2), 0, 'حذف التحويل شال الوجهة');
$pm = (int)db_val("SELECT id FROM stock_movements WHERE ref_type='purchase' LIMIT 1");
throws(fn() => stock_operation_delete($pm), 'مستند', 'حركة ناتجة عن فاتورة ما تتحذفش يدوياً');

echo "▶ ملخص أرصدة المخازن (منطق شيت المخازن)\n";
$sum = array_column(stock_summary(), null, 'id');
eqf($sum[$flour]['opening_qty'], 50, 'ملخص: رصيد أول المدة');
eqf($sum[$flour]['in_qty'], 100, 'ملخص: الوارد');
eqf($sum[$flour]['out_qty'], 140, 'ملخص: المنصرف (فاتورة ملغاة لا تُحسب)');
eqf($sum[$flour]['current_qty'], 10, 'ملخص: الرصيد الحالي = أول + وارد − منصرف');
ok($sum[$flour]['stock_status'] === 'reorder', 'حالة المخزون: اطلب بضاعة (10 ≤ 20)');
ok($sum[$rice]['stock_status'] === 'ok', 'حالة المخزون: متوفر');
$sumPeriod = array_column(stock_summary(null, '2026-10-08', '2026-10-31'), null, 'id');
eqf($sumPeriod[$flour]['opening_qty'], 150, 'ملخص بفترة: أول المدة يشمل ما قبل الفترة (50+100)');
eqf($sumPeriod[$flour]['out_qty'], 140, 'ملخص بفترة: منصرف الفترة');
$sumWh2 = array_column(stock_summary(2), null, 'id');
eqf($sumWh2[$flour]['current_qty'], 0, 'ملخص لمخزن آخر');

echo "▶ حالة الحساب (نشط / متأخر / مغلق)\n";
$lateCus = save_party('customer', ['name' => 'عميل متأخر', 'credit_days' => 10, 'is_active' => 1]);
$pi = post_invoice('purchase', ['invoice_date' => '2026-01-01', 'doc_no' => 'OLD-1', 'party_id' => $sup, 'warehouse_id' => 1], normalize_lines(['item_id' => [$rice], 'qty' => ['1'], 'unit_price' => ['10']]));
post_invoice('sale', ['invoice_date' => '2026-01-01', 'party_id' => $lateCus, 'warehouse_id' => 1], normalize_lines(['item_id' => [$rice], 'qty' => ['1'], 'unit_price' => ['10']]));
$rows = array_column(party_summaries('customer'), null, 'id');
ok($rows[$lateCus]['status_key'] === 'late', 'عميل فاتورته فات استحقاقها = متأخر');
ok($rows[$cus]['status_key'] === 'active', 'عميل فاتورته لسه في ميعادها = نشط');
post_voucher('receipt', ['voucher_date' => '2026-10-10', 'party_id' => $lateCus, 'amount' => '10', 'account_id' => 1, 'payment_method' => 'نقدي', 'alloc' => 'auto']);
$rows = array_column(party_summaries('customer'), null, 'id');
ok($rows[$lateCus]['status_key'] === 'closed', 'بعد السداد الكامل = مغلق');

echo "▶ ملخصات الأطراف وعبارة الرصيد وأكتر منتج\n";
$sumC = array_column(party_summaries('customer'), null, 'id');
$led = db_one("SELECT COALESCE(SUM(CASE WHEN doc_type<>'opening' THEN invoice_amount END),0) AS inv, COALESCE(SUM(payment_amount),0) AS pd FROM customer_ledger WHERE customer_id=?", [$cus]);
eqf($sumC[$cus]['total_invoiced'], (float)$led['inv'], 'إجمالي فواتير العميل = مجموع قيود الفواتير');
eqf($sumC[$cus]['total_paid'], (float)$led['pd'], 'إجمالي المدفوع = مجموع قيود الدفع');
eqf($sumC[$cus]['balance'], (float)$led['inv'] - (float)$led['pd'], 'الرصيد = الفواتير − المدفوع (بدون افتتاحي)');
ok($sumC[$cus]['next_due'] !== null, 'أقرب موعد استحقاق موجود للعميل ذو فاتورة مفتوحة');
[$t1, $c1] = balance_phrase('customer', 1500.5); ok($c1 === 'owes' && str_starts_with($t1, 'عليه'), 'عبارة العميل المدين: ' . $t1);
[$t2, $c2] = balance_phrase('customer', -20); ok($c2 === 'credit' && str_starts_with($t2, 'له'), 'عبارة العميل الدائن: ' . $t2);
[$t3, $c3] = balance_phrase('supplier', 300); ok($c3 === 'owes' && str_starts_with($t3, 'له علينا'), 'عبارة المورد: ' . $t3);
[$t4, $c4] = balance_phrase('customer', 0); ok($c4 === 'zero', 'رصيد صفر');
$top = top_selling_items(null, null, 3);
ok(count($top) >= 1 && $top[0]['name'] === 'دقيق', 'أكتر منتج مبيعاً = الدقيق (' . ($top[0]['name'] ?? '?') . ')');
eqf($top[0]['qty_sold'], (float)db_val("SELECT SUM(si.qty) FROM sale_items si JOIN sales s ON s.id=si.sale_id AND s.status='active' WHERE si.item_id=?", [$flour]), 'كمية الأكثر مبيعاً تستبعد الفواتير الملغاة');
$none = top_selling_items('2000-01-01', '2000-01-02'); ok($none === [], 'فترة بدون مبيعات = قائمة فاضية');

echo "▶ رمز الاسترداد (خدمات)\n";
$cd = generate_recovery_code(); ok(preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $cd) === 1, 'شكل الرمز: ' . $cd);
$code = set_recovery_code(1);
ok(password_verify(normalize_recovery_code($code), db_val('SELECT recovery_hash FROM users WHERE id=1')), 'الرمز مخزّن كهاش ويتطابق');
ok(db_val('SELECT recovery_hash FROM users WHERE id=1') !== $code, 'الرمز مش مخزّن كنص صريح');
$n2 = reset_password_with_code('t', strtolower($code), 'Newer@123456', 'Newer@123456');
ok($n2 !== $code, 'الاسترجاع بيطلّع رمز جديد');
throws(fn() => reset_password_with_code('t', $code, 'Newer@123456', 'Newer@123456'), 'غير صحيح', 'الرمز القديم مرفوض');
throws(fn() => reset_password_with_code('nobody', $code, 'Newer@123456', 'Newer@123456'), 'غير صحيح', 'مستخدم غير موجود: نفس الرسالة العامة');
db_exec('UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=1');

echo "▶ الصلاحيات حسب الدور\n";
$allMods = array_keys(MODULES);
ok(role_modules('admin') === $allMods, 'المدير: كل الوحدات');
ok(!array_diff(array_diff($allMods, ['users']), role_modules('accountant')), 'المحاسب: كل وحدات الأعمال (' . count(role_modules('accountant')) . ')');
ok(role_modules('accounts_manager') === $allMods, 'مدير الحسابات: كل الوحدات بما فيها المستخدمين');
ok(role_modules('warehouse') === ['items', 'stock'], 'أمين المخزن: الأصناف والمخزن فقط');
ok(!in_array('users', role_modules('accountant'), true), 'إدارة المستخدمين خارج وحدات المحاسب');

echo "▶ كشف الحساب: الرصيد الافتتاحي\n";
$stm = party_statement('supplier', $sup);
ok($stm['rows'][0]['doc_type'] === 'opening', 'الرصيد الافتتاحي أول سطر حتى لو تاريخه بعد الفواتير');
eqf($stm['rows'][0]['prev_balance'], 0, 'الرصيد السابق للافتتاحي = صفر');
$stm2 = party_statement('supplier', $sup, '2026-10-02', null);
$inP = array_sum(array_column($stm2['rows'], 'invoice_amount')); $outP = array_sum(array_column($stm2['rows'], 'payment_amount'));
eqf($stm2['closing'] - $inP + $outP, $stm2['opening'], 'كشف بفترة: الافتتاحي ضمن الرصيد السابق والإجماليات متسقة');
ok(!in_array('opening', array_column($stm2['rows'], 'doc_type'), true), 'كشف بفترة: لا يكرر سطر الافتتاحي');
eqf($stm2['closing'], party_balance('supplier', $sup), 'كشف بفترة مفتوحة النهاية: الرصيد الختامي = رصيد الحساب');

echo "▶ مصروفات الفواتير + الفواتير المتأخرة\n";
$expRaw = db_one("SELECT COALESCE(SUM(transport + other_fees),0) t FROM purchases WHERE status='active'");
eqf(invoice_expenses(null, null)['total'], (float)$expRaw['t'], 'مصروفات الفواتير = نقل + رسوم أخرى لفواتير المشتريات الفعّالة فقط');
$grossP = (float)db_val("SELECT COALESCE(SUM(total),0) FROM purchases WHERE status='active'");
ok($grossP >= invoice_expenses(null, null)['total'], 'إجمالي المشتريات (بالإجمالي) أكبر من أو يساوي مصروفات الفواتير');
eqf(invoice_expenses('2099-01-01', null)['total'], 0, 'مصروفات الفواتير في فترة فاضية = صفر');
$late = overdue_invoices(50);
$prev = PHP_INT_MAX; $sorted = true; foreach ($late as $l) { if ($l['days_late'] > $prev || $l['days_late'] < 1 || $l['remaining'] <= 0) { $sorted = false; } $prev = $l['days_late']; }
ok($sorted, 'الفواتير المتأخرة مرتبة بالأقدم تأخيراً وكلها فيها متبقي وتأخير فعلي');
ok(money(1234.5) === '1,234.50' && str_contains(egp(5), CURRENCY) && CURRENCY === 'ج.م', 'egp() بتضيف ج.م');

// نسيب بيانات الاختبار في القاعدة عشان فحص الشاشات (tests/smoke.sh) — كلمة مرور المستخدم t
db_exec('UPDATE users SET password_hash=?, failed_logins=0, locked_until=NULL WHERE id=1', [password_hash('Test@12345', PASSWORD_DEFAULT)]);

echo "\n══ النتيجة: $pass نجح، $fail فشل ══\n";
exit($fail ? 1 : 0);
