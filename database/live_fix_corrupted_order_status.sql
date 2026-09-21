-- =====================================================================
-- JMC Digital — LIVE one-time data fix: 5 basics_orders rows ended up with
-- status = '' (an invalid/blank ENUM value) because they were written to
-- during the window before basics_orders.status had been fully migrated
-- to include 'pending'/'paid'. MariaDB silently stored the invalid write
-- as an empty string instead of erroring.
--
-- Status recomputed per order from its actual basics_payments total vs
-- total_amount (verified against the Sep 8, 2026 live export):
--   #8  (900.31 due, 0 paid)      -> pending
--   #9  (457.26 due, 457.26 paid) -> paid
--   #11 (428.66 due, 0 paid)      -> pending
--   #20 (642.87 due, paid x2!)    -> paid  (see note below — do NOT touch
--                                    basics_payments for #20 here; that
--                                    needs manual verification first)
--   #28 (26.73 due, 26.73 paid)   -> paid
--
-- NOTE: Order #20 has two ₱642.87 rows in basics_payments (ids 5 and 6),
-- from two separate confirmed payment submissions (GCash + bank, 3
-- minutes apart). This script only fixes the order's status — it does
-- NOT delete either payment row. Verify with the member / your GCash-bank
-- records whether they paid twice (member is owed a ₱642.87 refund) or
-- only once (the duplicate confirmation should be reversed) before
-- touching basics_payments yourself.
--
-- Safe to run any time — only touches these 5 known rows, matched by id.
-- Paste into phpMyAdmin's SQL tab and run as one batch.
-- =====================================================================

UPDATE basics_orders SET status = 'pending' WHERE id = 8;
UPDATE basics_orders SET status = 'paid'    WHERE id = 9;
UPDATE basics_orders SET status = 'pending' WHERE id = 11;
UPDATE basics_orders SET status = 'paid'    WHERE id = 20;
UPDATE basics_orders SET status = 'paid'    WHERE id = 28;

-- Verify afterward — should return 0 rows.
SELECT id, status FROM basics_orders WHERE status = '' OR status NOT IN ('draft','pending','paid','delivered','cancelled');
