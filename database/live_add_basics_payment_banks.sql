-- =====================================================================
-- JMC Foodies Basics — LIVE: dynamic Payment Banks.
--
-- Replaces the hardcoded GCash/PNB/EastWest fields in Settings with a
-- proper table admins can add to, edit, enable/disable, and remove from
-- (basics/admin/payment_banks.php) without a code change per bank.
--
-- basics_payment_submissions.payment_method used to be a closed
-- ENUM('gcash','bank') — it now stores the chosen bank's name directly, so
-- it needs to hold arbitrary text.
--
-- The three existing destinations are seeded from whatever is currently in
-- Settings (falling back to sensible defaults if unset) so nothing breaks
-- for members mid-migration. Their QR images must be copied to
-- uploads/basics_payment_bank_qrs/qr_pnb.jpg and qr_eastwest.jpg (same
-- filenames as the old assets/img/basics/ copies) before this deploys —
-- already done in this commit.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

CREATE TABLE basics_payment_banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(100) NOT NULL,
    qr_image VARCHAR(255) DEFAULT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE basics_payment_submissions MODIFY payment_method VARCHAR(100) NOT NULL;

INSERT INTO basics_payment_banks (name, account_name, account_number, qr_image, is_enabled, sort_order) VALUES
('GCash',
 COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'basics_gcash_name'), 'JMC Foodies Basics'),
 COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'basics_gcash_number'), ''),
 NULL, 1, 1),
('PNB',
 COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'basics_pnb_account_name'), 'JMC Foodies Basics'),
 COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'basics_pnb_account_number'), ''),
 'qr_pnb.jpg', 1, 2),
('EastWest',
 COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'basics_eastwest_account_name'), 'JMC Foodies Basics'),
 COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'basics_eastwest_account_number'), ''),
 'qr_eastwest.jpg', 1, 3);
