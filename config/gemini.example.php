<?php
/*
 * GOOGLE GEMINI API CONFIGURATION — EXAMPLE
 * =============================================
 * Copy this file to gemini.php (which is gitignored, since it holds a real
 * API key) and fill in your own value from
 * https://aistudio.google.com/apikey — free, no billing/credit card
 * required for the free tier's rate limits.
 *
 * Leave GEMINI_API_KEY blank to disable AI payment-proof analysis
 * entirely — basics_analyze_payment_proof() silently returns an error
 * instead of crashing, so the rest of the app keeps working even before
 * this is configured.
 */

define('GEMINI_API_KEY', '');
