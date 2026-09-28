-- Rotates the 'basicsadmin' account to a newly generated strong password.
-- The plaintext password was shared with the site owner directly and is not stored anywhere in this repo.
UPDATE basics_admins SET password_hash = '$2y$10$mkZFDG7mOUMr64v6ASeoyuzExeae02UQxLQRvyYfgLfL0Rc2.vAx6' WHERE username = 'basicsadmin';
