-- ============================================================================
-- AUDIT / reconcile.sql
-- Run these against store_pos_db at any time to check the accounting
-- invariants on real data. Every query should return ZERO rows on a healthy
-- system. Any row returned names a specific, concrete violation.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1) Invariant #1 / #7: reported net per sale must equal total - sum(returns)
--    and must never go negative.
-- ----------------------------------------------------------------------------
SELECT
    s.id, s.invoice_number, s.status, s.total AS gross,
    COALESCE((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id), 0) AS returned_amount,
    s.total - COALESCE((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id), 0) AS net
FROM sales s
WHERE s.total - COALESCE((SELECT SUM(r.total) FROM returns r WHERE r.sale_id = s.id), 0) < 0;
-- Expected: 0 rows. A negative net means more was returned than was ever sold
-- on that invoice (a return-amount bug or a duplicated return row).

-- ----------------------------------------------------------------------------
-- 2) Invariant #3: a sale_item's returned quantity can never exceed its sold
--    quantity (guards against a return.php validation bypass or a race).
-- ----------------------------------------------------------------------------
SELECT
    si.id AS sale_item_id, si.sale_id, si.product_name_ar, si.quantity AS sold_qty,
    COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0) AS returned_qty
FROM sale_items si
HAVING returned_qty > sold_qty;
-- Expected: 0 rows.

-- ----------------------------------------------------------------------------
-- 3) Invariant #1: returns.total must equal the sum of its own return_items
--    (guards against a partial INSERT or a manual data edit).
-- ----------------------------------------------------------------------------
SELECT
    r.id AS return_id, r.sale_id, r.total AS return_total,
    COALESCE((SELECT SUM(ri.total) FROM return_items ri WHERE ri.return_id = r.id), 0) AS items_total
FROM returns r
HAVING return_total <> items_total;
-- Expected: 0 rows.

-- ----------------------------------------------------------------------------
-- 4) Invariant #6: sale.status must correctly reflect whether all lines are
--    fully returned, partially returned, or not returned at all.
-- ----------------------------------------------------------------------------
SELECT s.id, s.invoice_number, s.status AS stored_status,
    CASE
        WHEN NOT EXISTS (
            SELECT 1 FROM sale_items si
            WHERE si.sale_id = s.id
              AND si.quantity > COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0)
        ) THEN 'returned'
        WHEN EXISTS (
            SELECT 1 FROM sale_items si
            WHERE si.sale_id = s.id
              AND COALESCE((SELECT SUM(ri.quantity) FROM return_items ri WHERE ri.sale_item_id = si.id), 0) > 0
        ) THEN 'partial_return'
        ELSE 'completed'
    END AS computed_status
FROM sales s
HAVING stored_status <> computed_status;
-- Expected: 0 rows.

-- ----------------------------------------------------------------------------
-- 5) Invariant #4: sale_items.unit_price for price_type='offer' should match
--    what an active campaign or the static offer_price implies. This query
--    can only check the STATIC-column half (F-01's campaign half needs the
--    PHP-side computeOfferPrice() function, see AUDIT_REPORT/03-findings.md's
--    F-01 test script reference instead) — it
--    flags any 'offer' line whose price doesn't match any known offer price
--    source at all, which is always a bug regardless of F-01's resolution.
-- ----------------------------------------------------------------------------
SELECT si.id, si.sale_id, si.product_id, si.variant_id, si.unit_price AS charged_price,
       p.offer_price AS product_static_offer, pv.offer_price AS variant_static_offer, p.retail_price
FROM sale_items si
LEFT JOIN products p ON si.product_id = p.id
LEFT JOIN product_variants pv ON si.variant_id = pv.id
WHERE si.price_type = 'offer'
  AND si.unit_price NOT IN (COALESCE(pv.offer_price, p.offer_price, -1), p.retail_price);
-- Any row here charged an 'offer' price that matches neither today's static
-- offer_price NOR full retail — i.e. it doesn't even match the OLD (buggy)
-- logic, which would indicate a different problem worth investigating by hand.
-- NOTE: rows where unit_price = p.retail_price and offer_price is NULL are
-- exactly the F-01 bug signature — filter this query further once F-01 is
-- fixed to assert unit_price always equals the true intended offer price.

-- ----------------------------------------------------------------------------
-- 6) Stock sanity: no product or variant should have gone negative except
--    where explicitly allowed (offline oversell — flagged, not an error, but
--    worth a human glance each time it happens).
-- ----------------------------------------------------------------------------
SELECT id, name_ar, stock_qty FROM products WHERE stock_qty < 0;
SELECT id, product_id, color_ar, size, stock_qty FROM product_variants WHERE stock_qty < 0;
-- Expected: 0 rows in normal operation. A non-empty result is not automatically
-- a bug (offline sales are deliberately allowed to oversell rather than lose
-- the invoice) but every row here should be reviewed and restocked/corrected.

-- ----------------------------------------------------------------------------
-- 7) Movement-log reconciliation for a single product/variant: replay every
--    movement and compare to the stored stock_qty. Replace :product_id.
--    NOTE: this will legitimately NOT reconcile for any product whose stock
--    was ever set via inventory/stocktake.php, because that screen can
--    silently overwrite stock_qty without a matching, complete movement
--    trail if the browser's stale system_qty diverged from the DB at submit
--    time (see finding F-00 in 03-findings.md, the stocktake TOCTOU bug).
-- ----------------------------------------------------------------------------
SET @product_id = 1; -- change me
SELECT
    p.id, p.name_ar, p.stock_qty AS stored_stock,
    COALESCE(SUM(CASE movement_type
        WHEN 'in' THEN quantity
        WHEN 'return' THEN quantity
        WHEN 'sale' THEN -quantity
        WHEN 'out' THEN -quantity
        WHEN 'adjustment' THEN 0 -- adjustment quantity is stored as an absolute delta magnitude, sign not recoverable from this table alone
        ELSE 0
    END), 0) AS replayed_from_movements_excl_adjustments
FROM products p
LEFT JOIN inventory_movements im ON im.product_id = p.id AND im.variant_id IS NULL
WHERE p.id = @product_id
GROUP BY p.id;
-- This will only exactly match stored_stock for a product that has never had
-- a stocktake adjustment (adjustment movements only store abs(diff), not
-- signed diff, so they can't be replayed here without a schema change —
-- see the proposed fix for F-00/stocktake in 03-findings.md).

-- ----------------------------------------------------------------------------
-- 8) Invariant #11: find any user account with cost-visibility exposure risk
--    — anyone who is NOT admin and does NOT have view_cost, but DOES have
--    manage_products (the exact F-04 scenario).
-- ----------------------------------------------------------------------------
SELECT id, name_ar, role, permissions
FROM users
WHERE role <> 'admin'
  AND JSON_CONTAINS(COALESCE(permissions, '[]'), '"manage_products"')
  AND NOT JSON_CONTAINS(COALESCE(permissions, '[]'), '"view_cost"');
-- Any row here is a real user who, under the current F-04 bug, can already
-- see cost prices today even though you never granted them view_cost.

-- ----------------------------------------------------------------------------
-- 9) Duplicate invoice_number / client_uid sanity (should be impossible given
--    the UNIQUE constraints, included as a belt-and-suspenders check in case
--    of a future migration or manual data edit).
-- ----------------------------------------------------------------------------
SELECT invoice_number, COUNT(*) FROM sales GROUP BY invoice_number HAVING COUNT(*) > 1;
SELECT client_uid, COUNT(*) FROM sales WHERE client_uid IS NOT NULL GROUP BY client_uid HAVING COUNT(*) > 1;
-- Expected: 0 rows (enforced by UNIQUE constraints already, this just verifies them).

-- ----------------------------------------------------------------------------
-- 10) Duplicate barcodes across products and variants (F-09 — app-level check
--     only, no DB constraint yet).
-- ----------------------------------------------------------------------------
SELECT barcode, COUNT(*) c FROM (
    SELECT barcode FROM products WHERE barcode IS NOT NULL AND barcode <> ''
    UNION ALL
    SELECT barcode FROM product_variants WHERE barcode IS NOT NULL AND barcode <> ''
) all_barcodes
GROUP BY barcode HAVING c > 1;
-- Expected: 0 rows today; not DB-enforced, so this should be run periodically
-- until F-09 is fixed with a real UNIQUE index.

-- ----------------------------------------------------------------------------
-- 11) F-08: every return should record who it was refunded to (cash/card/
--     transfer). NULL is tolerated for returns processed before this fix, or
--     a cancelled 'mixed'-payment sale where no single method could be
--     inferred automatically — but any NEW return with no method going
--     forward should be reviewed by hand.
-- ----------------------------------------------------------------------------
SELECT id, sale_id, total, refund_method, created_at
FROM returns
WHERE refund_method IS NULL
ORDER BY created_at DESC;
-- Not automatically a bug (see above) — just a worklist to glance at
-- periodically for anything that should have had a method but didn't.

-- ----------------------------------------------------------------------------
-- 12) F-07: sale_items sold after this fix shipped should carry a cost
--     snapshot. Rows with a NULL cost_price are either genuinely uncosted
--     products (fine) or pre-fix historical rows (expected, not a bug) — this
--     just flags anything that looks like it should have one but doesn't.
-- ----------------------------------------------------------------------------
SELECT si.id, si.sale_id, si.product_name_ar, si.unit_price, si.cost_price, s.created_at
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_id = p.id
WHERE si.cost_price IS NULL AND p.cost_price IS NOT NULL
ORDER BY s.created_at DESC;
-- A row here means the product HAD a cost price at some point but this sale's
-- snapshot is missing — expected only for sales made before this fix shipped.
