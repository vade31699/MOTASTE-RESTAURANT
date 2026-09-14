<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class Staff extends Authenticatable
{
    use Notifiable;

    protected $table = 'staff';

    protected $fillable = [
        'full_name',
        'email',
        'password_hash',
        'role',
        'phone',
        'is_active',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Email of the single Admin account, or null when none is configured.
     */
    public static function adminEmail(): ?string
    {
        try {
            $email = static::query()->where('role', 'Admin')->value('email');
        } catch (Throwable) {
            // The staff table is optional in minimal deployments.
            return null;
        }

        return is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null;
    }

    /**
     * Whether the given address belongs to the Admin account recorded as a
     * `staff` row (older deployments). Password recovery is staff-only, so this
     * is used to keep the admin address out of the staff reset flow.
     */
    public static function isAdminEmail(?string $email): bool
    {
        $normalized = strtolower(trim((string) $email));
        $adminEmail = static::adminEmail();

        return $normalized !== '' && $adminEmail !== null && $normalized === $adminEmail;
    }

    /**
     * Whether this address is the Admin account, in any deployment shape: the
     * dedicated `admins` table, or the role = 'Admin' row older installs kept
     * in `staff`.
     */
    public static function isAdminAccount(?string $email): bool
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return false;
        }

        try {
            if (static::isAdminEmail($normalized)) {
                return true;
            }

            return Schema::hasTable('admins')
                && DB::table('admins')->whereRaw('LOWER(email) = ?', [$normalized])->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Why this address cannot start a staff password reset, or null when it can.
     *
     * - 'admin':   the address belongs to the Admin account. A staff reset can
     *              never apply to it, and the caller can say so plainly.
     * - 'unknown': no matching account (or the lookup itself failed — this
     *              fails closed). Callers must keep this vague so addresses
     *              cannot be probed.
     */
    public static function passwordResetRejection(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return 'unknown';
        }

        if (static::isAdminAccount($normalized)) {
            return 'admin';
        }

        try {
            // Staff accounts live in the `staff` table, so that is the only
            // table this flow consults.
            return DB::table('staff')->whereRaw('LOWER(email) = ?', [$normalized])->exists() ? null : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Whether this address may start (and complete) a staff password reset.
     */
    public static function canResetPassword(?string $email): bool
    {
        return static::passwordResetRejection($email) === null;
    }
}
