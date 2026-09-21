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
- **Device verification:** every staff login must confirm a 6-digit code emailed to the account before a session is created — trusted devices never bypass it (`trusted_devices` is an informational record of verified logins; `login_verification_tokens` holds the one-time codes)
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

> **CAPTCHA (staff login):** brute-force CAPTCHA uses Google reCAPTCHA v2 (visible checkbox). Register the site at [Google reCAPTCHA Admin](https://www.google.com/recaptcha/admin/create) with type **v2 "I'm not a robot" Checkbox**, add your production domain (`motaste.laravel.cloud`) as an allowed domain, then set the variables below. Without them, login still works — the CAPTCHA challenge is simply skipped / reported as unavailable:
>
> ```env
> RECAPTCHA_V2_SITE_KEY=0123456789abcdef...   # public sitekey, served to the login page
> RECAPTCHA_V2_SECRET_KEY=0123456789abcdef... # server-side, used to verify tokens
> ```
>
> The sitekey is fetched by `script.js` from `GET /api/get_recaptcha_sitekey.php` and passed to `grecaptcha.render()` (explicit rendering), so `staff.html` needs no templating. v2 keys are **not** interchangeable with v3 keys — register a v2 site.

## Deploying (Laravel Cloud)

1. Push to the connected Git repository (Laravel Cloud auto-deploys).
2. In the dashboard set the production environment variables (APP_KEY, DB_*, MAIL_* SMTP credentials, and the reCAPTCHA v2 CAPTCHA keys below).
3. Verify with `GET https://your-app.laravel.cloud/api/health.php` (returns `{"status":"ok","db":"ok"}`).

### reCAPTCHA v2 CAPTCHA (required in production)

The staff-login CAPTCHA silently degrades to "unavailable" if these are missing, and `authenticate_staff.php` then **fails open** (skips verification) — so set them or the brute-force CAPTCHA layer is not real:

| Variable | Where it comes from |
| --- | --- |
| `RECAPTCHA_V2_SITE_KEY` | Google reCAPTCHA Admin → your v2 "I'm not a robot" Checkbox site (public) |
| `RECAPTCHA_V2_SECRET_KEY` | Same site (secret — rotate if it's ever shared) |

Make sure the site's **Allowed domains** in the Google reCAPTCHA Admin console includes the production domain (`motaste.laravel.cloud`) — otherwise token verification fails with `invalid-input-response` / `domain mismatch` even with correct keys.

Post-deploy check: `GET https://your-app.laravel.cloud/api/get_recaptcha_sitekey.php` should return the production sitekey (not an empty string).

### Staff-login security limits (optional)

All optional. Each falls back to the default shown when unset, and is clamped to a minimum of `1` — a blank or invalid value can never disable a protection:

| Variable | Default | Meaning |
| --- | --- | --- |
| `STAFF_LOGIN_MAX_ATTEMPTS` | `5` | Failed attempts (per account) before the account locks. |
| `STAFF_LOGIN_LOCKOUT_MINUTES` | `2` | Window those failures are counted over. |
| `STAFF_LOGIN_IP_MAX_ATTEMPTS` | `20` | Failed attempts (per IP, across all accounts) before the IP locks. |
| `STAFF_LOGIN_IP_LOCKOUT_MINUTES` | `2` | Window for the IP-scoped counter. |
| `STAFF_LOGIN_CAPTCHA_THRESHOLD` | `3` | Failed attempts (per account or per IP, within the lockout window) before the CAPTCHA checkbox is demanded — the 4th submit is gated. Suspicious-login detection can still demand it earlier. |
| `STAFF_SESSION_LIFETIME_SECONDS` | `2592000` (30 days) | Lifetime of the persistent staff session cookie. |
| `STAFF_SESSION_TOKEN_TTL_DAYS` | `30` | Lifetime of an issued session token (revoked on password/email change). |
| `STAFF_SESSION_IDLE_TIMEOUT_SECONDS` | `1800` (30 minutes) | Inactivity window: a staff session unused for this long is dropped, so closing the browser (or leaving a tab untouched) signs the account out 30 minutes later. Every authenticated staff request refreshes the window. |

### Off-site database mirror (Supabase)

`php artisan db:sync` copies new and updated rows from the production database into a hosted Postgres mirror on Supabase and deletes rows that disappeared from the source, so the mirror tracks the live database within about a cycle. It is driven by the Laravel Cloud scheduler (`routes/console.php`); `php artisan db:sync:status` reports what it has done. Cadence and the mirrored table list live in `config/db_sync.php`.

The scheduler is **off until it is enabled per environment**: open the environment's **App cluster** in the dashboard, turn on the **Scheduler** toggle, then save and redeploy. Without it `db:sync` is never invoked and Supabase stays empty. Environment variable changes also only take effect on a new deployment.

| Variable | Value |
| --- | --- |
| `DB_SYNC_BACKUP` | `supabase` — the connection name in `config/database.php` |
| `BACKUP_DB_HOST` | `aws-0-<region>.pooler.supabase.com` |
| `BACKUP_DB_PORT` | `5432` |
| `BACKUP_DB_DATABASE` | `postgres` |
| `BACKUP_DB_USERNAME` | `postgres.<project-ref>` |
| `BACKUP_DB_PASSWORD` | Supabase → Project Settings → Database → Database password |

Use the **session pooler** (port 5432). The transaction pooler (6543) drops the session state that creating the mirror's tables needs, and the direct `db.<ref>.supabase.co` host is IPv6-only.

## Restoring from the mirror (Supabase)

Treat this as a data-recovery path, not a backup rotation:

- It is **near-real-time, not versioned**. A row deleted in production is deleted from the mirror on the next cycle and **cannot be recovered from it** — it protects against losing the database, not against losing the rows someone deleted an hour ago.
- The mirror holds **data only**: no foreign keys, no indexes beyond the `id` primary key, no views (`DbSync::defineColumns()`). A restore rebuilds the schema with migrations and imports rows into it.
- Framework tables (`sessions`, `cache`, `jobs`, …) and short-lived credentials (`staff_session_tokens`, `login_verification_tokens`, `staff_invite_tokens`, `trusted_devices`) are **excluded on purpose** — see the comment in `config/db_sync.php`. After any restore every staff member is signed out and devices must be re-trusted. `api_event_logs` is not mirrored either, so API audit history is not recoverable.
- `sync_state` and `sync_events` are the mirror's own bookkeeping. **Never import them into the application database.**

The mirror keeps updating while you work, so export and verify in one sitting — or turn the Scheduler toggle off for the duration. Rehearse the whole procedure against a throwaway database before the real one.

### 1. Restore the schema

```bash
php artisan migrate --force
```

Run this against the target (a fresh Laravel Cloud database, or a scratch one for the rehearsal). It builds the real schema — foreign keys, indexes, views, sequences — plus every table the mirror never carried.

### 2. Import the rows

```bash
export SUPABASE_URL="postgresql://postgres.<ref>:<password>@aws-0-<region>.pooler.supabase.com:5432/postgres"
export TARGET_URL="postgresql://<user>:<password>@<host>:5432/<database>?sslmode=require"

# Data only. The mirror's bookkeeping must not land in the application database.
pg_dump "$SUPABASE_URL" --data-only --no-owner --no-privileges \
  --exclude-table=sync_state --exclude-table=sync_events -f mirror.sql
psql "$TARGET_URL" -v ON_ERROR_STOP=1 -f mirror.sql
```

A data-only dump neither orders tables nor defers constraints, and the target enforces foreign keys (`staff.user_id` and `admins.user_id` → `users.id`), so those tables fail if `users` has not landed first. Dump `users` separately and load it first:

```bash
pg_dump "$SUPABASE_URL" --data-only --no-owner --no-privileges -t users -f users.sql
pg_dump "$SUPABASE_URL" --data-only --no-owner --no-privileges \
  --exclude-table=users --exclude-table=sync_state --exclude-table=sync_events -f rest.sql
psql "$TARGET_URL" -v ON_ERROR_STOP=1 -f users.sql -f rest.sql
```

**With no Postgres client installed**, `php scripts/restore_from_mirror.php` does the import and the check in plain PHP: it reads the mirrored table list from the mirror itself, writes parents before children, upserts every row by id (never deleting anything), resets the sequences and reports the comparison. Run it with `--help` for the options.

If `psql`/`pg_dump` are not available, any SQL client (Supabase's SQL editor, `cloud database:open`) can run the same two statements per table. The mirror can also be replayed with the sync command itself — `DB_SYNC_SOURCE=supabase DB_SYNC_BACKUP=<target> php artisan db:sync` — but **never** by editing the production environment's variables: a forgotten `DB_SYNC_SOURCE=supabase` would make the scheduled job copy the mirror *over* production and delete production rows the mirror lacks. Run it with inline variables from a machine that can reach both, use the same `users`-first order, and drop the `sync_state`/`sync_events` tables it creates in the target afterwards.

### 3. Reset the sequences

Imported ids came from the mirror, so the target's sequences still sit at 1 and the first insert in the application would collide with an existing id. Run this **on the target** before the application writes anything — `scripts/restore_from_mirror.php --apply` does it for you:

```sql
DO $$
DECLARE
    r record;
    seq text;
    max_id bigint;
BEGIN
    FOR r IN
        SELECT table_name FROM information_schema.columns
        WHERE table_schema = 'public' AND column_name = 'id'
        ORDER BY table_name
    LOOP
        seq := pg_get_serial_sequence(format('public.%I', r.table_name), 'id');
        IF seq IS NULL THEN
            CONTINUE;                      -- not a sequence-backed id (e.g. a view)
        END IF;
        EXECUTE format('SELECT COALESCE(max(id), 0) FROM public.%I', r.table_name) INTO max_id;
        IF max_id > 0 THEN
            PERFORM setval(seq, max_id);   -- next id will be max_id + 1
        END IF;
    END LOOP;
END $$;
```

### 4. Verify the restore

Row counts alone are not enough — a table can have the right number of rows and the wrong ones. Every mirrored table shares an `id`, and `id` is the one column with an identical type on both sides, so compare the count plus a digest of the ids:

```bash
TABLES=$(psql "$SUPABASE_URL" -tAc "select table_name from sync_state where table_name <> '__cycle__' order by table_name")
DIGEST="select count(*) || ':' || coalesce(min(id)::text,'-') || ':' || coalesce(max(id)::text,'-') || ':' || coalesce(md5(string_agg(id::text, ',' order by id)), 'empty') from"

for t in $TABLES; do
  mirror=$(psql "$SUPABASE_URL" -tAc "$DIGEST $t")
  target=$(psql "$TARGET_URL" -tAc "$DIGEST $t")
  if [ "$mirror" = "$target" ]; then echo "ok   $t $target"; else echo "DIFF $t mirror=$mirror target=$target"; fi
done
```

Taking the table list from the mirror's own `sync_state` keeps the check aligned with what the mirror actually carries. Every line should read `ok`; a `DIFF` means that table did not import fully — or that production changed after the dump, in which case re-export.

`php scripts/restore_from_mirror.php --verify` runs the same check without any Postgres client: row counts plus an md5 of the ids per table, foreign key orphans, and whether the target's sequences are caught up. It never writes, and exits `2` when anything differs, so it can gate a scripted restore.

Then confirm the data is usable by the application, not just present:

| Check | Expected |
| --- | --- |
| `GET /api/health.php` against the restored database | `{"status":"ok","db":"ok"}` |
| Staff login | succeeds — proves `staff` rows and password hashes survived |
| Admin dashboard → orders and reviews | lists real data rather than empty tables |
| `select count(*) from staff s left join users u on u.id = s.user_id where u.id is null;` | `0` (the same for `admins`) |
| `select nextval(pg_get_serial_sequence('orders','id')) > (select max(id) from orders);` | `true` — or simply create one record through the app |

Finally, point the application at the restored database, drop the scratch database used for the rehearsal, and re-enable the Scheduler toggle if you turned it off.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Login says "Invalid credentials" with correct password | Admin/staff email was changed in the DB (e.g., by a script). Restore the row or use the email-verified credentials flow. |
| "Too many failed login attempts" | Brute-force lockout — wait 15 minutes; failed attempts are cleared on success. |
| New device can't log in, no email arrives | SMTP credentials missing/invalid — check `MAIL_*` in `.env` (Laravel Cloud dashboard for prod) and retry; the code then falls back to the server log. |
| All API calls return 504 | The hosting PHP runtime is down — check the Laravel Cloud dashboard (deployment status, logs, metrics) and restart/redeploy. |
| Supabase mirror is empty or has stopped updating | The Laravel Cloud **Scheduler** toggle is off (it is opt-in per environment), or environment variables were changed without redeploying. `php artisan db:sync:status` shows the last cycle; `php artisan db:sync` forces one. |

## Data classification (DPA)

Personal information handled by the system, per the Privacy Notice (`/privacy`):

| Sensitivity | Fields | Where |
| --- | --- | --- |
| High (secrets) | password hashes, session token hashes, verification code hashes, invite code hashes | `staff`, `staff_session_tokens`, `login_verification_tokens`, `staff_invite_tokens` |
| Medium (personal) | customer name/phone/email/address; staff names/emails/roles | `orders`, `staff` |
| Low (operational) | IPs, device labels/fingerprints, login timestamps, audit events | `staff_login_history`, `trusted_devices`, `order_activity_logs`, `api_event_logs` |

Known logging exception: when SMTP is not configured, `sendSystemEmail()` falls back to writing the email body — **including device-verification codes — to the server log** so logins remain possible. This is an availability trade-off; configure `MAIL_*` in production so it never triggers.

Retention: order/sales history is kept for only 3 months and is archived + automatically deleted after that (the administered CSV archive email is the permanent copy); login/security logs are staged monthly. See `routes/console.php` and the retention banner in the staff dashboard.

## Maintenance

- One-off DB scripts live in `scripts/` and should be removed after use. `restore_from_mirror.php` is the deliberate exception: it is the psql-free restore path documented above.
- The mirror's `sync_events` table only ever grows: one row per table per cycle in which rows moved. Trim it occasionally (`delete from sync_events where created_at < now() - interval '30 days';`) — `sync_state` is current-state only and needs no pruning.
- Do **not** run smoke tests against the production site — tests connected to the production DB overwrote the admin account once (2026-08-12). Use a staging database.
