# MORFINITY

A production-ready PHP 8.2/MySQL storefront and creative-platform website for MORFINITY Originals, MORFINITY Brands, and MORFINITY Production. It runs directly on Apache without Node.js or a long-running process.

## Requirements

- PHP 8.2+ with PDO MySQL, Fileinfo, session, JSON, and cURL extensions
- MySQL 8+ or MariaDB 10.5+
- Apache with `mod_rewrite` and `mod_headers`
- HTTPS in production

## Local setup

1. Copy `.env.example` to `.env` and set `APP_URL`, a random `APP_KEY`, and your database values.
2. Create an empty UTF-8 database. Import `database/schema.sql`, then `database/seed.sql`. Existing installations run the additive migration in `database/migrations/20260807_stripe_accounts.sql` after taking a database backup.
3. Create the first administrator: `php scripts/create-admin.php admin@example.com`. The password is hashed; it is never stored in source.
4. Point Apache at the repository root. On Hostinger, the repository contents belong directly in `public_html`. Ensure `uploads/`, `storage/logs/`, and `storage/private/` are writable by PHP.
5. Open `/admin/login`. Replace sample records and centralized settings before launch.

Never commit `.env`, customer data, exported databases, uploaded application artwork, or credentials.

## Hostinger deployment (GitHub “Deploy Web App”)

1. Push this repository to GitHub and connect it in Hostinger **Websites → Add website → Deploy Web App → GitHub**. Grant access only to the intended repository.
2. Select the reviewed production branch. Use the feature branch for preview first, then deploy the default branch after merging.
3. Deploy the repository root directly to `public_html`. `index.php` and `.htaccess` must be directly inside `public_html`. No build command or Node runtime is required. The root `.htaccess` denies direct access to application code, SQL, scripts, storage and legacy deployment folders.
4. In Hostinger hPanel, create a MySQL database and user. Record the database host, name, username, and password—do not put them in Git.
5. Open phpMyAdmin, select that database, import `database/schema.sql`, then `database/seed.sql` once.
6. Create `public_html/.env` manually from `.env.example`; it is ignored by Git. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://morfinity.io`, timezone, and the Hostinger database values. Quote `DB_PASS` if it contains spaces, `#`, `=`, or quotes. Generate `APP_KEY` with a password generator (32+ random characters).
7. Attach the production domain `morfinity.io` in hPanel. The sitemap and robots files already use this domain.
8. Enable Hostinger SSL and **Force HTTPS**. Verify the secure-cookie session works after doing so.
9. Import/create the admin securely. If Hostinger provides SSH, run `php scripts/create-admin.php you@domain.com`. Otherwise generate a password hash locally with `password_hash`, insert it through phpMyAdmin, and delete the plaintext immediately.
10. If SSH is enabled, run `php scripts/diagnose.php`. It checks PHP, extensions, required environment variables, writable directories, and database connectivity without printing secrets. It returns 404 over the web and is additionally blocked by `.htaccess`.
11. Test home, product, cart, checkout request, application upload, contact, newsletter, admin sign-in, product editing, mobile navigation and 404. Confirm PHP errors are not displayed and inspect `storage/logs/php.log` privately.
12. For future releases, work on a branch, back up database/uploads, review and merge, then redeploy the commit in Hostinger. Run new SQL migrations before code only when documented as backward-compatible; use a maintenance window otherwise. Never re-import `seed.sql` on an active store.

## Production checklist and placeholders

- Replace the sample logo treatment, SVG product art, products, prices, stock, brand copy, domain, business email, social URLs, shipping/returns rules, and legal jurisdiction.
- Optimize final photographs as WebP and supply meaningful product-specific alt descriptions if names are insufficient.
- Configure SMTP/transactional email before relying on automated confirmations. The legacy physical-product checkout continues to store an order request.
- Stripe-powered plans live at `/plans`. Only active Products/Prices with metadata `morfinity_entitlement_key=studio_access` are shown. Prices and totals are always resolved server-side.
- Stripe Checkout, Customer Portal, and the signed idempotent webhook at `/stripe/webhook` remain Test-mode only until `STRIPE_LIVE_MODE_ENABLED=true` is deliberately approved and configured.
- Confirm privacy, tax, consumer, cookie, shipping, and returns requirements with qualified advisers in each selling country.
- Back up the database and `uploads/`; restrict database privileges to this database.

## Production environment variables

Use these exact names in `public_html/.env`; replace every bracketed value and never commit the real file:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://morfinity.io
APP_TIMEZONE=Europe/Nicosia
APP_KEY="[32+ random characters]"
DB_HOST=localhost
DB_PORT=3306
DB_NAME="[Hostinger database name]"
DB_USER="[Hostinger database user]"
DB_PASS="[Hostinger database password]"
MAIL_TO="[business email]"
PAYMENT_MODE=order_request
STRIPE_SECRET_KEY="sk_test_[set privately in Hostinger]"
STRIPE_WEBHOOK_SECRET="whsec_[set privately in Hostinger]"
STRIPE_API_VERSION=2025-06-30.basil
STRIPE_CATALOG_TTL=300
STRIPE_LIVE_MODE_ENABLED=false
```

## Stripe Test-mode setup

1. Back up the production database and `uploads/`, then run `database/migrations/20260807_stripe_accounts.sql` once.
2. In Stripe Test mode, create Products and one-time or recurring Prices. Add `morfinity_entitlement_key=studio_access` to Product metadata (or Price metadata).
3. Put the Test secret key in the private Hostinger `.env`; never commit it.
4. Create a Stripe webhook endpoint for `https://morfinity.io/stripe/webhook`. Subscribe to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`, and `charge.refunded`. Put its signing secret in `.env`.
5. Configure the Stripe Customer Portal in Test mode.
6. Run `php scripts/diagnose.php` and `php tests/stripe_unit.php`, register a test customer, complete Checkout with a Stripe test card, and verify `/account` shows active `studio_access` only after a verified webhook.
7. Keep `STRIPE_LIVE_MODE_ENABLED=false`. Live mode requires separate explicit approval, live keys, a live webhook secret, legal/tax review, and a new end-to-end verification.

The application does not default to `root` or an empty password. Missing required database values produce a logged HTTP 500 configuration failure, never a redirect.

## Architecture

`index.php` is the route/controller entry point. `app/bootstrap.php` contains environment, database, session, security, upload, and rendering helpers. Views live in `app/views`, public assets in `assets`, SQL in `database`, and CLI-only operational scripts in `scripts`. URLs are rewritten by the root `.htaccess`.
