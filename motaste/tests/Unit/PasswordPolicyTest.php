<?php

require_once __DIR__ . '/../../public/api/_password_policy.php';

test('is_password_reused detects a password matching a stored hash', function () {
    $hash = password_hash('March031699!', PASSWORD_DEFAULT);

    expect(is_password_reused('March031699!', [$hash]))->toBeTrue();

    // Blank/absent hashes are ignored, but a real match anywhere still trips.
    expect(is_password_reused('March031699!', [null, '', $hash]))->toBeTrue();

    expect(is_password_reused('Different-Pass-1', [$hash]))->toBeFalse();
    expect(is_password_reused('', [$hash]))->toBeFalse();
    expect(is_password_reused('March031699!', []))->toBeFalse();
    expect(is_password_reused('March031699!', ['not-a-hash']))->toBeFalse();
});
