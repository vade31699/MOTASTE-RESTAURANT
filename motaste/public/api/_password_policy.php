<?php

declare(strict_types=1);

/**
 * Shared password policy for every pure PHP auth endpoint.
 *
 * Enforces the "Strong Password Policy" requirements:
 *  - Minimum length (8+; admin password changes 12+)
 *  - Complexity: upper, lower, digit required
 *  - Rejection of extremely common / trivially guessable passwords
 *
 * Every password-setting endpoint includes this file and calls
 * enforce_password_policy() before hashing and persisting.
 */

const PASSWORD_POLICY_MIN_LENGTH = 8;
const PASSWORD_POLICY_ADMIN_MIN_LENGTH = 12;

/**
 * A blocklist of extremely common passwords (top of the most-used lists:
 * SecLists Top-1000 style, plus MOTASTE-specific trivial guesses).
 * Comparison is case-insensitive.
 */
const PASSWORD_POLICY_COMMON_PASSWORDS = [
    '1234', '12345', '123456', '1234567', '12345678', '123456789',
    '1234567890', '12345678910', '123123', '112233', '121212',
    '1234qwer', '1234qwe', '123qwe', '1q2w3e', '1q2w3e4r',
    '1qaz2wsx', 'qazwsx', 'qwerty', 'qwerty123', 'qwertyuiop',
    'azerty', 'qwertz', 'asdf', 'asdfgh', 'zxcvbn', 'zxcvbnm',
    'abc123', 'abcd1234', 'a1b2c3d4', 'p@ssw0rd', 'p@ss', 'pa55word',
    'passw0rd', 'password', 'password1', 'password123', 'password12',
    'password!', 'password1!', 'pass123', 'pass1234', 'passwd',
    'letmein', 'letmein123', 'welcome', 'welcome1', 'welcome123',
    'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess',
    'football', 'baseball', 'superman', 'batman', 'starwars',
    'master', 'michael', 'jessica', 'jennifer', 'charlie',
    'shadow', 'mypass', 'mypassword', 'trustno1', 'hunter2',
    'freedom', 'whatever', 'secret', 'solo', 'flower',
    'admin', 'admin123', 'admin1234', 'administrator', 'root',
    'toor', 'manager', 'motaste', 'motaste123', 'motaste2026',
    'staff', 'staff123', 'cashier', 'cashier123', 'inventory',
    'inventory123', '0000', '000000', '111111', '121314',
    '654321', '696969', '88888888', 'abc123456', 'aaa111',
    'google', 'gmail', 'gmail123', 'coffee', 'summer',
    'winter', 'spring2026', 'iloveyou1', 'lovely', 'lovely1',
    'zaq12wsx', 'xsw21qaz', 'qwe123', 'asd123', 'q1w2e3r4',
];

/**
 * Validate a candidate password against the shared policy.
 *
 * @param  string  $password   The candidate plaintext password.
 * @param  bool    $forAdmin   When true, requires the elevated minimum length.
 * @return string|null         A human-readable error message, or null when the
 *                             password satisfies the policy.
 */
function check_password_policy(string $password, bool $forAdmin = false): ?string
{
    $minLength = $forAdmin ? PASSWORD_POLICY_ADMIN_MIN_LENGTH : PASSWORD_POLICY_MIN_LENGTH;
    $length = mb_strlen($password);

    if ($length < $minLength) {
        return "Password must be at least {$minLength} characters";
    }

    if ($length > 191) {
        // bcrypt truncates at 72 bytes; cap well below the 191-char column so
        // giant inputs cannot create pathological hashing workloads.
        return 'Password must be no more than 191 characters';
    }

    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must include uppercase, lowercase, and a number';
    }

    if (is_common_password($password)) {
        return 'This password is too common. Choose a more unique password';
    }

    return null;
}

/**
 * True when the password is in the common-password blocklist, is purely
 * numeric, or collapses to a trivial repeated/s predictable pattern.
 */
function is_common_password(string $password): bool
{
    $candidate = mb_strtolower(trim($password));

    if ($candidate === '') {
        return true;
    }

    // Purely numeric passwords are always considered common.
    if (preg_match('/^[0-9]+$/', $candidate)) {
        return true;
    }

    // Same character repeated the whole way (e.g. "aaaaaaaa", "1111!!!!").
    if (preg_match('/^(.)\1+$/', $candidate)) {
        return true;
    }

    // Simple ascending/descending runs ("abcdefgh", "87654321").
    if (preg_match('/^(?:0123456789|abcdefghij|abcdefgh|abcdefg|abcdef|9012345678|9876543210|987654321|87654321|7654321|654321|54321|4321|321)$/', $candidate)) {
        return true;
    }

    // Keyboard walks ("qwerty", "1qaz2wsx", "zaq12wsx").
    if (preg_match('/^(?:qwertyuiop|qwertyui|qwertyu|qwerty|asdfghjkl|asdfghj|asdfgh|zxcvbnm|zxcvbn|1qaz2wsx|2wsx3edc|3edc4rfv|qazwsx|zaqwsx|zaq12wsx|xsw21qaz|1q2w3e4r|1q2w3e|q1w2e3r4|q1w2e3|!@#$%^&*|!QAZ2WSX)$/', $candidate)) {
        return true;
    }

    // Exact match against the blocklist.
    if (in_array($candidate, PASSWORD_POLICY_COMMON_PASSWORDS, true)) {
        return true;
    }

    // Common passwords with the usual decorations: trailing digit(s),
    // trailing "!", or digit-for-letter substitutions (a@4, e3, i1, o0, s$).
    $decorated = preg_replace('/[0-9!@#$]+$/', '', $candidate);
    if ($decorated !== $candidate && $decorated !== '' && in_array($decorated, PASSWORD_POLICY_COMMON_PASSWORDS, true)) {
        return true;
    }

    $unleeted = str_replace(['@', '4', '0', '1', '3', '5', '$'], ['a', 'a', 'o', 'i', 'e', 's', 's'], $candidate);
    if ($unleeted !== $candidate && in_array($unleeted, PASSWORD_POLICY_COMMON_PASSWORDS, true)) {
        return true;
    }

    return false;
}

/**
 * Convenience guard for endpoints: when the candidate password violates the
 * policy, respond with a 422 JSON error and terminate the request.
 *
 * @param  string $password The candidate plaintext password.
 * @param  bool   $forAdmin Apply the elevated admin minimum length.
 */
function enforce_password_policy(string $password, bool $forAdmin = false): void
{
    $error = check_password_policy($password, $forAdmin);

    if ($error !== null) {
        http_response_code(422);
        echo json_encode(['error' => $error]);
        exit;
    }
}
