-- =====================================================================
-- JMC Foodies Basics — LIVE: AI pre-screen for Emergency Cash Loan requests.
--
-- Caches the result of basics_prescreen_emergency_request() (in
-- basics/includes/functions.php) on the request row — a JSON blob with the
-- member's repayment facts plus Gemini's short summary of strengths and
-- risks, shown on basics/admin/emergency_credit.php. Advisory only — never
-- approves or denies anything.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_emergency_credit_requests
  ADD COLUMN ai_analyzed_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN ai_result TEXT NULL DEFAULT NULL;
