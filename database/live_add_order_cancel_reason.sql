-- =====================================================================
-- JMC Foodies Basics — LIVE: require + store a reason when admin cancels
-- an order, and text/email that reason to the member (basics/admin/
-- order_view.php).
--
-- Nullable because existing cancelled orders (and any future member
-- self-cancel from basics/order_view.php, which doesn't collect a reason)
-- have none.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_orders ADD COLUMN cancel_reason VARCHAR(255) NULL DEFAULT NULL AFTER status;
