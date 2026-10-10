<?php
/**
 * اختبار من طرف لطرف عبر HTTP (فورمات حقيقية + CSRF + صلاحيات).
 * التشغيل:  php tests/services_test.php   (يجهّز قاعدة الاختبار)
 *           MB_ENV_FILE=tests/env.test.php php -S 127.0.0.1:8080 -t htdocs &
 *           php tests/e2e.php
 */
const BASE = 'http://127.0.0.1:8080';
$pass = 0; $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $n\n"; } else { $fail++; echo "  ✗ FAIL: $n\n"; } }

class Client {
    public string $jar; public string $lastUrl = '';
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
    function req(string $path, ?array $post = null): array {
        $ch = curl_init(BASE . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HEADER => false]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $r = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body, 'url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
        curl_close($ch);
        $this->lastUrl = $r['url'];
        return $r;
    }
    function token(string $path): string { $r = $this->req($path); preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m); return $m[1] ?? ''; }
    function post(string $formPath, array $data, ?string $tokenFrom = null): array { $data['csrf_token'] = $this->token($tokenFrom ?? $formPath); return $this->req($formPath, $data); }
}
function flashText(string $html): string { preg_match_all('/<div class="alert alert-[a-z]+ alert-dismissible[^>]*>\s*(.*?)<button/su', $html, $m); return implode(' | ', array_map('trim', $m[1] ?? [])); }

putenv('MB_ENV_FILE=' . __DIR__ . '/env.test.php');
$pdo = new PDO('mysql:host=localhost;dbname=maher_unit;charset=utf8mb4', 'maher', 'maher_pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$val = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();

echo "▶ تسجيل الدخول والحماية\n";
$c = new Client();
$r = $c->req('dashboard/');
ok(str_contains($c->lastUrl, 'auth/login.php'), 'زائر غير مسجل يتحول لصفحة الدخول');
$r = $c->post('auth/login.php', ['username' => 't', 'password' => 'wrong']);
ok(str_contains($r['body'], 'غير صحيحة'), 'كلمة مرور خاطئة ترفض');
$r = $c->req('auth/login.php', ['username' => 't', 'password' => 'Test@12345']);
ok($r['code'] === 419, 'POST بدون CSRF يترفض (419)');
$r = $c->post('auth/login.php', ['username' => 't', 'password' => 'Test@12345']);
ok(str_contains($c->lastUrl, 'dashboard/') && str_contains($r['body'], 'الرئيسية'), 'دخول ناجح للداشبورد');
ok(str_contains($r['body'], 'ماهر البحيري للاستيراد والتصدير'), 'اسم الشركة ظاهر');

echo "▶ إنشاء مورد وصنف وفاتورة مشتريات من الفورم\n";
$r = $c->post('suppliers/form.php', ['name' => 'مورد اختبار E2E', 'credit_days' => '20', 'opening_balance' => '0', 'is_active' => '1', 'opening_date' => '2026-10-01']);
ok(str_contains($c->lastUrl, 'suppliers/statement.php?id='), 'حفظ المورد وتحويل لكشف الحساب');
$supId = (int)$val("SELECT id FROM suppliers WHERE name='مورد اختبار E2E'");
$r = $c->post('items/form.php', ['name' => 'سكر E2E', 'unit' => 'كيلو', 'reorder_level' => '10', 'warehouse_id' => '1', 'opening_qty' => '5', 'opening_date' => '2026-10-01', 'is_active' => '1']);
$itemId = (int)$val("SELECT id FROM items WHERE name='سكر E2E'");
ok($itemId > 0, 'حفظ الصنف');
$r = $c->post('purchases/form.php', ['invoice_date' => '2026-10-09', 'doc_no' => 'E2E-100', 'party_id' => $supId, 'warehouse_id' => 1,
    'item_id' => [$itemId, $itemId], 'qty' => ['50', '25'], 'unit_price' => ['30', '32'], 'discount' => '100', 'transport' => '50', 'vat' => '200', 'other_fees' => '0',
    'paid_amount' => '300', 'account_id' => 1, 'payment_method' => 'نقدي', 'due_date' => '', 'note_no' => 'GRN-9', 'notes' => 'اختبار']);
ok(str_contains($c->lastUrl, 'purchases/view.php?id='), 'تسجيل فاتورة المشتريات وتحويل لصفحة العرض: ' . flashText($r['body']));
$pu = $pdo->query("SELECT * FROM purchases WHERE doc_no='E2E-100'")->fetch();
ok($pu && abs($pu['total'] - (50 * 30 + 25 * 32 - 100 + 50 + 200)) < 0.01, 'إجمالي الفاتورة صحيح = ' . ($pu['total'] ?? '?'));
ok($pu && $pu['due_date'] === '2026-10-29', 'الاستحقاق = التاريخ + 20 يوم');
ok(str_contains($r['body'], 'جزئي'), 'ظهور حالة السداد: جزئي');
$r = $c->post('purchases/form.php', ['invoice_date' => '2026-10-09', 'doc_no' => 'E2E-100', 'party_id' => $supId, 'warehouse_id' => 1, 'item_id' => [$itemId], 'qty' => ['1'], 'unit_price' => ['1']]);
ok(str_contains(flashText($r['body']), 'منع تكرار'), 'تكرار رقم الفاتورة يظهر رسالة خطأ للمستخدم');

echo "▶ فاتورة مبيعات + سند قبض + إلغاء\n";
$r = $c->post('customers/form.php', ['name' => 'عميل اختبار E2E', 'credit_days' => '10', 'opening_balance' => '250', 'opening_date' => '2026-10-01', 'is_active' => '1']);
$cusId = (int)$val("SELECT id FROM customers WHERE name='عميل اختبار E2E'");
$r = $c->post('sales/form.php', ['invoice_date' => '2026-10-10', 'party_id' => $cusId, 'warehouse_id' => 1, 'item_id' => [$itemId], 'qty' => ['999999'], 'unit_price' => ['40']]);
ok(str_contains(flashText($r['body']), 'لا يكفي'), 'منع البيع بكمية أكبر من المخزون');
$r = $c->post('sales/form.php', ['invoice_date' => '2026-10-10', 'party_id' => $cusId, 'warehouse_id' => 1, 'item_id' => [$itemId], 'qty' => ['20'], 'unit_price' => ['40'], 'paid_amount' => '100', 'account_id' => 2, 'payment_method' => 'تحويل بنكي']);
ok(str_contains($c->lastUrl, 'sales/view.php?id='), 'تسجيل فاتورة المبيعات: ' . flashText($r['body']));
$saleId = (int)$val("SELECT MAX(id) FROM sales WHERE customer_id=?", [$cusId]);
$r = $c->post('vouchers/form.php', ['type' => 'receipt', 'voucher_date' => '2026-10-10', 'party_id' => $cusId, 'amount' => '300', 'account_id' => 1, 'payment_method' => 'نقدي', 'alloc' => 'invoice', 'invoice_id' => $saleId, 'doc_no' => '']);
ok(str_contains($c->lastUrl, 'vouchers/view.php?id='), 'سند قبض: ' . flashText($r['body']));
$vId = (int)$val('SELECT MAX(id) FROM vouchers');
ok(abs($val('SELECT paid_amount FROM sales WHERE id=?', [$saleId]) - 400) < 0.01, 'المدفوع على الفاتورة = 100 + 300');
$r = $c->post("sales/view.php?id=$saleId", ['action' => 'cancel']);
ok(str_contains(flashText($r['body']), 'سندات مرتبطة'), 'منع إلغاء فاتورة عليها سند');
$r = $c->post("vouchers/view.php?id=$vId", []);
ok($val('SELECT status FROM vouchers WHERE id=?', [$vId]) === 'cancelled', 'إلغاء السند');
$r = $c->post("sales/view.php?id=$saleId", ['action' => 'cancel']);
ok($val('SELECT status FROM sales WHERE id=?', [$saleId]) === 'cancelled', 'إلغاء فاتورة المبيعات بعد إلغاء السند');
$r = $c->req("customers/statement.php?id=$cusId");
ok($r['code'] === 200 && str_contains($r['body'], 'كشف الحساب'), 'كشف حساب العميل يعرض');

echo "▶ الخزينة\n";
$r = $c->post('treasury/form.php', ['direction' => 'payment', 'category' => 'إيجار', 'txn_date' => '2026-10-10', 'doc_no' => 'E2E-RENT', 'description' => 'إيجار اختبار', 'amount' => '10', 'account_id' => 1, 'payment_method' => 'نقدي', 'responsible' => 'اختبار']);
ok(str_contains($c->lastUrl, 'treasury/') && !str_contains($c->lastUrl, 'form.php'), 'تسجيل مصروف: ' . flashText($r['body']));
$r = $c->post('treasury/transfer.php', ['operation' => 'deposit', 'txn_date' => '2026-10-10', 'doc_no' => 'E2E-DEP', 'amount' => '5', 'cash_account_id' => 1, 'bank_account_id' => 2, 'payment_method' => 'تحويل بنكي']);
ok((int)$val("SELECT COUNT(*) FROM treasury_transactions WHERE doc_no='E2E-DEP'") === 2, 'إيداع بنكي = حركتين');
$r = $c->req('treasury/?from=2026-10-01&to=2026-10-31');
ok($r['code'] === 200 && str_contains($r['body'], 'E2E-RENT'), 'ظهور المصروف في شاشة الخزينة');

echo "▶ المستخدمين والصلاحيات\n";
$r = $c->post('users/form.php', ['full_name' => 'أمين مخزن', 'username' => 'wh1', 'password' => 'Wh@12345678', 'role' => 'warehouse', 'is_active' => '1']);
ok($val("SELECT role FROM users WHERE username='wh1'") === 'warehouse', 'إنشاء مستخدم أمين مخزن: ' . flashText($r['body']));
$r = $c->post('users/form.php', ['full_name' => 'x', 'username' => 'bad name', 'password' => 'Wh@12345678', 'role' => 'warehouse', 'is_active' => '1']);
ok(str_contains(flashText($r['body']), 'حروف إنجليزية'), 'رفض اسم مستخدم غير صالح');
$w = new Client();
$w->post('auth/login.php', ['username' => 'wh1', 'password' => 'Wh@12345678']);
$r = $w->req('dashboard/');
ok($r['code'] === 200 && !str_contains($r['body'], 'رصيد الخزينة والبنك'), 'أمين المخزن لا يرى الأرقام المالية في الداشبورد');
ok($w->req('treasury/')['code'] === 403, 'أمين المخزن ممنوع من الخزينة (403)');
ok($w->req('purchases/')['code'] === 403, 'أمين المخزن ممنوع من المشتريات (403)');
ok($w->req('users/')['code'] === 403, 'أمين المخزن ممنوع من المستخدمين (403)');
ok($w->req('stock/')['code'] === 200, 'أمين المخزن يدخل المخازن');
$r = $w->req('reports/'); ok($r['code'] === 403, 'ممنوع من التقارير');
$w2 = new Client();
for ($i = 0; $i < 5; $i++) { $w2->post('auth/login.php', ['username' => 'wh1', 'password' => 'bad' . $i]); }
$r = $w2->post('auth/login.php', ['username' => 'wh1', 'password' => 'Wh@12345678']);
ok(str_contains($r['body'], 'مقفل مؤقتاً'), 'قفل الحساب بعد 5 محاولات فاشلة');
$pdo->exec("UPDATE users SET locked_until=NULL, failed_logins=0 WHERE username='wh1'");

echo "▶ الإعدادات وصفحة التثبيت\n";
$r = $c->post('settings/', ['company_name' => 'ماهر البحيري للاستيراد والتصدير', 'company_name_en' => 'MB', 'vat_rate' => '14', 'invoice_footer' => 'شكراً']);
ok(str_contains(flashText($r['body']), 'تم حفظ'), 'حفظ الإعدادات');
$r = $c->req('install/'); ok(in_array($r['code'], [200, 403], true), 'صفحة التثبيت متاحة/مقفلة');

echo "\n══ النتيجة: $pass نجح، $fail فشل ══\n";
exit($fail ? 1 : 0);
