# MOTASTE Restaurant System

An online ordering and restaurant-management platform for **MOTASTE** (Batchoy, Silog, Fried Chicken, Breakfast, Drinks, Add-ons, and Specials). Customers order through a public site while staff manage orders, inventory, sales, reviews, highlights, and credentials from a staff dashboard.

## Stack

- **Backend:** Laravel (PHP 8.x) — used for routing and shared helpers; business logic lives in standalone PHP endpoints under `public/api/`
- **Database:** PostgreSQL (Laravel Cloud managed) — `staff`, `users`, `orders`, `inventory_items`, `customer_reviews`, `trusted_devices`, `login_attempts`, `staff_session_tokens`, and more
- **Frontend:** Vanilla HTML/CSS/JS (`public/index.html` for customers, `public/staff.html` for staff) + Chart.js/Boxicons/FontAwesome
- **Hosting:** Laravel Cloud (`https://motasterestaurant890.laravel.cloud`)

## Features

### Customer site (`index.html`)
- Menu browsing with categories (Batchoy, Silog, Fried Chicken, Breakfast, Drinks, Add-ons, Specials)
- Cart + online ordering with order tracking (status + preparation countdown via Server-Sent Events)
- Star reviews with daily per-customer limits
- Homepage highlights slideshow (admin-managed)
- Loyalty points lookup/redemption (points per peso)

### Staff dashboard (`staff.html`)
- **Roles:** Admin, Cashier, Inventory Manager (role-based access to sections)
- **Overview:** live metrics — pending/completed orders, revenue, prep time, low stock, best seller, sales analytics, receipt export (Excel)
- **Orders:** walk-in order builder, pending-order queue with prep timers, completion/refund/cancel
- **Inventory:** product CRUD, categories, stock, unit cost, reorder levels, availability, special-food images, low-stock alerts
- **Sales:** daily/weekly/monthly analytics + insights (busiest hours, best sellers, period comparison, PDF export)
- **Logs:** real-time activity + review management (publish/delete)
- **Account Management:** create/edit/delete Cashier & Inventory Manager accounts (Gmail-only, invite code confirmation)
- **Credentials (Admin only):** change the admin email/password (email-verified), manage trusted devices, view login history

## Security model

- **Password hashing:** `password_hash()` (bcrypt) — plaintext passwords are never stored
- **Login rate limiting:** 6 failed attempts per 15 minutes locks the account (`login_attempts`)
- **Device verification:** new browsers must confirm a 6-digit code emailed to the account before the device is trusted (`trusted_devices`, `login_verification_tokens`)
- **Session tokens:** after login the client stores an opaque bearer token (hashed server-side in `staff_session_tokens`) instead of the password. Logout revokes the token and destroys the PHP session
- **Endpoint gating:** staff endpoints require `requireStaffAuth()` / `requireAdminAuth()`; the Admin account can only be changed through the email-verified credentials flow
- **CSRF:** staff mutation endpoints validate an `X-CSRF-TOKEN`
- **Security headers:** `_security_headers.php` applied to API responses

## Project structure

```
app/            Laravel app (models, middleware, console commands)
public/
  index.html    Customer site
  staff.html    Staff dashboard
  script.js     Shared frontend logic (both sites)
  api/          Standalone PHP endpoints (auth, orders, inventory, reviews…)
  style.css     Styles
database/       Migrations + seeders
routes/         Laravel routes
scripts/        One-off dev/ops scripts
tests/          Pest feature/unit tests
```

## Local setup

```bash
cp .env.example .env    # configure DB (see .env for the production Postgres URL)
composer install
npm install
php artisan migrate
php artisan serve
```

Open `http://localhost:8000` for the customer site and `http://localhost:8000/staff` for the staff dashboard.

> **Local email:** verification emails use SMTP. The project is configured for Gmail SMTP (`smtp.gmail.com:587`) with an **App Password** (not the normal Gmail password):
>
> ```env
> MAIL_MAILER=smtp
> MAIL_HOST=smtp.gmail.com
> MAIL_PORT=587
> MAIL_USERNAME=dvidaddocs@gmail.com
> MAIL_PASSWORD=<16-char Gmail App Password>
> MAIL_FROM_ADDRESS="dvidaddocs@gmail.com"
> MAIL_FROM_NAME="MOTASTE"
> ```
>
> To create an App Password: enable 2-Step Verification at `myaccount.google.com/security`, then generate one at `myaccount.google.com/apppasswords`. Without valid credentials, `sendSystemEmail()` falls back to writing the message — including verification codes — to the server log.

## Deploying (Laravel Cloud)

1. Push to the connected Git repository (Laravel Cloud auto-deploys).
2. In the dashboard set the production environment variables (APP_KEY, DB_*, MAIL_* SMTP credentials).
3. Verify with `GET https://your-app.laravel.cloud/api/health.php` (returns `{"status":"ok","db":"ok"}`).

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Login says "Invalid credentials" with correct password | Admin/staff email was changed in the DB (e.g., by a script). Restore the row or use the email-verified credentials flow. |
| "Too many failed login attempts" | Brute-force lockout — wait 15 minutes; failed attempts are cleared on success. |
| New device can't log in, no email arrives | SMTP credentials missing/invalid — check `MAIL_*` in `.env` (Laravel Cloud dashboard for prod) and retry; the code then falls back to the server log. |
| All API calls return 504 | The hosting PHP runtime is down — check the Laravel Cloud dashboard (deployment status, logs, metrics) and restart/redeploy. |

## Maintenance

- One-off DB scripts live in `scripts/` and should be removed after use.
- Do **not** run smoke tests against the production site — tests connected to the production DB overwrote the admin account once (2026-08-12). Use a staging database.
