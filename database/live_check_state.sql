-- Diagnostic only — no changes made. Run this and send me the full result.
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'users', 'admins', 'basics_users', 'basics_admins',
    'activity_log', 'basics_members', 'basics_kyc_documents',
    'basics_products', 'basics_emergency_credit_requests',
    'basics_payment_submissions', 'basics_benefit_requests',
    'basics_benefit_documents', 'basics_payout_accounts'
  )
ORDER BY TABLE_NAME;

SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'wellness_enrolled';

SELECT COUNT(*) AS basics_only_accounts_in_users FROM users WHERE wellness_enrolled = 0;

SELECT COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'basics_products' AND COLUMN_NAME = 'category';

SELECT COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'basics_kyc_documents' AND COLUMN_NAME = 'doc_type';
