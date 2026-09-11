<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rejects a password change where the new password is the same as the account's
 * CURRENT password.
 *
 * Without this, the forgot-password flow happily "resets" a password to the
 * value that was already set — the reset reports success, yet the credential
 * the user already knew keeps working. That defeats the point of a reset
 * (e.g. after a suspected compromise).
 *
 * The staff portal stores its own hash in the `staff` table (synced from
 * `users`), so both are checked.
 */
class NotCurrentPassword implements ValidationRule
{
    public function __construct(private readonly string $email)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        foreach ($this->currentPasswordHashes() as $hash) {
            if (Hash::check($value, $hash)) {
                $fail('Your new password must be different from your current password.');
                return;
            }
        }
    }

    /**
     * Every stored hash that currently authenticates this account.
     *
     * @return list<string>
     */
    private function currentPasswordHashes(): array
    {
        $email = strtolower(trim($this->email));
        if ($email === '') {
            return [];
        }

        $hashes = [];

        $userHash = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('password');
        if (is_string($userHash) && $userHash !== '') {
            $hashes[] = $userHash;
        }

        // The staff table is optional in minimal deployments; if it is missing
        // the users hash above already covers the reset path.
        try {
            if (Schema::hasTable('staff')) {
                $staffHash = DB::table('staff')->whereRaw('LOWER(email) = ?', [$email])->value('password_hash');
                if (is_string($staffHash) && $staffHash !== '') {
                    $hashes[] = $staffHash;
                }
            }
        } catch (Throwable) {
            // Best effort — never block a reset because the lookup failed.
        }

        return $hashes;
    }
}
