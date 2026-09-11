<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The single administrator account.
 *
 * The Admin used to live in the `staff` table as a row with role = 'Admin';
 * it now has its own `admins` table so admin credentials are stored separately
 * from Cashier / Inventory Manager accounts.
 */
class Admin extends Authenticatable
{
    use Notifiable;

    protected $table = 'admins';

    protected $fillable = [
        'full_name',
        'email',
        'password_hash',
        'role',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }
}
