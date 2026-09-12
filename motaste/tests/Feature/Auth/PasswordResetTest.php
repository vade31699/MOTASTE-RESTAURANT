<?php

use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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
 * Create the single Admin account: the users row (the reset store) plus the
 * matching staff row that marks it as the Admin.
 */
function createAdminUser(): User
{
    $admin = User::factory()->create();

    DB::table('staff')->insert([
        'user_id' => $admin->id,
        'full_name' => 'Admin',
        'role' => 'Admin',
        'email' => $admin->email,
        'password_hash' => $admin->password,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $admin;
}

/**
 * Create a non-admin staff account (Cashier or Inventory Manager).
 */
function createStaffUser(string $role): User
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

test('reset password screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('a verification code is emailed before any reset form is shown', function () {
    Mail::fake();

    $user = createAdminUser();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $user->email);

    // The code email goes out — it is the only email in the flow.
    Mail::assertSent(PasswordResetCode::class);
});

test('an unknown email gets the same response as a known one', function () {
    Mail::fake();

    // Identical to a real send: no error and the same pending-code state, so
    // the response cannot be used to test which addresses exist.
    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', 'nobody@example.com');

    // ...but no code is created and no email is sent.
    Mail::assertNothingSent();
});

test('a cashier cannot start a password reset', function () {
    Mail::fake();

    $cashier = createStaffUser('Cashier');

    $this->post('/forgot-password', ['email' => $cashier->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $cashier->email);

    expect(session('errors')->get('email'))->toContain('Please try again.');
    Mail::assertNothingSent();

    // The neutral response must not actually let the account through: any code
    // submitted afterwards is rejected.
    $this->post('/forgot-password/verify', ['email' => $cashier->email, 'code' => '000000'])
        ->assertSessionHasErrors('code');
});

test('an inventory manager cannot start a password reset', function () {
    Mail::fake();

    $inventory = createStaffUser('Inventory Manager');

    $this->post('/forgot-password', ['email' => $inventory->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $inventory->email);

    Mail::assertNothingSent();
});

test('forgot-password is rate limited per IP', function () {
    Mail::fake();

    $admin = createAdminUser();

    // The limiter allows five requests per minute per IP.
    foreach (range(1, 5) as $ignored) {
        $this->post('/forgot-password', ['email' => $admin->email])
            ->assertRedirect();
    }

    // The sixth is refused before the controller runs.
    $this->post('/forgot-password', ['email' => $admin->email])
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

    $user = createAdminUser();

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

    $user = createAdminUser();

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $this->get('/reset-password/'.$token)
        ->assertStatus(200);
});

test('password can be reset with valid token', function () {
    Mail::fake();

    $user = createAdminUser();

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

test('password cannot be reset to the current password', function () {
    Mail::fake();

    $user = createAdminUser();
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

    $user = createAdminUser();
    $staffCurrent = 'Staff-Current-9';

    // The admin's staff portal credential is the same account, so a reset must
    // not be allowed to land on the portal's current hash either.
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

test('password reset syncs the hash into the admin credentials table', function () {
    Mail::fake();

    $user = User::factory()->create();

    // The Admin now keeps its hash in a dedicated `admins` table.
    DB::table('admins')->insert([
        'full_name' => 'Test Admin',
        'email' => $user->email,
        'password_hash' => Hash::make('Admin-Current-9'),
        'role' => 'Admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'New-Str0ng-Passw0rd',
        'password_confirmation' => 'New-Str0ng-Passw0rd',
    ])->assertSessionHasNoErrors()->assertRedirect(route('password.success'));

    $adminHash = DB::table('admins')
        ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
        ->value('password_hash');

    expect(Hash::check('New-Str0ng-Passw0rd', $adminHash))->toBeTrue();
});

test('password cannot be reset to the admin current password', function () {
    Mail::fake();

    $user = User::factory()->create();
    $adminCurrent = 'Admin-Current-9';

    DB::table('admins')->insert([
        'full_name' => 'Test Admin',
        'email' => $user->email,
        'password_hash' => Hash::make($adminCurrent),
        'role' => 'Admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => $adminCurrent,
        'password_confirmation' => $adminCurrent,
    ])->assertSessionHasErrors('password');
});

test('password reset requires the elevated 12-character policy', function () {
    Mail::fake();

    $user = createAdminUser();

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

    $user = createAdminUser();

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

    $user = createAdminUser();

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