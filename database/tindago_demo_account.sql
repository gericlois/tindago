-- TindaGo demo / tester account
--
-- An approved, active Store Partner for trying out the member site, with
-- username "demo". The password isn't written here — ask the developer, or
-- set your own: replace the password_hash below with the output of
--   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
-- Weekly purchase limit PHP 3,000 (the admin approval default), and no
-- forced password change on first login.
--
-- Import after tindago_schema.sql (phpMyAdmin > Import, or
-- mysql -uroot tindago < database/tindago_demo_account.sql). Safe to re-run:
-- it resets the demo account's password, status, and limits.
--
-- On the live site, deactivate the account when testing is done (Admin > Members, or:
-- UPDATE basics_users SET status = 'suspended' WHERE username = 'demo';).

INSERT INTO basics_users
  (full_name, first_name, middle_name, last_name, address, address_line, barangay, city, province,
   birthdate, contact_number, email, username, password_hash, must_change_password, status)
VALUES
  ('Demo Tester', 'Demo', NULL, 'Tester', 'Demo Street, Poblacion, Demo City, Demo Province',
   'Demo Street', 'Poblacion', 'Demo City', 'Demo Province',
   '1990-01-01', '09000000000', NULL, 'demo',
   '$2y$10$QPC3GE5Xv.lhcz4u5.MJP.h3nZHjwgbZYYkh1BX.hWXVswmYQfHgy', 0, 'active')
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash), must_change_password = 0, status = 'active';

SET @demo_user_id = (SELECT id FROM basics_users WHERE username = 'demo');

INSERT INTO basics_members
  (user_id, employer_name, position, declaration_accepted_at, application_status, membership_status,
   weekly_credit_limit, emergency_credit_limit, reviewed_at, admin_notes)
VALUES
  (@demo_user_id, 'Demo Sari-Sari Store', 'Owner / Proprietor', NOW(), 'approved', 'active',
   3000.00, 0.00, NOW(), 'Demo / tester account')
ON DUPLICATE KEY UPDATE
  application_status = 'approved', membership_status = 'active', weekly_credit_limit = 3000.00,
  suspended_until = NULL, credit_limit_frozen = 0;
