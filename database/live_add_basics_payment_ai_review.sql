-- =====================================================================
-- JMC Foodies Basics — LIVE: AI-assisted payment proof review.
--
-- Adds columns to cache the result of running a payment proof image
-- through Google Gemini's vision API (basics_analyze_payment_proof() in
-- basics/includes/functions.php) — extracted amount/reference number,
-- compared against what the member typed, shown on
-- basics/admin/payment_submissions.php so an admin can spot mismatches
-- before confirming a payment. Advisory only — never auto-confirms.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_payment_submissions
  ADD COLUMN ai_analyzed_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN ai_extracted_amount DECIMAL(10,2) NULL DEFAULT NULL,
  ADD COLUMN ai_extracted_reference VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN ai_notes VARCHAR(255) NULL DEFAULT NULL;
