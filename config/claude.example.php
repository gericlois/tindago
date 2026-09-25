<?php
/*
 * CLAUDE API CONFIGURATION — EXAMPLE (Anthropic, console.anthropic.com)
 * =============================================
 * Copy this file to claude.php (which is gitignored, since it holds a real
 * API key) and fill in your own value from your Anthropic Console.
 *
 * Used only for basics_check_payment_proof() (basics/includes/functions.php)
 * — reads the amount/reference number off a submitted payment proof image
 * and flags a mismatch for the admin to review on
 * basics/admin/payment_submissions.php. It never confirms or rejects a
 * payment automatically.
 *
 * Leave ANTHROPIC_API_KEY blank to disable the check entirely —
 * basics_check_payment_proof() marks every submission 'error' (not
 * checked) instead of erroring, same "silently degrade" pattern already
 * used by send_sms()/send_email() when their own keys are blank.
 *
 * Billed per call (usage-based, no free tier) — this runs once per
 * submitted payment, not on every page view.
 */

define('ANTHROPIC_API_KEY', '');
// Cheaper/faster model — plenty accurate for reading an amount and
// reference number off a receipt screenshot. Bump to 'claude-sonnet-5' if
// you find it's misreading images often.
define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
