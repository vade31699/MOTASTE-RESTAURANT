<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
     * Whether the given address belongs to the Admin account. Public password
     * recovery is restricted to the Admin: cashier and inventory accounts can
     * never start a reset, even with a known address.
     */
    public static function isAdminEmail(?string $email): bool
    {
        $normalized = strtolower(trim((string) $email));
        $adminEmail = static::adminEmail();

        return $normalized !== '' && $adminEmail !== null && $normalized === $adminEmail;
    }
}
