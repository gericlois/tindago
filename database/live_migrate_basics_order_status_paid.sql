-- =====================================================================
-- JMC Digital — LIVE migration: add 'paid' to basics_orders.status.
-- Purely additive (widens the ENUM, doesn't remove or rename any existing
-- value), so existing rows are unaffected. Once uploaded, the app code
-- auto-flips a 'pending' order to 'paid' the moment a recorded payment
-- (basics_record_payment(), whether entered directly by an admin via
-- Record Payment or via confirming a member's payment submission) brings
-- its cumulative amount_paid up to total_amount. "Mark Delivered" then
-- only becomes available once an order is 'paid'.
--
-- Safe to run any time, but as usual pair it with uploading the changed
-- PHP files (basics/**) in the same maintenance window — the old code
-- doesn't know about 'paid' and the new code's Deliver gate expects it.
-- Paste this ENTIRE script into phpMyAdmin's SQL tab and run it as one
-- batch (do NOT use the Import tab — it has silently failed before on
-- this host).
-- =====================================================================

ALTER TABLE basics_orders
    MODIFY COLUMN status ENUM('draft','pending','paid','delivered','cancelled') NOT NULL DEFAULT 'draft';

-- Optional one-time backfill: any order already sitting at 'pending' that
-- had, under the old code, already been paid in full (no auto-transition
-- existed yet) should be caught up to 'paid' now. Safe to run — only
-- touches rows where the payment total already covers the order.
UPDATE basics_orders o
    SET o.status = 'paid'
    WHERE o.status = 'pending'
      AND (SELECT COALESCE(SUM(p.amount_paid), 0) FROM basics_payments p WHERE p.order_id = o.id) >= o.total_amount;
