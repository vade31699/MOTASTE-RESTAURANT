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

test('reset password screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('a verification code is emailed before any reset form is shown', function () {
    Mail::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $user->email);

    // The code email goes out — it is the only email in the flow.
    Mail::assertSent(PasswordResetCode::class);
});

test('requesting a code for an unknown email is rejected', function () {
    Mail::fake();

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
});

test('the reset form is reached only after the code is verified', function () {
    Mail::fake();

    $user = User::factory()->create();

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

    $user = User::factory()->create();

    $code = requestPasswordResetCode($user, $this);
    $token = verifyCodeAndGetResetToken($user, $this, $code);

    $this->get('/reset-password/'.$token)
        ->assertStatus(200);
});

test('password can be reset with valid token', function () {
    Mail::fake();

    $user = User::factory()->create();

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

    $user = User::factory()->create();
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

    $user = User::factory()->create();
    $staffCurrent = 'Staff-Current-9';

    // The staff portal keeps its own hash in the staff table, so a reset must
    // not be allowed to land on that value either.
    DB::table('staff')->insert([
        'user_id' => $user->id,
        'full_name' => 'Test Staff',
        'role' => 'Cashier',
        'email' => $user->email,
        'password_hash' => Hash::make($staffCurrent),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

    $user = User::factory()->create();

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

    $user = User::factory()->create();

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

    $user = User::factory()->create();

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