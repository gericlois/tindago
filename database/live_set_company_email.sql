-- Changes the company email shown in the site header (top bar) and footer,
-- on both Wellness and Basics. Paste into phpMyAdmin on the live InfinityFree DB.
-- (Same value can also be edited at Wellness Admin > Settings > Company Email.)
INSERT INTO settings (setting_key, setting_value) VALUES ('company_email', 'jmcdigital2026@gmail.com')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
