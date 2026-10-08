-- =====================================================================
-- TindaGo — database schema (structure only, no member data)
-- Import into an empty database (local: `tindago`) via phpMyAdmin or:
--   mysql -uroot tindago < database/tindago_schema.sql
-- Seeds: default business-rule settings, product categories, and one
-- super admin (username: admin / password: ChangeMe123!) — change that
-- password from Basics Admin > Admins right after the first login.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` varchar(20) NOT NULL,
  `identifier` varchar(150) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scope_identifier` (`scope`,`identifier`,`created_at`),
  KEY `idx_ip` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('wallet','bank_transfer','cod') NOT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `status` enum('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `archived_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `product_id` (`product_id`),
  KEY `confirmed_by` (`confirmed_by`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`confirmed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `srp` decimal(10,2) NOT NULL DEFAULT 210.00,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `referral_code` varchar(20) NOT NULL,
  `referred_by` int(11) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `address` varchar(255) NOT NULL,
  `birthdate` date NOT NULL,
  `contact_number` varchar(30) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('pending','active','suspended') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `referral_code` (`referral_code`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email_unique` (`email`),
  KEY `referred_by` (`referred_by`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wallet_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('personal_rebate','referral_override','purchase_wallet_debit','purchase_refund','cashout','cashout_reversal') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_order_id` int(11) DEFAULT NULL,
  `reference_cashout_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `reference_order_id` (`reference_order_id`),
  KEY `reference_cashout_id` (`reference_cashout_id`),
  CONSTRAINT `wallet_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wallet_transactions_ibfk_2` FOREIGN KEY (`reference_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wallet_transactions_ibfk_3` FOREIGN KEY (`reference_cashout_id`) REFERENCES `cashouts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `admin_type` enum('wellness','basics') DEFAULT NULL,
  `admin_name` varchar(150) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL,
  `role` enum('super_admin','admin','staff_orders','staff_payments','staff_registration') NOT NULL DEFAULT 'admin',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_benefit_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `doc_type` enum('electric_bill','medical_abstract','hospital_bill','prescription','death_certificate','enrollment_form','child_id','birth_certificate') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
  `ai_analyzed_at` timestamp NULL DEFAULT NULL,
  `ai_result` text DEFAULT NULL,
  `ocr_text` mediumtext DEFAULT NULL,
  `ocr_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `basics_benefit_documents_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `basics_benefit_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `basics_benefit_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `benefit_type` enum('electric_subsidy','hospital_assistance','burial_assistance','baon_eskwela') NOT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `relationship_to_deceased` varchar(100) DEFAULT NULL,
  `deceased_address` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `admin_notes` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `basics_benefit_requests_ibfk_2` (`reviewed_by`),
  CONSTRAINT `basics_benefit_requests_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `basics_benefit_requests_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `basics_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `basics_birthday_gifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `birthday_year` smallint(6) NOT NULL,
  `greeted_at` timestamp NULL DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_year` (`member_id`,`birthday_year`),
  KEY `fk_basics_birthday_gifts_order_id` (`order_id`),
  CONSTRAINT `basics_birthday_gifts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_basics_birthday_gifts_order_id` FOREIGN KEY (`order_id`) REFERENCES `basics_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_cashouts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(10,2) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `basics_cashouts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_emergency_credit_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `amount_requested` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `amount_released` decimal(10,2) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `admin_notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `ai_analyzed_at` timestamp NULL DEFAULT NULL,
  `ai_result` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `basics_emergency_credit_requests_ibfk_2` (`reviewed_by`),
  CONSTRAINT `basics_emergency_credit_requests_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `basics_emergency_credit_requests_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `basics_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `basics_kyc_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `doc_type` enum('valid_id_1','valid_id_2','barangay_clearance','membership_application_form','membership_application_form_back','certificate_of_employment') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ai_analyzed_at` timestamp NULL DEFAULT NULL,
  `ai_result` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `basics_kyc_documents_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `employer_name` varchar(150) NOT NULL,
  `employer_contact` varchar(100) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `employer_address` varchar(255) DEFAULT NULL,
  `application_status` enum('pending','approved','denied') NOT NULL DEFAULT 'pending',
  `membership_status` enum('active','suspended','dormant','terminated') NOT NULL DEFAULT 'active',
  `is_community_partner` tinyint(1) NOT NULL DEFAULT 0,
  `referral_code` varchar(20) DEFAULT NULL,
  `referred_by` int(11) DEFAULT NULL,
  `weekly_credit_limit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `emergency_credit_limit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `offense_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `consecutive_on_time_payments` int(10) unsigned NOT NULL DEFAULT 0,
  `credit_limit_frozen` tinyint(1) NOT NULL DEFAULT 0,
  `suspended_until` date DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `admin_notes` varchar(255) DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `referral_code` (`referral_code`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `fk_basics_members_referred_by` (`referred_by`),
  CONSTRAINT `basics_members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `basics_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `basics_members_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `basics_admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_basics_members_referred_by` FOREIGN KEY (`referred_by`) REFERENCES `basics_members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'account',
  `title` varchar(120) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_unread` (`member_id`,`read_at`),
  KEY `member_created` (`member_id`,`created_at`),
  CONSTRAINT `basics_notifications_member` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `basics_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `basics_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `basics_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `basics_products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_order_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `status` varchar(30) NOT NULL,
  `actor_label` varchar(150) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `basics_order_status_history_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `basics_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','pending','confirmed','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'draft',
  `cancel_reason` varchar(255) DEFAULT NULL,
  `is_gift` tinyint(1) NOT NULL DEFAULT 0,
  `delivery_location` enum('home','company') NOT NULL DEFAULT 'home',
  `delivery_address` varchar(255) NOT NULL DEFAULT '',
  `archived_at` timestamp NULL DEFAULT NULL,
  `placed_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `out_for_delivery_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `basics_orders_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_payment_banks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `qr_image` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_payment_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `payment_for` enum('grocery','loan','other') NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `loan_request_id` int(11) DEFAULT NULL,
  `payment_method` varchar(100) NOT NULL,
  `destination_account` varchar(150) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_number` varchar(100) NOT NULL,
  `paid_at` datetime NOT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `ai_analyzed_at` timestamp NULL DEFAULT NULL,
  `ai_extracted_amount` decimal(10,2) DEFAULT NULL,
  `ai_extracted_reference` varchar(100) DEFAULT NULL,
  `ai_notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `order_id` (`order_id`),
  KEY `loan_request_id` (`loan_request_id`),
  KEY `basics_payment_submissions_ibfk_4` (`reviewed_by`),
  CONSTRAINT `basics_payment_submissions_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `basics_payment_submissions_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `basics_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `basics_payment_submissions_ibfk_3` FOREIGN KEY (`loan_request_id`) REFERENCES `basics_emergency_credit_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `basics_payment_submissions_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `basics_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `basics_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `penalty_rate` decimal(5,4) NOT NULL DEFAULT 0.0000,
  `penalty_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(100) DEFAULT NULL,
  `offense_number` tinyint(3) unsigned DEFAULT NULL,
  `is_late` tinyint(1) NOT NULL DEFAULT 0,
  `paid_at` timestamp NULL DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `member_id` (`member_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `basics_payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `basics_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `basics_payments_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `basics_payments_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `basics_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_payout_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `method` enum('gotyme','gcash','bank') NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_number` varchar(50) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_id` (`member_id`),
  CONSTRAINT `basics_payout_accounts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `basics_product_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_basics_product_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(20) NOT NULL,
  `category` varchar(100) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `srp` decimal(10,2) NOT NULL DEFAULT 0.00,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `flash_deal_price` decimal(10,2) DEFAULT NULL,
  `flash_deal_ends_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `address_line` varchar(150) DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `birthdate` date NOT NULL,
  `contact_number` varchar(30) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('pending','active','suspended') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `basics_wallet_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `type` enum('referral_override','cashout','cashout_reversal') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_order_id` int(11) DEFAULT NULL,
  `reference_cashout_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `basics_wallet_transactions_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `basics_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `cashouts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `account_number` varchar(50) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` varchar(255) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `processed_by` (`processed_by`),
  CONSTRAINT `cashouts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cashouts_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `communication_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `channel` enum('sms','email') NOT NULL,
  `module` enum('wellness','basics') DEFAULT NULL,
  `recipient` varchar(190) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed') NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `admin_type` enum('wellness','basics') DEFAULT NULL,
  `admin_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('basics_dormancy_weeks', '3'),
('basics_grace_period_days', '7'),
('basics_late_penalty_tier1', '0.03'),
('basics_late_penalty_tier2', '0.05'),
('basics_late_penalty_tier3', '0.05'),
('basics_sms_notifications_enabled', '1'),
('company_email', '');

INSERT INTO `basics_product_categories` (`name`, `sort_order`) VALUES
('Rice', 1), ('Food Essentials', 2), ('Cooking Products', 3), ('Beverages', 4),
('Homecare', 5), ('Personal Care', 6), ('Palengke Items', 7),
('Frozen Meat Products', 8), ('Bread & Snacks', 9);

INSERT INTO `basics_admins` (`username`, `password_hash`, `name`, `role`) VALUES
('admin', '$2y$10$qlZhenrepBdIA5pPs9moEeIO5BtoYbOjig7E63zW9K07bMSG6RbA.', 'TindaGo Administrator', 'super_admin');

SET FOREIGN_KEY_CHECKS = 1;
