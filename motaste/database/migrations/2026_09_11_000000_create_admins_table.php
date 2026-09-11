<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split the Admin account out of the shared `staff` table.
 *
 * Historically the single Admin account lived in `staff` as a row with
 * role = 'Admin'. That made admin credentials indistinguishable from Cashier /
 * Inventory Manager accounts at the schema level. This migration gives the
 * Admin its own table and moves the existing row in place, so the current
 * Admin keeps their email, password hash and last-active timestamp.
 *
 * Every other admin-adjacent table (trusted_devices, staff_login_history,
 * staff_session_tokens, staff_invite_tokens, admin_credential_change_tokens,
 * login_attempts) is keyed by EMAIL, not by a staff foreign key — so moving the
 * row preserves trusted devices, session tokens and login history with no
 * re-linking required.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admins')) {
            Schema::create('admins', function (Blueprint $table) {
                $table->id();
                // Nullable so an admin row can exist even when no matching
                // `users` row has been mirrored yet (see saveStaffAccountsSnapshot).
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('full_name', 191)->nullable();
                $table->string('email', 191)->unique();
                $table->string('password_hash', 191)->nullable();
                $table->string('role', 100)->default('Admin');
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();
            });
        }

        // Move the existing Admin row (if any) out of `staff` and into `admins`.
        if (Schema::hasTable('staff') && Schema::hasColumn('staff', 'role')) {
            $admins = DB::table('staff')
                ->whereRaw('LOWER(role) = ?', ['admin'])
                ->get();

            foreach ($admins as $admin) {
                $email = strtolower(trim((string)($admin->email ?? '')));
                if ($email === '') {
                    continue;
                }

                $row = [
                    'user_id' => $admin->user_id ?? null,
                    'full_name' => $admin->full_name ?? null,
                    'email' => $email,
                    'password_hash' => $admin->password_hash ?? null,
                    'role' => 'Admin',
                    'last_active_at' => $admin->last_active_at ?? null,
                    'created_at' => $admin->created_at ?? now(),
                    'updated_at' => $admin->updated_at ?? now(),
                ];

                // Keyed by email so re-running is idempotent.
                DB::table('admins')->updateOrInsert(['email' => $email], $row);
            }

            // Now that the Admin lives in `admins`, remove the duplicate from
            // `staff` so admin accounts no longer appear among staff accounts.
            DB::table('staff')->whereRaw('LOWER(role) = ?', ['admin'])->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('admins')) {
            // Move admins back into `staff` before dropping the table so a
            // rollback does not lose the credential.
            if (Schema::hasTable('staff')) {
                foreach (DB::table('admins')->get() as $admin) {
                    $email = strtolower(trim((string)($admin->email ?? '')));
                    if ($email === '') {
                        continue;
                    }

                    DB::table('staff')->updateOrInsert(
                        ['email' => $email],
                        [
                            'user_id' => $admin->user_id ?? null,
                            'full_name' => $admin->full_name ?? null,
                            'role' => 'Admin',
                            'password_hash' => $admin->password_hash ?? null,
                            'created_at' => $admin->created_at ?? now(),
                            'updated_at' => $admin->updated_at ?? now(),
                        ]
                    );
                }
            }

            Schema::dropIfExists('admins');
        }
    }
};
