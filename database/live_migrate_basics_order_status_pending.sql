-- =====================================================================
-- JMC Digital — LIVE migration: rename basics_orders.status value
-- 'placed' to 'pending'. Purely a rename — the flow itself is unchanged
-- (draft = cart, pending = checked out & awaiting payment/delivery,
-- delivered, cancelled), matching what's already built & tested in
-- local dev.
--
-- CRITICAL ORDERING: run this SQL FIRST, in the same maintenance window,
-- immediately followed by uploading the changed PHP files (basics/**). The
-- new code reads/writes status='pending' for checked-out orders, which
-- does not exist in the live ENUM (it currently has 'placed' instead)
-- until this script runs.
--
-- Paste this ENTIRE script into phpMyAdmin's SQL tab and run it as one
-- batch (do NOT use the Import tab — it has silently failed before on
-- this host).
-- =====================================================================

-- 1. Widen the ENUM to hold both the old and new value at once, so
--    existing 'placed' rows stay valid while we rename them below.
ALTER TABLE basics_orders
    MODIFY COLUMN status ENUM('draft','placed','pending','delivered','cancelled') NOT NULL DEFAULT 'draft';

-- 2. Rename the value on existing rows.
UPDATE basics_orders SET status = 'pending' WHERE status = 'placed';

-- 3. Drop 'placed' from the ENUM now that no row uses it.
ALTER TABLE basics_orders
    MODIFY COLUMN status ENUM('draft','pending','delivered','cancelled') NOT NULL DEFAULT 'draft';
