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

## Deploying

`.github/workflows/deploy.yml.disabled` is an FTP deploy workflow. Before
enabling it (rename back to `deploy.yml`), point this repo at TindaGo's own
GitHub repository, add TindaGo's FTP secrets, and fill in the live database
credentials in `config/database.php` on the server.
