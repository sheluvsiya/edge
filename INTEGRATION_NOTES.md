# 3D Edge integration changes

Only the shared bootstrap/configuration paths were adjusted in this pass.

## Required local setup

1. Copy `.env.example` to `.env` in the project root.
2. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` to match your local XAMPP MySQL instance. The supplied SQL dump names the database `3d_edge`.
3. Set SMTP credentials and `MAIL_FROM_ADDRESS` to enable email delivery.
4. Set the Google reCAPTCHA v3 site and secret keys to enable contact/quote verification.
5. Set PayPal sandbox credentials before testing checkout. Configure BobGo only if its tracking API is enabled for your account.
6. Keep `.env` out of Git and do not share it in project ZIPs.

## Changes made

- `Start_session.php` now uses the shared environment configuration for the database, sets `utf8mb4`, avoids exposing database exception details, and enforces expiry for both customer and admin database sessions.
- `config/database.php` delegates to the shared bootstrap instead of declaring a competing `db()` function.
- SMTP settings in `send_email.php` now use the same configuration constants as the rest of the application.
- reCAPTCHA keys are read from `.env` rather than being embedded in PHP source.
- `.gitignore` excludes local environment files and logs; `.env.example` contains placeholders only.

## Validation status

All 57 non-vendor PHP files pass `php -l`. This is a static syntax check only. Browser workflows, database operations against a live MySQL server, SMTP delivery, reCAPTCHA verification, PayPal sandbox transactions, and BobGo requests still require runtime testing with the local server and valid test credentials.
