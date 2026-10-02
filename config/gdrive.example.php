<?php
/*
 * GOOGLE DRIVE BACKUP UPLOAD — EXAMPLE
 * =============================================
 * Copy this file to gdrive.php (which is gitignored, since it holds a
 * secret) and fill in both values. Each hourly database backup is then
 * also uploaded to Google Drive through a small Google Apps Script web
 * app — see database/gdrive_backup_apps_script.gs for the script and the
 * one-time setup steps.
 *
 * GDRIVE_BACKUP_URL     the Apps Script web app URL ("Deploy > New
 *                       deployment > Web app"), ends in /exec
 * GDRIVE_BACKUP_SECRET  a long random string — must match SECRET in the
 *                       Apps Script exactly
 *
 * Leave GDRIVE_BACKUP_URL blank (or skip creating gdrive.php at all) to
 * keep backups on the server only — nothing else changes.
 */

define('GDRIVE_BACKUP_URL', '');
define('GDRIVE_BACKUP_SECRET', '');
