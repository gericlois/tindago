-- =====================================================================
-- JMC Foodies Basics — LIVE: automated payment-proof verification.
--
-- When a member submits a payment (basics/payments.php), the uploaded
-- proof image is now sent to the Claude API to read the amount and
-- reference number off the receipt/screenshot and compare them against
-- what the member typed in. Result is stored here so admins see it
-- immediately on basics/admin/payment_submissions.php without waiting
-- on anything — no automatic confirm/reject ever happens from this,
-- it's a flag for the human reviewer only.
--
-- Requires config/claude.php (see config/claude.example.php) with a real
-- ANTHROPIC_API_KEY — until that's filled in, every submission is simply
-- marked 'error' (not checked), same "silently degrade" pattern already
-- used by send_sms()/send_email() when their own API keys are blank.
--
-- Run ONCE in phpMyAdmin's SQL tab BEFORE deploying this code.
-- =====================================================================

ALTER TABLE basics_payment_submissions
  ADD COLUMN proof_check_status ENUM('pending','match','mismatch','no_proof','unsupported','error') NOT NULL DEFAULT 'pending' AFTER proof_image,
  ADD COLUMN proof_extracted_amount DECIMAL(10,2) NULL DEFAULT NULL AFTER proof_check_status,
  ADD COLUMN proof_extracted_reference VARCHAR(190) NULL DEFAULT NULL AFTER proof_extracted_amount,
  ADD COLUMN proof_check_notes VARCHAR(255) NULL DEFAULT NULL AFTER proof_extracted_reference;
