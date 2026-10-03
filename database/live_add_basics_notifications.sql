-- =====================================================================
-- JMC Foodies Basics — LIVE: in-app member notifications.
--
-- One row per notification shown on basics/notifications.php (and counted
-- on the navbar bell while read_at is NULL). Written by basics_notify() /
-- basics_add_notification() / basics_notify_all_members() in
-- basics/includes/functions.php for order, payment, benefit, loan,
-- earnings, account, announcement and admin-message events.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code. (If the
-- code goes live first nothing breaks — notifications are just skipped
-- until the table exists.)
-- =====================================================================

CREATE TABLE basics_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'account',
  title VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  link VARCHAR(255) DEFAULT NULL,
  read_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY member_unread (member_id, read_at),
  KEY member_created (member_id, created_at),
  CONSTRAINT basics_notifications_member FOREIGN KEY (member_id) REFERENCES basics_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
