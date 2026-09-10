<?php

use Illuminate\Support\Facades\Route;

// Self-registration is intentionally disabled (routes removed in commit
// 4fbeac5): accounts are created by admin invite only. The
// RegisteredUserController is kept as Breeze scaffolding. This test guards
// against the route being accidentally re-added without re-introducing the
// strong password policy tests (min 12 mixedCase + numbers + NotCommonPassword
// for password reset; Password::defaults() + NotCommonPassword elsewhere).
test('self-registration is disabled', function () {
    expect(Route::has('register'))->toBeFalse();
});
