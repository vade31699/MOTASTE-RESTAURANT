<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `staff_session_tokens.last_used_at` — the timestamp of the last staff
 * request that used a session token.
 *
 * Staff sessions used to end only at logout or after the token TTL (30 days),
 * so a closed browser stayed "logged in" for a month. resolveStaffSessionToken()
 * now drops any token nobody has used for STAFF_SESSION_IDLE_TIMEOUT_SECONDS
 * (30 minutes by default) and refreshes this column on every authenticated
 * request, which is what signs an inactive account out.
 *
 * Guarded by hasColumn() so it is a no-op wherever ensureStaffEnhancementSchema()
 * already added the column on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_session_tokens')) {
            return;
        }

        if (Schema::hasColumn('staff_session_tokens', 'last_used_at')) {
            return;
        }

        Schema::table('staff_session_tokens', function (Blueprint $table) {
            $table->timestamp('last_used_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('staff_session_tokens')) {
            return;
        }

        if (!Schema::hasColumn('staff_session_tokens', 'last_used_at')) {
            return;
        }

        Schema::table('staff_session_tokens', function (Blueprint $table) {
            $table->dropColumn('last_used_at');
        });
    }
};
