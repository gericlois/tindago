-- =====================================================================
-- JMC Digital — LIVE migration: payment method on recorded Basics payments.
-- Lets admins / payment staff record HOW an order was paid on Record
-- Payment (e.g. Cash on Delivery), and keeps the bank a member chose when
-- their online payment submission is confirmed. Older payments stay blank.
-- Purely additive. Paste into phpMyAdmin's SQL tab (run once).
-- =====================================================================

ALTER TABLE basics_payments
    ADD COLUMN payment_method VARCHAR(100) NULL DEFAULT NULL AFTER amount_paid;
