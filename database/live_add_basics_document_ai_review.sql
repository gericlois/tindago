-- =====================================================================
-- JMC Foodies Basics — LIVE: AI-assisted KYC and benefit document review.
--
-- Adds columns to cache the result of running an uploaded KYC document
-- (basics/admin/application_view.php) or benefit request document
-- (basics/admin/benefit_requests.php) through Google Gemini's vision API —
-- see basics_analyze_kyc_document() / basics_analyze_benefit_document() in
-- basics/includes/functions.php. ai_result is a small JSON blob (verdict,
-- detected document type, fields read, concerns). Advisory only — never
-- approves or denies anything.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_kyc_documents
  ADD COLUMN ai_analyzed_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN ai_result TEXT NULL DEFAULT NULL;

ALTER TABLE basics_benefit_documents
  ADD COLUMN ai_analyzed_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN ai_result TEXT NULL DEFAULT NULL;
