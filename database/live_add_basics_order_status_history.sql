-- =====================================================================
-- JMC Foodies Basics — LIVE: Order Status Trail.
--
-- Records every status change a Basics order goes through (Checking →
-- Preparing → In Transit → Delivered, or Cancelled at any point before
-- delivery) along with who made it, for the new "Order Trail" card on
-- basics/admin/order_view.php.
--
-- Only takes effect for orders placed/transitioned after this deploys —
-- existing orders will simply have no history rows (or a partial trail,
-- for one already mid-pipeline), which the order_view.php card handles by
-- just showing whatever rows exist.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

CREATE TABLE basics_order_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status VARCHAR(30) NOT NULL,
    actor_label VARCHAR(150) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES basics_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
