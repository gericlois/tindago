-- =====================================================================
-- JMC Foodies Basics — LIVE migration: add the "Staff (Registration)" role.
--
-- Registration staff can register new members (submitted as a pending
-- application for an admin to approve) and view users — nothing else.
--
-- Run ONCE in phpMyAdmin's SQL tab. Existing admin accounts keep their
-- current role; only the list of allowed values grows. Run this BEFORE
-- creating a Staff (Registration) account on the live site.
-- =====================================================================

ALTER TABLE basics_admins
    MODIFY role ENUM('super_admin','admin','staff_orders','staff_payments','staff_registration') NOT NULL DEFAULT 'admin';
