-- =====================================================================
-- JMC Foodies Basics — LIVE: Flash Deals + Featured Products, now shown
-- at the top of basics/catalog.php (the member's new landing page after
-- login, replacing basics/dashboard.php in that role).
--
-- is_featured: a manual admin flag, no expiry — shows in the "Featured
-- Products" row until unchecked.
--
-- flash_deal_price + flash_deal_ends_at: both must be set for a product to
-- count as an active flash deal; catalog.php only shows it while
-- flash_deal_ends_at is still in the future (basics/admin/product_edit.php
-- doesn't auto-clear these after they expire — an expired deal just stops
-- showing, the fields are left as a record of the last deal set).
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_products
  ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN flash_deal_price DECIMAL(10,2) NULL DEFAULT NULL AFTER is_featured,
  ADD COLUMN flash_deal_ends_at DATETIME NULL DEFAULT NULL AFTER flash_deal_price;
