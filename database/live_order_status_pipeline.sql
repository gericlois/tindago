-- =====================================================================
-- JMC Foodies Basics — LIVE: rework order status into a 4-stage
-- admin-driven fulfillment pipeline, decoupled from payment.
--
--   pending          -> "Checking"   (member placed it, awaiting admin review)
--   confirmed        -> "Preparing"  (admin approved; editable; delivery
--                                     receipt becomes printable here)
--   out_for_delivery -> "In Transit" (admin marked it out for delivery)
--   delivered        -> "Delivered"  (member received it)
--   cancelled        -> "Cancelled"  (unchanged; only from pending/confirmed)
--
-- The 'paid' status is removed — payment completion is no longer a
-- fulfillment stage, it's tracked purely via SUM(basics_payments.amount_paid)
-- vs total_amount (already how basics_payment_due_date() etc. work), so an
-- order can be paid at any stage without changing where it sits above.
-- Existing 'paid' rows become 'confirmed' (they were approved and fully
-- paid, just not yet marked out for delivery).
-- =====================================================================

ALTER TABLE basics_orders MODIFY COLUMN status ENUM('draft','pending','confirmed','paid','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'draft';
UPDATE basics_orders SET status = 'confirmed' WHERE status = 'paid';
ALTER TABLE basics_orders MODIFY COLUMN status ENUM('draft','pending','confirmed','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'draft';
