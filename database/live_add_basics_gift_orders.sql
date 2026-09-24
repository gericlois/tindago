-- =====================================================================
-- JMC Foodies Basics — LIVE: Birthday Grocery Gift becomes a real order.
--
-- Claiming a gift now creates a normal basics_orders row and runs it
-- through the existing pending -> confirmed -> out_for_delivery ->
-- delivered pipeline (see basics/admin/order_view.php) instead of just
-- flipping a timestamp. is_gift marks that order so the admin item
-- editor and every payment-status display can special-case it.
--
-- Gift orders keep total_amount pinned at 0 forever (see the add_item /
-- update_item guards in basics/admin/order_view.php) — this deliberately
-- keeps them out of every outstanding-balance / amount-due query, which
-- all key off total_amount vs. amount_paid.
--
-- basics_birthday_gifts.order_id links the claim to its order so
-- basics/admin/birthdays.php can show the order's live status instead of
-- a dead-end "Claimed" pill. Nullable + ON DELETE SET NULL so deleting an
-- order never blocks deleting the gift/member rows.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_orders ADD COLUMN is_gift TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE basics_birthday_gifts ADD COLUMN order_id INT NULL DEFAULT NULL AFTER claimed_at;
ALTER TABLE basics_birthday_gifts ADD CONSTRAINT fk_basics_birthday_gifts_order_id
    FOREIGN KEY (order_id) REFERENCES basics_orders(id) ON DELETE SET NULL;
