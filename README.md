# TindaGo

A weekly grocery credit line for employees of partner companies: members
order groceries online and settle after delivery. PHP + MySQL, runs on XAMPP.

## Local setup

1. Put the project at `C:\xampp\htdocs\tindago` (served at `http://localhost/tindago/`).
2. Create the database and import the schema:
   ```
   mysql -uroot -e "CREATE DATABASE tindago CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
   mysql -uroot tindago < database/tindago_schema.sql
   ```
3. Copy each `config/*.example.php` to the same name without `.example`
   (`database.php`, `email.php`, `sms.php`, `gemini.php`, optionally `gdrive.php`).
   Blank API keys simply disable that feature (email, SMS, AI review, Drive backup).
4. Admin panel: `http://localhost/tindago/basics/admin/login.php`
   — username `admin`, password `ChangeMe123!`. Change it right away
   (Admin > Admins), then set the company email/address in Admin > Settings
   and add your receiving accounts in Admin > Payment Banks.

## Layout

- `basics/` — member site; `basics/admin/` — admin panel. The site root
  redirects to `basics/`. (The folder and the `basics_*` table names are kept
  from the codebase TindaGo was built from.)
- `basics/includes/module.php` — brand name, logo, colors, and member nav.
- `config/constants.php` — `SITE_NAME`, `BASE_URL`, Facebook link.
- `assets/img/tindago.jpg` — master logo; `assets/img/basics/` holds the
  resized logo and app icons generated from it.

## Deploying (cPanel)

Every push to `main` runs `.github/workflows/deploy.yml`, which lints the PHP
and uploads changed files over FTPS to `public_html/`.

One-time setup:

1. **GitHub secrets** (repo > Settings > Secrets and variables > Actions):
   `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` — from cPanel > FTP Accounts.
   Optional: `FTP_SERVER_DIR` (default `public_html/`; use `/` if the FTP
   account's directory is already `public_html`) and `FTP_PROTOCOL`
   (default `ftps`; set `ftp` only if the host has no FTPS).
2. **Database**: cPanel > MySQL Databases — create a database and a user,
   add the user to the database with ALL PRIVILEGES, then import
   `database/tindago_schema.sql` in phpMyAdmin.
3. **Server config**: in cPanel File Manager, create
   `public_html/config/database.php` (copy `config/database.example.php`) and
   fill in the live database name, user and password. It's never uploaded by
   the deploy and never committed. Optionally create `email.php`, `sms.php`,
   `gemini.php`, `gdrive.php` the same way; until then those features are
   simply off.
4. **SSL**: cPanel > SSL/TLS Status — run AutoSSL for the domain. The root
   `.htaccess` redirects all traffic to HTTPS.
