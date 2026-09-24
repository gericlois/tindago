-- =====================================================================
-- JMC Foodies Basics — LIVE: choose Home or Company address at checkout.
--
-- basics_members.employer_address is a new, optional field (set at
-- application, or later via My Account / admin Edit Details) — there was
-- previously no company/office address anywhere in the schema.
--
-- basics_orders.delivery_location + delivery_address snapshot the admin's
-- choice at the moment the order is placed (basics/cart.php), so a later
-- change to the member's home or employer address never rewrites the
-- delivery address of an order already placed.
--
-- Existing orders are backfilled with delivery_location='home' and the
-- member's current home address, since "home" was the only option that
-- ever existed before this migration.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_members ADD COLUMN employer_address VARCHAR(255) NULL DEFAULT NULL AFTER position;

ALTER TABLE basics_orders ADD COLUMN delivery_location ENUM('home','company') NOT NULL DEFAULT 'home' AFTER is_gift;
ALTER TABLE basics_orders ADD COLUMN delivery_address VARCHAR(255) NOT NULL DEFAULT '' AFTER delivery_location;

UPDATE basics_orders o
JOIN basics_members bm ON bm.id = o.member_id
JOIN basics_users u ON u.id = bm.user_id
SET o.delivery_address = u.address
WHERE o.delivery_address = '';
