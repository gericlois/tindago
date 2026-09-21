-- Adds a new "admin" role to basics_admins: full operational access to
-- every Basics admin page EXCEPT the three super_admin-only System tools
-- (Database Backup, Admin Management, Maintenance Mode). Distinct from the
-- existing narrow staff_orders/staff_payments roles, which stay unchanged.
-- Paste into phpMyAdmin on the live InfinityFree DB.
ALTER TABLE basics_admins
    MODIFY role ENUM('super_admin','admin','staff_orders','staff_payments') NOT NULL DEFAULT 'admin';
