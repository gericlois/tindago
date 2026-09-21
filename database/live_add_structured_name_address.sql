-- =====================================================================
-- JMC Foodies Basics — LIVE: add structured name/address fields to
-- basics_users, additive only.
--
-- `full_name` and `address` stay exactly as they are today — every existing
-- display, SMS, email, and receipt in the app keeps reading those two
-- columns unchanged. The new columns (first_name/middle_name/last_name,
-- address_line/barangay/city/province) are nullable and start empty for
-- every existing member — there is no automated parsing of existing real
-- names/addresses here (Filipino names have compound first/last names,
-- particles like "de la Cruz", suffixes, etc. that a naive split would get
-- wrong for real people). New registrations populate both the structured
-- fields and the legacy full_name/address (computed from the parts).
-- Existing members can fill theirs in via the new My Account page
-- (basics/account.php) whenever they choose to.
-- =====================================================================

ALTER TABLE basics_users
  ADD COLUMN first_name VARCHAR(100) NULL AFTER full_name,
  ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name,
  ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_name,
  ADD COLUMN address_line VARCHAR(150) NULL AFTER address,
  ADD COLUMN barangay VARCHAR(100) NULL AFTER address_line,
  ADD COLUMN city VARCHAR(100) NULL AFTER barangay,
  ADD COLUMN province VARCHAR(100) NULL AFTER city;
