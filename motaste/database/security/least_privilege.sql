-- ============================================================================
-- MOTASTE — Database Least-Privilege Setup (Security Requirement 5.2)
--
-- Run this ONCE as a PostgreSQL superuser / database owner against the
-- production database. It creates two dedicated, non-superuser roles:
--
--   1. motaste_migrator  — runs `php artisan migrate` at deploy time
--                          (owns the public schema / tables).
--
--   2. motaste_app       — the role the web application uses at runtime
--                          (SELECT/INSERT/UPDATE/DELETE only; no DDL, no
--                          superuser, no CREATEDB, no CREATEROLE).
--
-- After running this, configure the deployed app with:
--
--   DB_USERNAME=motaste_app
--   DB_PASSWORD=<the password you set for motaste_app>
--
-- Migrations on deploy use the migrator account, NOT the app account:
--
--   DB_USERNAME=motaste_migrator php artisan migrate --force
--
-- NEVER point the application at a superuser (postgres/root) account.
-- ============================================================================

-- Replace these with real, strong, unique passwords before running.
\set app_password 'March031699'
\set migrator_password 'March031699'

-- ---------------------------------------------------------------------------
-- 1. Create the migration-owner role (no superuser, no CREATEDB/CREATEROLE).
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'motaste_migrator') THEN
        CREATE ROLE motaste_migrator LOGIN PASSWORD 'CHANGE_ME_MIGRATOR_PASSWORD' NOSUPERUSER NOCREATEDB NOCREATEROLE;
    ELSE
        RAISE NOTICE 'role motaste_migrator already exists';
    END IF;
END
$$;

-- ---------------------------------------------------------------------------
-- 2. Create the dedicated application role (least privilege).
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'motaste_app') THEN
        CREATE ROLE motaste_app LOGIN PASSWORD 'CHANGE_ME_APP_PASSWORD' NOSUPERUSER NOCREATEDB NOCREATEROLE;
    ELSE
        RAISE NOTICE 'role motaste_app already exists';
    END IF;
END
$$;

-- Belt and braces: never inherit anything beyond the granted privileges.
ALTER ROLE motaste_app NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
ALTER ROLE motaste_migrator NOSUPERUSER NOCREATEDB NOCREATEROLE;

-- ---------------------------------------------------------------------------
-- 3. Schema ownership: the migrator owns the public schema objects.
--
--    The app role gets USAGE so it can reach the tables, plus CREATE on the
--    schema ONLY because this application's API helpers create tables and
--    columns at runtime as a deployment fallback (see public/api/_helpers.php
--    Schema::create guards). If migrations always run before the app is
--    reached, you may remove the CREATE grant to lock the app down further.
-- ---------------------------------------------------------------------------
GRANT USAGE, CREATE ON SCHEMA public TO motaste_app;
GRANT ALL ON SCHEMA public TO motaste_migrator;

-- ---------------------------------------------------------------------------
-- 4. Grants for tables/sequences/views that already exist.
--    (Run `ALTER DEFAULT PRIVILEGES` below first on a fresh DB, then migrate.)
-- ---------------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA public TO motaste_app;
GRANT USAGE, SELECT ON ALL SEQUENCES                  IN SCHEMA public TO motaste_app;
GRANT EXECUTE ON ALL FUNCTIONS                        IN SCHEMA public TO motaste_app;

-- ---------------------------------------------------------------------------
-- 5. Default privileges so every table the migrator creates later (including
--    the runtime on-demand helper tables) is automatically readable/writable
--    by the app role without re-granting.
-- ---------------------------------------------------------------------------
ALTER DEFAULT PRIVILEGES FOR ROLE motaste_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES    TO motaste_app;
ALTER DEFAULT PRIVILEGES FOR ROLE motaste_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES                  TO motaste_app;
ALTER DEFAULT PRIVILEGES FOR ROLE motaste_migrator IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS                        TO motaste_app;

-- ---------------------------------------------------------------------------
-- 6. Explicitly deny DDL and cluster-level rights to the app role.
--    Rows/Tables owned by motaste_migrator cannot be dropped/altered/truncated
--    by motaste_app (PostgreSQL object ownership rules).
-- ---------------------------------------------------------------------------
REVOKE ALL PRIVILEGES ON DATABASE current_database() FROM PUBLIC;
REVOKE CREATE ON DATABASE current_database() FROM motaste_app;

-- ---------------------------------------------------------------------------
-- 7. Verification queries (run separately as superuser).
-- ---------------------------------------------------------------------------
-- SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles
--   WHERE rolname IN ('motaste_app', 'motaste_migrator');
-- \dp   -- lists all table privileges; motaste_app should show r/w only