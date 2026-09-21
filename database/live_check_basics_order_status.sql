-- Diagnostic only — no changes made. Run this in phpMyAdmin and send me
-- the full result (all three result sets).

-- 1. What values does the live status column actually accept right now?
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'basics_orders' AND COLUMN_NAME = 'status';

-- 2. Order #20 specifically — is status really blank, and what does it owe/have paid?
SELECT id, member_id, cycle_id, status, total_amount, placed_at, delivered_at
FROM basics_orders WHERE id = 20;

-- 3. Every payment recorded against order #20 (checking for the duplicate).
SELECT id, order_id, amount_due, amount_paid, penalty_amount, is_late, paid_at, recorded_by, notes, created_at
FROM basics_payments WHERE order_id = 20 ORDER BY created_at ASC;

-- 4. Any other orders sitting on an invalid/blank status right now.
SELECT id, member_id, status, total_amount FROM basics_orders WHERE status = '' OR status NOT IN ('draft','pending','paid','delivered','cancelled');
