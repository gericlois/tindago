<?php
/*
 * EMAIL CONFIGURATION — EXAMPLE (Gmail SMTP)
 * =============================================
 * Copy this file to email.php (which is gitignored, since it holds a real
 * account password) and fill in your own values.
 *
 * GMAIL_SMTP_PASSWORD must be a 16-character Gmail App Password, NOT your
 * normal Gmail login password — generate one at
 * https://myaccount.google.com/apppasswords (requires 2-Step Verification
 * to be enabled on the account first).
 *
 * Leave GMAIL_SMTP_USERNAME blank to disable email sending entirely —
 * send_email() silently skips instead of erroring, so the rest of the app
 * keeps working even before this is configured.
 */

define('GMAIL_SMTP_USERNAME', ''); // e.g. yourstore@gmail.com
define('GMAIL_SMTP_PASSWORD', ''); // 16-character App Password, no spaces
define('GMAIL_SMTP_FROM_NAME', 'TindaGo');
