-- =====================================================================
-- JMC Foodies Basics — LIVE: Community Partner Account.
--
-- A Community Partner is a regular Basics member (same benefits/privileges,
-- can order for themselves) who ALSO earns a 2% override on orders placed
-- by other members tagged under them via a referral code, entered at
-- signup (basics/apply.php?ref=CODE), mirroring the referral system that
-- already exists on the Wellness side (see users.referral_code/referred_by
-- in this same file, and basics_deliver_order() in
-- basics/includes/functions.php for exactly when/how the override credits).
--
-- The override is paid out through a wallet + cashout flow (also mirrored
-- from Wellness's wallet_transactions/cashouts) — this wallet is ONLY an
-- override-earnings ledger, never used to pay for groceries.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_members
  ADD COLUMN is_community_partner TINYINT(1) NOT NULL DEFAULT 0 AFTER membership_status,
  ADD COLUMN referral_code VARCHAR(20) NULL UNIQUE AFTER is_community_partner,
  ADD COLUMN referred_by INT NULL DEFAULT NULL AFTER referral_code,
  ADD CONSTRAINT fk_basics_members_referred_by FOREIGN KEY (referred_by) REFERENCES basics_members(id) ON DELETE SET NULL;

CREATE TABLE basics_wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    type ENUM('referral_override','cashout','cashout_reversal') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference_order_id INT NULL,
    reference_cashout_id INT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES basics_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE basics_cashouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(10,2) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(100) NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    processed_by INT NULL,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES basics_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
