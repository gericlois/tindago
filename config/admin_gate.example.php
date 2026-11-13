<?php
/*
 * ADMIN PANEL GATE — EXAMPLE
 * =============================================
 * Copy this file to admin_gate.php (which is gitignored) and set a long
 * random key. While a key is set, every /basics/admin/ page answers
 * "404 Not Found" — the same as a page that doesn't exist — unless the
 * browser has been let in by opening, once:
 *
 *   https://yourdomain/basics/admin/login.php?key=YOUR_KEY
 *
 * That sets a cookie for a year; logged-in admins also pass. Changing the
 * key locks every browser out again. Blank = gate off (admin panel visible).
 */

define('ADMIN_GATE_KEY', '');
