-- =====================================================================
-- JMC Digital — LIVE migration: add three new Basics product categories
-- (Palengke Items, Frozen Meat Products, Bread & Snacks) to the
-- basics_products.category ENUM. Purely additive (widens the ENUM, doesn't
-- remove any existing value), so existing rows/values are unaffected.
-- Safe to run any time. Paste into phpMyAdmin's SQL tab.
-- =====================================================================

ALTER TABLE basics_products
    MODIFY COLUMN category ENUM('Rice','Food Essentials','Cooking Products','Beverages','Homecare','Personal Care','Palengke Items','Frozen Meat Products','Bread & Snacks') NOT NULL;
