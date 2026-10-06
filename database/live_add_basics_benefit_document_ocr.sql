-- =====================================================================
-- JMC Digital — LIVE migration: AI "Read Text" on benefit documents.
-- Stores the text Gemini transcribes from a benefit request document
-- (basics/admin/benefit_requests.php → Read Text), so admins can reopen
-- and copy it without another AI call. Purely additive.
-- Paste into phpMyAdmin's SQL tab (run once).
-- =====================================================================

ALTER TABLE basics_benefit_documents
    ADD COLUMN ocr_text MEDIUMTEXT NULL AFTER ai_result,
    ADD COLUMN ocr_at TIMESTAMP NULL DEFAULT NULL AFTER ocr_text;
