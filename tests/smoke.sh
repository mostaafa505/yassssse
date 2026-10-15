#!/usr/bin/env bash
# فحص دخاني: يسجل دخول ويفتح كل الشاشات ويتأكد مفيش أخطاء PHP.
# التشغيل (بعد php tests/services_test.php اللي بيجهّز البيانات):
#   MB_ENV_FILE=tests/env.test.php php -S 127.0.0.1:8080 -t htdocs &
#   bash tests/smoke.sh
BASE=${BASE:-http://127.0.0.1:8080}
J=$(mktemp); fail=0
T=$(curl -s -c "$J" -b "$J" "$BASE/auth/login.php" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -c "$J" -b "$J" -o /dev/null -X POST "$BASE/auth/login.php" --data-urlencode "csrf_token=$T" -d "username=${USERNAME_T:-t}&password=${PASS_T:-Test@12345}"
check() {
  local out code body
  out=$(curl -s -b "$J" -c "$J" -w "\n%{http_code}" "$BASE/$1"); code=${out##*$'\n'}; body=${out%$'\n'*}
  if [ "$code" != "200" ] || echo "$body" | grep -qiE "Fatal error|Parse error|Warning:|Notice:|Stack trace|Uncaught|Undefined (variable|array key|index)"; then
    echo "✗ $1 -> $code"; echo "$body" | grep -iE "Fatal error|Warning:|Notice:|Uncaught|Undefined" | head -3; fail=1
  else echo "✓ $1"; fi
}
for p in dashboard/ items/ items/form.php "items/form.php?id=1" stock/ "stock/?wh=1&from=2026-10-01" stock/movements.php stock/operation.php warehouses/ \
  suppliers/ suppliers/form.php "suppliers/form.php?id=1" "suppliers/statement.php?id=1" "suppliers/statement.php?id=1&from=2026-10-02&to=2026-10-30" \
  customers/ customers/form.php "customers/statement.php?id=1" "customers/statement.php?id=2" \
  purchases/ purchases/form.php "purchases/form.php?party=1" "purchases/view.php?id=1" "purchases/?status=partial" "purchases/?status=late" \
  sales/ sales/form.php "sales/view.php?id=1" "sales/view.php?id=4" "sales/?status=cancelled" \
  vouchers/ "vouchers/form.php?type=receipt" "vouchers/form.php?type=payment&party=1" "vouchers/view.php?id=1" "vouchers/view.php?id=3" \
  treasury/ "treasury/?acc=1" "treasury/?acc=2" treasury/form.php "treasury/form.php?dir=receipt" treasury/transfer.php treasury/accounts.php "treasury/accounts.php?id=1" \
  reports/ reports/daily.php "reports/daily.php?d=2026-10-10" "reports/daily.php?d=2026-10-10&export=1" "reports/report.php?r=summary" "reports/report.php?r=aging_customers" "reports/report.php?r=aging_suppliers" "reports/report.php?r=expenses" "reports/report.php?r=treasury_expenses" manifest.webmanifest sw.js offline.html \
  "reports/report.php?r=sales_by_customer" "reports/report.php?r=purchases_by_supplier" "reports/report.php?r=stock_value" \
  users/ users/form.php "users/form.php?id=1" users/profile.php settings/ audit/ \
  "customers/?export=1" "stock/?export=1" "treasury/?export=1" "purchases/?export=1" "reports/report.php?r=summary&export=1" "suppliers/statement.php?id=1&export=1"; do check "$p"; done
rm -f "$J"; exit $fail
