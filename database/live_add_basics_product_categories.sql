-- =====================================================================
-- JMC Digital — LIVE migration: make Basics product categories editable.
-- Moves the category list out of the basics_products.category ENUM into
-- its own table, so admins can add new categories from Add/Edit Product
-- without a schema change each time. Existing product rows keep their
-- category text unchanged (ENUM -> VARCHAR is lossless).
-- Safe to run more than once. Paste into phpMyAdmin's SQL tab.
-- =====================================================================

CREATE TABLE IF NOT EXISTS basics_product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_basics_product_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The current list, in the order the filter pills show it today.
INSERT IGNORE INTO basics_product_categories (name, sort_order) VALUES
('Rice', 1),
('Food Essentials', 2),
('Cooking Products', 3),
('Beverages', 4),
('Homecare', 5),
('Personal Care', 6),
('Palengke Items', 7),
('Frozen Meat Products', 8),
('Bread & Snacks', 9);

ALTER TABLE basics_products
    MODIFY COLUMN category VARCHAR(100) NOT NULL;
