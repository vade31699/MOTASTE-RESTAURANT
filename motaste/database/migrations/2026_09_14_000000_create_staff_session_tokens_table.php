<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `staff_session_tokens` table.
 *
 * Despite its name, 2026_08_01_000002_create_staff_token_tables.php only ever
 * created `staff_invite_tokens` and `admin_credential_change_tokens` — this
 * table has only ever been created on demand by
 * ensureStaffEnhancementSchema()/ensureStaffSessionTokenTable(), and those
 * helpers log and continue if the CREATE fails. A deployment where that DDL was
 * rejected (permissions, a locked schema, a partial migration) therefore had no
 * table at all while logins kept reporting success: issueStaffSessionToken()
 * swallowed the insert error and returned the token anyway, so the browser held
 * a bearer with no backing row and every staff-gated request was rejected.
 *
 * Making it a real migration puts the table in the normal deploy path, where a
 * failure is visible in `php artisan migrate` instead of only in the log.
 *
 * Guarded by hasTable() so it is a no-op wherever the on-demand helper already
 * created the table with this exact shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_session_tokens')) {
            return;
        }

        Schema::create('staff_session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191);
            $table->string('role', 100)->nullable();
            // SHA-256 hash of the opaque bearer token (never the token itself).
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('email', 'staff_session_tokens_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_session_tokens');
    }
};
