<?php

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Mail\AdminResetAttempt;
use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Password recovery is a STAFF feature: staff accounts live in the `staff`
 * table, and the flow only ever consults that table. The Admin keeps its
 * credentials in the dedicated `admins` table, so an admin address is never
 * eligible — asking for a reset with it fails exactly like an unknown address.
 * These tests pin that contract down.
 */

/**
 * POST the forgot-password form and return the 6-digit code that was emailed.
 * The code is only ever stored as a hash, so the test reads it from the
 * Mailable that Mail::fake() captured.
 */
function requestPasswordResetCode(User $user, $test): string
{
    $test->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors();

    $code = null;
    Mail::assertSent(PasswordResetCode::class, function (PasswordResetCode $mail) use (&$code) {
        $code = $mail->code;
        return true;
    });
    expect($code)->not->toBeNull();

    return $code;
}

/**
 * Verify a code and pull the reset token out of the redirect to the reset
 * form. The token is handed directly to the verified browser — no second
 * email with a reset link is ever sent.
 */
function verifyCodeAndGetResetToken(User $user, $test, string $code): string
{
    $response = $test->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->not->toBeNull();
    preg_match('#/reset-password/([^/?]+)#', (string)$location, $matches);
    expect($matches)->toHaveCount(2);

    return $matches[1];
}

/**
 * Create a staff account: the users row (the reset store) plus the matching
 * `staff` row that the staff portal authenticates against.
 */
function createStaffAccount(string $role = 'Cashier'): User
{
    $staff = User::factory()->create();

    DB::table('staff')->insert([
        'user_id' => $staff->id,
        'full_name' => 'Staff',
        'role' => $role,
        'email' => $staff->email,
        'password_hash' => $staff->password,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $staff;
}

/**
 * Create the Admin account. It only exists in the `admins` table (plus its
 * users row) — never in `staff`.
 */
function createAdminAccount(): User
{
    $admin = User::factory()->create();

    DB::table('admins')->insert([
        'full_name' => 'Test Admin',
        'email' => $admin->email,
        'password_hash' => $admin->password,
        'role' => 'Admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $admin;
}

test('reset password screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);

    // Recovery belongs to the staff portal, so the page leads back there.
    $response->assertSee('href="'.route('staff').'"', false);
});

test('a verification code is emailed before any reset form is shown', function () {
    Mail::fake();

    $user = createStaffAccount('Cashier');

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $user->email);

    // The code email goes out — it is the only email in the flow.
    Mail::assertSent(PasswordResetCode::class);
});

test('an unknown email is rejected with a Please try again message', function () {
    Mail::fake();

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHasErrors('email');

    // Unknown addresses stay deliberately vague: only the admin address is
    // named, so a real staff address cannot be probed out of this form.
    $errors = session('errors')->get('email');
    expect($errors)->toContain('Please try again.');
    expect($errors)->not->toContain(PasswordResetLinkController::ADMIN_RECOVERY_MESSAGE);
    Mail::assertNothingSent();
});

test('a cashier can start a password reset', function () {
    Mail::fake();

    $cashier = createStaffAccount('Cashier');

    $this->post('/forgot-password', ['email' => $cashier->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $cashier->email);

    Mail::assertSent(PasswordResetCode::class);
});

test('an inventory manager can start a password reset', function () {
    Mail::fake();

    $inventory = createStaffAccount('Inventory Manager');

    $this->post('/forgot-password', ['email' => $inventory->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $inventory->email);

    Mail::assertSent(PasswordResetCode::class);
});

test('the admin email cannot start a staff password reset', function () {
    Mail::fake();

    $admin = createAdminAccount();

    // The admin address lives in the `admins` table, which this flow never
    // consults — the form gives the same vague message as an unknown address
    // so the admin account cannot be probed.
    $this->post('/forgot-password', ['email' => $admin->email])
        ->assertSessionHasErrors('email');

    $errors = session('errors')->get('email');
    expect($errors)->toContain('Please try again.');
    expect($errors)->not->toContain(PasswordResetLinkController::ADMIN_RECOVERY_MESSAGE);

    // No verification code is ever issued for the admin address — the only
    // mail that goes out is the alert to the Admin (covered separately).
    Mail::assertNotSent(PasswordResetCode::class);

    // And the code step refuses it too, so no session state can be built up.
    $this->post('/forgot-password/verify', ['email' => $admin->email, 'code' => '000000'])
        ->assertSessionHasErrors('code');
});

test('the admin rejection is indistinguishable from an unknown address', function () {
    Mail::fake();

    $admin = createAdminAccount();

    $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => $admin->email])
        ->assertRedirect('/forgot-password');

    // The person who typed the admin address sees the same vague message as
    // an unknown email — the admin account must not be identifiable.
    $this->get('/forgot-password')
        ->assertStatus(200)
        ->assertSee('Please try again.', false)
        ->assertDontSee(PasswordResetLinkController::ADMIN_RECOVERY_MESSAGE, false);
});

test('the admin email is rejected even when it is a legacy staff row', function () {
    Mail::fake();

    // Older deployments kept the Admin as a role = 'Admin' row in `staff`.
    // Such a row must not become a reset loophole, and it gets the same vague
    // message as an unknown address.
    $user = User::factory()->create();
    DB::table('staff')->insert([
        'user_id' => $user->id,
        'full_name' => 'Legacy Admin',
        'role' => 'Admin',
        'email' => $user->email,
        'password_hash' => $user->password,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasErrors('email');

    expect(session('errors')->get('email'))->toContain('Please try again.');
    expect(session('errors')->get('email'))->not->toContain(PasswordResetLinkController::ADMIN_RECOVERY_MESSAGE);
    Mail::assertNotSent(PasswordResetCode::class);
});

test('the admin is emailed when a reset is attempted with the admin address', function () {
    Mail::fake();

    $admin = createAdminAccount();

    $this->post('/forgot-password', ['email' => $admin->email])
        ->assertSessionHasErrors('email');

    Mail::assertSent(AdminResetAttempt::class, function (AdminResetAttempt $mail) use ($admin) {
        return $mail->hasTo($admin->email)
            && $mail->email === $admin->email
            && $mail->occurredAt !== ''
            && $mail->ipAddress !== '';
    });
});

test('staff and unknown addresses never alert the admin', function () {
    Mail::fake();

    $staff = createStaffAccount();

    // A real staff account starts the flow normally...
    $this->post('/forgot-password', ['email' => $staff->email])
        ->assertSessionHasNoErrors();

    // ...and an unknown address is rejected without an alert.
    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHasErrors('email');

    Mail::assertNotSent(AdminResetAttempt::class);
    Mail::assertSent(PasswordResetCode::class);
});

test('the admin notice body carries the attempt details', function () {
    // Mail::fake() never renders the template, so render it here: a typo in the
    // view would otherwise only surface in production.
    $body = (new AdminResetAttempt(
        'admin@example.com',
        '2026-09-14 15:04:05',
        '203.0.113.7',
        'TestAgent/1.0'
    ))->render();

    expect($body)->toContain('MOTASTE Admin Password Recovery Notice');
    expect($body)->toContain('admin@example.com');
    expect($body)->toContain('2026-09-14 15:04:05');
    expect($body)->toContain('203.0.113.7');
    expect($body)->toContain('TestAgent/1.0');
    // The notice must never imply the admin password was changed.
    expect($body)->toContain('the admin password was NOT changed');
});

test('repeated attempts do not flood the admin inbox', function () {
    Mail::fake();

    $admin = createAdminAccount();

    foreach (range(1, 3) as $ignored) {
        $this->post('/forgot-password', ['email' => $admin->email])
            ->assertSessionHasErrors('email');
    }

    // The per-address notice window collapses the burst into one email.
    Mail::assertSent(AdminResetAttempt::class, 1);
});

test('the admin email cannot be redeemed even with a reset token', function () {
    Mail::fake();

    $admin = createAdminAccount();

    // Re-check at the write step: a stale token must never be redeemable for
    // the admin account, whose credentials live in `admins`.
    $this->post('/reset-password', [
        'token' => 'any-token',
        'email' => $admin->email,
        'password' => 'New-Str0ng-Passw0rd',
        'password_confirmation' => 'New-Str0ng-Passw0rd',
    ])->assertSessionHasErrors('email');

    expect(session('errors')->get('email'))
        ->toContain('We could not find an account with that email address.');

    // The admin credential is untouched.
    $adminHash = DB::table('admins')
        ->whereRaw('LOWER(email) = ?', [strtolower($admin->email)])
        ->value('password_hash');
    expect(Hash::check('New-Str0ng-Passw0rd', (string) $adminHash))->toBeFalse();
});

test('forgot-password is rate limited per IP', function () {
    Mail::fake();

    $staff = createStaffAccount();

    // The limiter allows five requests per minute per IP.
    foreach (range(1, 5) as $ignored) {
        $this->post('/forgot-password', ['email' => $staff->email])
            ->assertRedirect();
    }

    // The sixth is refused before the controller runs.
    $this->post('/forgot-password', ['email' => $staff->email])
        ->assertStatus(429);
});

test('forgot-password verify is rate limited per IP', function () {
    Mail::fake();

    // The limiter allows ten verification attempts per minute per IP.
    foreach (range(1, 10) as $ignored) {
        $this->post('/forgot-password/verify', ['email' => 'nobody@example.com', 'code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    $this->post('/forgot-password/verify', ['email' => 'nobody@example.com', 'code' => '000000'])
        ->assertStatus(429);
});

test('reset-password is rate limited per IP', function () {
    Mail::fake();

    $payload = [
        'token' => 'invalid-token',
        'email' => 'nobody@example.com',
        'password' => 'New-Str0ng-Passw0rd',
        'password_confirmation' => 'New-Str0ng-Passw0rd',
    ];

    // The limiter allows six write attempts per minute per IP. An invalid
    // token is still rejected, but it counts toward the limit.
    foreach (range(1, 6) as $ignored) {
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    $this->post('/reset-password', $payload)->assertStatus(429);
});

test('forgot-password cancel is rate limited per IP', function () {
    // Cancelling only clears session state, but it is still throttled per IP.
    foreach (range(1, 10) as $ignored) {
        $this->post('/forgot-password/cancel')->assertRedirect();
    }

    $this->post('/forgot-password/cancel')->assertStatus(429);
});

test('the reset form is reached only after the code is verified', function () {
    Mail::fake();

    $user = createStaffAccount();

    $code = requestPasswordResetCode($user, $this);

    // A wrong code is rejected and does not advance the flow.
    $wrong = str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT);

    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $wrong])
        ->assertSessionHasErrors('code');

    // The correct code redirects straight to the reset form.
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

test('reset password screen can be rendered with a verified code', function () {
    Mail::fake();

    $user = createStaffAccount();

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $this->get('/reset-password/'.$token)
        ->assertStatus(200);
});

test('password can be reset with valid token', function () {
    Mail::fake();

    $user = createStaffAccount();

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'New-Str0ng-Passw0rd',
        'password_confirmation' => 'New-Str0ng-Passw0rd',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('password.success'));

    // The new password must actually be stored...
    $user->refresh();

    expect(Hash::check('New-Str0ng-Passw0rd', $user->password))->toBeTrue();

    // ...while the old password no longer works and the new one can log in.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->post('/login', ['email' => $user->email, 'password' => 'New-Str0ng-Passw0rd'])
        ->assertSessionHasNoErrors();
});

test('password reset syncs the hash into the staff table', function () {
    Mail::fake();

    $user = createStaffAccount('Inventory Manager');

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'New-Str0ng-Passw0rd',
        'password_confirmation' => 'New-Str0ng-Passw0rd',
    ])->assertSessionHasNoErrors()->assertRedirect(route('password.success'));

    // The staff portal (authenticate_staff.php) reads staff.password_hash, so
    // the reset is only complete once that row carries the new hash too.
    $staffHash = DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
        ->value('password_hash');

    expect(Hash::check('New-Str0ng-Passw0rd', (string) $staffHash))->toBeTrue();
});

test('password cannot be reset to the current password', function () {
    Mail::fake();

    $user = createStaffAccount();
    $current = 'March031699!';
    $user->forceFill(['password' => Hash::make($current)])->save();

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    // Reusing the password that is already set must be rejected — otherwise
    // the "reset" leaves the old credential working.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => $current,
        'password_confirmation' => $current,
    ])->assertSessionHasErrors('password');

    // The stored hash is untouched.
    $user->refresh();
    expect(Hash::check($current, $user->password))->toBeTrue();
});

test('password cannot be reset to the staff portal current password', function () {
    Mail::fake();

    $user = createStaffAccount();
    $staffCurrent = 'Staff-Current-9';

    // The staff portal credential is what the account logs in with, so a reset
    // must not be allowed to land on the portal's current hash either.
    DB::table('staff')
        ->whereRaw('LOWER(email) = ?', [$user->email])
        ->update(['password_hash' => Hash::make($staffCurrent)]);

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => $staffCurrent,
        'password_confirmation' => $staffCurrent,
    ])->assertSessionHasErrors('password');
});

test('password reset requires the elevated 12-character policy', function () {
    Mail::fake();

    $user = createStaffAccount();

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    // 11 characters — meets the default policy but not the reset policy.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Str0ng-Pas0',
        'password_confirmation' => 'Str0ng-Pas0',
    ])->assertSessionHasErrors('password');
});

test('the verification code is single-use', function () {
    Mail::fake();

    $user = createStaffAccount();

    $code = requestPasswordResetCode($user, $this);

    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // Replaying the same code must not advance the flow a second time.
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasErrors('code');
});

test('the verification code self-destructs after 3 failed attempts', function () {
    Mail::fake();

    $user = createStaffAccount();

    $code = requestPasswordResetCode($user, $this);
    $wrong = str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT);

    foreach (range(1, 3) as $attempt) {
        $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $wrong])
            ->assertSessionHasErrors('code');
    }

    // Even the correct code no longer works after the lockout.
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasErrors('code');
});

test('AJAX requests get JSON responses for every forgot-password step', function () {
    Mail::fake();

    $user = createStaffAccount('Cashier');

    // Step 1: requesting a code answers JSON (the login-page modal posts this way).
    $this->withHeaders(['Accept' => 'application/json'])
        ->post('/forgot-password', ['email' => $user->email])
        ->assertOk()
        ->assertJson([
            'status' => 'code_sent',
            'email' => $user->email,
        ]);

    $code = null;
    Mail::assertSent(PasswordResetCode::class, function (PasswordResetCode $mail) use (&$code) {
        $code = $mail->code;
        return true;
    });
    expect($code)->not->toBeNull();

    // Step 2: verifying the code answers JSON with the reset token.
    $response = $this->withHeaders(['Accept' => 'application/json'])
        ->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertOk()
        ->assertJson(['status' => 'verified']);

    $payload = $response->json();
    expect($payload['token'] ?? '')->not->toBeEmpty();
    expect($payload['email'] ?? null)->toEqual($user->email);

    // Step 3: storing the new password answers JSON.
    $this->withHeaders(['Accept' => 'application/json'])
        ->post('/reset-password', [
            'token' => $payload['token'],
            'email' => $user->email,
            'password' => 'New-Str0ng-Passw0rd',
            'password_confirmation' => 'New-Str0ng-Passw0rd',
        ])
        ->assertOk()
        ->assertJson(['status' => 'password_reset']);

    // And the new password actually landed.
    $user->refresh();
    expect(Hash::check('New-Str0ng-Passw0rd', $user->password))->toBeTrue();
});

test('AJAX requests get JSON validation errors for unknown and admin email addresses', function () {
    Mail::fake();

    $admin = createAdminAccount();
    $unknown = 'nobody@example.com';

    $response = $this->withHeaders(['Accept' => 'application/json'])
        ->post('/forgot-password', ['email' => $unknown])
        ->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('email');

    // An admin address must look exactly like an unknown one.
    $response = $this->withHeaders(['Accept' => 'application/json'])
        ->post('/forgot-password', ['email' => $admin->email])
        ->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('email');
    expect($response->json('errors.email.0'))->toBe('Please try again.');

    // No code is ever issued for either address.
    Mail::assertNotSent(PasswordResetCode::class);
});
