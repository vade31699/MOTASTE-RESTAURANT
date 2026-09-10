<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects extremely common / trivially guessable passwords.
 *
 * Condensed mirror of is_common_password() in public/api/_password_policy.php
 * so the Laravel (users) and pure PHP (staff) auth systems apply the same
 * common-password policy. Kept as a standalone rule because Laravel's
 * Rules\Password defaults cannot carry custom closure rules.
 */
class NotCommonPassword implements ValidationRule
{
    /**
     * Blocklist of extremely common passwords (case-insensitive).
     */
    protected const COMMON_PASSWORDS = [
        '1234', '12345', '123456', '1234567', '12345678', '123456789',
        '1234567890', '123123', '112233', '121212', '1234qwer', '1234qwe',
        '1q2w3e', '1q2w3e4r', '1qaz2wsx', 'qazwsx', 'qwerty', 'qwerty123',
        'qwertyuiop', 'asdf', 'asdfgh', 'zxcvbn', 'zxcvbnm', 'abc123',
        'abcd1234', 'p@ssw0rd', 'p@ss', 'pa55word', 'passw0rd', 'password',
        'password1', 'password123', 'pass123', 'pass1234', 'passwd',
        'letmein', 'letmein123', 'welcome', 'welcome1', 'welcome123',
        'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess', 'football',
        'baseball', 'superman', 'batman', 'starwars', 'master', 'michael',
        'jessica', 'jennifer', 'charlie', 'shadow', 'mypass', 'mypassword',
        'trustno1', 'hunter2', 'freedom', 'whatever', 'secret', 'solo',
        'flower', 'admin', 'admin123', 'admin1234', 'administrator', 'root',
        'toor', 'manager', 'motaste', 'motaste123', 'motaste2026', 'staff',
        'staff123', 'cashier', 'cashier123', 'inventory', 'inventory123',
        '000000', '111111', '654321', '696969', '88888888', 'abc123456',
        'google', 'gmail', 'gmail123', 'coffee', 'summer', 'winter',
        'lovely', 'lovely1', 'zaq12wsx', 'xsw21qaz', 'qwe123', 'asd123',
        'q1w2e3r4', 'q1w2e3',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->isCommon($value)) {
            $fail('This password is too common. Choose a more unique password.');
        }
    }

    protected function isCommon(string $password): bool
    {
        $candidate = mb_strtolower(trim($password));

        if ($candidate === '') {
            return true;
        }

        // Purely numeric passwords are always considered common.
        if (preg_match('/^[0-9]+$/', $candidate) === 1) {
            return true;
        }

        // Same character repeated the whole way (e.g. "aaaaaaaa").
        if (preg_match('/^(.)\1+$/u', $candidate) === 1) {
            return true;
        }

        // Simple ascending/descending runs ("abcdefgh", "87654321").
        if (preg_match(
            '/^(?:0123456789|abcdefghij|abcdefgh|abcdefg|abcdef|9012345678|9876543210|987654321|87654321|7654321|654321|54321|4321|321)$/',
            $candidate
        ) === 1) {
            return true;
        }

        // Keyboard walks ("qwerty", "1qaz2wsx", "zaq12wsx").
        if (preg_match(
            '/^(?:qwertyuiop|qwertyui|qwertyu|qwerty|asdfghjkl|asdfghj|asdfgh|zxcvbnm|zxcvbn|1qaz2wsx|2wsx3edc|3edc4rfv|qazwsx|zaqwsx|zaq12wsx|xsw21qaz|1q2w3e4r|1q2w3e|q1w2e3r4|q1w2e3|!@#\$%\^&\*)$/',
            $candidate
        ) === 1) {
            return true;
        }

        // Exact blocklist match.
        if (in_array($candidate, self::COMMON_PASSWORDS, true)) {
            return true;
        }

        // Common passwords with trailing digit/punctuation decorations
        // ("Password1!", "welcome123").
        $decorated = (string) preg_replace('/[0-9!@#$]+$/', '', $candidate);
        if ($decorated !== $candidate && $decorated !== ''
            && in_array($decorated, self::COMMON_PASSWORDS, true)) {
            return true;
        }

        // Common leet-speak substitutions ("P@ssw0rd" -> "password").
        $unleeted = str_replace(
            ['@', '4', '0', '1', '3', '5', '$'],
            ['a', 'a', 'o', 'i', 'e', 's', 's'],
            $candidate
        );
        if ($unleeted !== $candidate && in_array($unleeted, self::COMMON_PASSWORDS, true)) {
            return true;
        }

        return false;
    }
}
