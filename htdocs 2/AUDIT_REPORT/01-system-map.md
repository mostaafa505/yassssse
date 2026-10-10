# System Map — Dahana POS & Inventory System

Scope: `C:\xampp\htdocs\store` (Store 1 / "Dahana"). Store 2 (`C:\xampp\htdocs\store2`, "Adventure")
is a separate codebase that **intentionally shares this same MySQL database** (`store_pos_db`) —
confirmed project decision, not a divergence to eliminate. Store 2 is covered separately in Phase 6.

Stack: PHP 8 procedural, PDO/MySQL (MariaDB 10.4 locally), no framework, no Composer/PHPUnit installed
in this environment, not a git repository at audit time.

## 1. Database tables

All tables use InnoDB, `utf8mb4_unicode_ci`. Money columns are `decimal(10,2)` throughout (good —
invariant 13 satisfied structurally). Quantity columns are also `decimal(10,2)` (allows fractional
quantities, which is unusual for piece-counted retail stock but not itself a bug).

| Table | Key columns | Notes |
|---|---|---|
| `users` | id, email (UNIQUE), password (bcrypt via `password_hash`), role enum(admin/cashier/stock/accountant), permissions (JSON) | No `deleted_at` — deactivation via `is_active` flag only. |
| `categories` | id, name_ar, name_en, sort | No FK complexity. |
| `products` | id, sku, barcode, category_id→categories(SET NULL), retail_price, offer_price, wholesale_price, cost_price, has_variants, stock_qty, min_stock_alert, is_active | `barcode` and `sku` have plain `KEY` indexes, **not UNIQUE** — uniqueness enforced only in application code (`barcodeExists()`). |
| `product_variants` | id, product_id→products(CASCADE), color_ar/en, size, sku, barcode, retail_price (nullable=inherit parent), offer_price, wholesale_price, cost_price, stock_qty, is_active | Same non-unique barcode index issue. |
| `sales` | id, invoice_number (UNIQUE), client_uid (UNIQUE, nullable — offline dedup key), exhibition_id→exhibitions(SET NULL), cashier_id→users(SET NULL), payment_method, cash/card/transfer_amount, subtotal, discount, total, status enum(completed/returned/partial_return), created_at | No index on `status` alone (only used in WHERE/CASE, low-cardinality — perf note, not correctness). |
| `sale_items` | id, sale_id→sales(CASCADE), product_id→products(SET NULL), variant_id→product_variants(SET NULL), product_name_ar/en (snapshot), color_ar/en/size/sku (snapshot), quantity, unit_price, price_type enum(retail/offer/wholesale), discount, total | Product/variant name and price are **snapshotted at sale time** (correct — protects historical invoices from later product edits). |
| `returns` | id, sale_id→sales(RESTRICT, no ON DELETE), processed_by→users(SET NULL), reason, total, created_at | `total` = sum of that return's line items (the refunded amount for that specific return event). |
| `return_items` | id, return_id→returns(CASCADE), sale_item_id (no FK constraint — see finding), quantity, unit_price, total | **`sale_item_id` has no FK constraint** to `sale_items.id` — an orphaned/invalid reference is possible if `sale_items` rows are ever hard-deleted independently (currently nothing does this, so latent risk, not an active bug). |
| `inventory_movements` | id, product_id→products(CASCADE), variant_id→product_variants(CASCADE), movement_type enum(in/out/transfer_in/transfer_out/sale/return/adjustment), quantity, cost_price, from_location, to_location, reference_id, notes, user_id→users(SET NULL) | `reference_id` is a loose pointer (sale_id or return_id depending on context) with **no FK constraint and no discriminator column** — cannot be joined reliably without knowing `movement_type` first. `transfer_in` enum value is **never actually written** by any code path (see §2.5). |
| `offers` | id, discount_type enum(percent/fixed/set_price), discount_value, apply_to enum(all/category/product), target_ids (JSON), start_date/end_date (nullable), is_active | Time-boxed promotional campaigns. **Structurally independent from `products.offer_price`** — see §2.7, this is the source of Finding F-01. |
| `exhibitions`, `exhibition_expenses` | — | Standard, well-formed FKs. |
| `audit_logs` | user_id, action, entity_type, entity_id, old_value/new_value (JSON), ip_address, created_at | Generic audit trail, used inconsistently in coverage (see §2). |
| `settings` | key (PK), value | Simple key-value store, e.g. invoice footer text. |

### Missing indexes / FK observations
- `products.barcode`, `product_variants.barcode`: not UNIQUE at DB level (app-level check only — race window, see F-09).
- `return_items.sale_item_id`: no FK constraint.
- `inventory_movements.reference_id`: no FK constraint (by necessity, since it's polymorphic — acceptable but undocumented in schema).
- `sales.status`: no index (low priority — data volumes here are small).

## 2. Money-moving and stock-moving operations

| # | Operation | Entry point(s) | Tables written | Transaction? | Row locking (`FOR UPDATE`)? |
|---|---|---|---|---|---|
| 2.1 | Sale (online) | `pos/save_sale.php` | `sales`, `sale_items`, `products`/`product_variants` (stock -), `inventory_movements` (sale), `audit_logs` | Yes (`beginTransaction`/`commit`/`rollBack`) | Yes — `SELECT ... FOR UPDATE` on product/variant before stock check (prevents oversell race, invariant-relevant). |
| 2.2 | Sale (offline, synced later) | `pos/sync_offline.php` | Same as 2.1, keyed by `client_uid` for dedup | Yes, per-sale sub-transaction in a loop | Yes — `FOR UPDATE` on product/variant. **Deliberately allows negative stock** (recent, confirmed design decision: an offline sale that already collected cash must never be silently dropped for a stock conflict). |
| 2.3 | Return (partial or full) | `sales/return.php` → `processReturn()` in `config.php` | `returns`, `return_items`, `products`/`product_variants` (stock +), `inventory_movements` (return), `sales.status` | Yes | No explicit lock, but `stock_qty = stock_qty + ?` is a single atomic UPDATE — safe by construction. |
| 2.4 | Cancel invoice (full) | `sales/cancel.php` → `processReturn()` | Same as 2.3 (all sale_items at full quantity) | Yes | Same as above. |
| 2.5 | Warehouse↔showroom transfer | `inventory/transfer.php` | `inventory_movements` only (`transfer_out` with `from_location`/`to_location` columns) | Yes | N/A — **does not touch `stock_qty` at all**, by design (schema has one stock number per product/variant, not per-location). Documents movement only; does not validate that the source location actually holds the quantity being "transferred" (there is no per-location balance to check against). |
| 2.6 | Stock receipt | `inventory/receive.php` | `products`/`product_variants` (stock +), `inventory_movements` (in) | Yes | N/A (additive UPDATE, safe) — **does not update `products.cost_price`/`product_variants.cost_price`** even when a new `cost_price` is entered for this receipt (see F-06). |
| 2.7 | Stock count / adjustment | `inventory/stocktake.php` | `products`/`product_variants` (stock = absolute value), `inventory_movements` (adjustment) | Yes (whole batch in one transaction) | **None — reads `system_qty` from a hidden form field submitted by the browser, not re-queried from the DB at submit time.** See F-01 (Critical). |
| 2.8 | Offline cancel/return sync | `sales/sync_offline_actions.php` → `processReturn()` | Same as 2.3/2.4 | Yes, per-action | Re-validates against live DB state each time (no stored dedup flag) — correct idempotency pattern. |
| 2.9 | Price change | `products/edit.php`, `products/create.php` | `products`/`product_variants` | Yes | N/A. **No audit-log entry for price changes specifically** (products/edit.php doesn't call `logAudit()` — verify in Phase 3). |
| 2.10 | Offer activation (campaign) | `offers/index.php` | `offers` only | N/A | N/A. **Never touches `products.offer_price`** — see F-01, the central finding of this audit. |

## 3. Report/dashboard number sources

| Report | File | How net revenue is computed (as of this audit) |
|---|---|---|
| Executive P&L report | `reports/index.php` | `SUM(s.total) − SUM(returns.total per sale)`, computed per-sale via correlated subquery, summed across ALL sales regardless of `status`. **Fixed in this session** (previously excluded `partial_return` sales from both "completed" and "returned" buckets — see prior findings, already resolved). |
| Reports Excel export | `reports/export.php` | Same corrected logic, mirrored. |
| Dashboard KPIs | `dashboard/index.php` | Same corrected logic (gross − returned_amount), per period filter (today/yesterday/week/month/all) using `CURDATE()`/`YEARWEEK()`/server clock — **see F-02, timezone**. |
| Sales list export | `sales/export.php` | Same corrected logic. |
| Exhibition P&L | `exhibitions/index.php`, `export.php`, `print.php` | Same corrected logic, plus per-product COGS computed from **net** (returns-adjusted) quantity × current `cost_price` (not historical cost at time of sale — see F-07). |

All five of the above were auditied and fixed **in the prior working session** (not part of this audit
run) for the specific bug: `partial_return` status sales were excluded from revenue entirely in all of
them. That fix is **already live and verified** — see `AUDIT_REPORT/03-findings.md` items marked `RESOLVED`.

## 4. Authentication & authorization model

- Session-based auth (`$_SESSION['user_id']`, `user_role`, `user_permissions`), `session.cookie_httponly=true`,
  `SameSite=Lax`, `secure` flag tied to HTTPS detection.
- `requireAuth(array $roles = [])`: login required, optional role allow-list.
- `requireAccess(array $roles, string $permission)`: login required, allowed if role matches OR the
  user's custom `permissions` JSON array contains the named permission. Introduced this session to let
  admins grant module access (POS, inventory, products, etc.) independent of base role.
- `hasPermission(string $perm)`: admin role always true; otherwise checks the JSON permissions array.
- Deliberately **not** grantable as a custom permission: user management (`/users/`) — hard-coded
  admin-only, documented in code as an intentional privilege-escalation guard.
- **No CSRF token anywhere in the codebase** (confirmed via full-text search) — relies solely on
  `SameSite=Lax` cookies for cross-site request forgery mitigation. See F-05.
