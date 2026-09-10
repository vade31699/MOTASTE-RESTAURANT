<?php

use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

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

test('reset password screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('a verification code is emailed before any reset link', function () {
    Mail::fake();
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('password_reset_email', $user->email);

    // The code email goes out…
    Mail::assertSent(PasswordResetCode::class);

    // …but the reset link itself is withheld until the code is verified.
    Notification::assertNothingSent();
});

test('requesting a code for an unknown email is rejected', function () {
    Mail::fake();
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('the reset link is sent only after the code is verified', function () {
    Mail::fake();
    Notification::fake();

    $user = User::factory()->create();

    $code = requestPasswordResetCode($user, $this);

    // A wrong code is rejected and sends no reset link.
    $wrong = str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $wrong])
        ->assertSessionHasErrors('code');
    Notification::assertNothingSent();

    // The correct code triggers the reset link email.
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered with a verified code', function () {
    Mail::fake();
    Notification::fake();

    $user = User::factory()->create();

    $code = requestPasswordResetCode($user, $this);
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors();

    $notification = Notification::sent($user, ResetPassword::class)->first();
    expect($notification)->not->toBeNull();

    $this->get('/reset-password/'.$notification->token)
        ->assertStatus(200);
});

test('password can be reset with valid token', function () {
    Mail::fake();
    Notification::fake();

    $user = User::factory()->create();

    $code = requestPasswordResetCode($user, $this);
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors();

    $notification = Notification::sent($user, ResetPassword::class)->first();
    $response = $this->post('/reset-password', [
        'token' => $notification->token,
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

test('password reset requires the elevated 12-character policy', function () {
    Mail::fake();
    Notification::fake();

    $user = User::factory()->create();

    $code = requestPasswordResetCode($user, $this);
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors();

    $notification = Notification::sent($user, ResetPassword::class)->first();

    // 11 characters — meets the default policy but not the reset policy.
    $this->post('/reset-password', [
        'token' => $notification->token,
        'email' => $user->email,
        'password' => 'Str0ng-Pas0',
        'password_confirmation' => 'Str0ng-Pas0',
    ])->assertSessionHasErrors('password');
});

test('the verification code is single-use', function () {
    Mail::fake();
    Notification::fake();

    $user = User::factory()->create();

    $code = requestPasswordResetCode($user, $this);

    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasNoErrors();
    Notification::assertSentTo($user, ResetPassword::class);

    // Replaying the same code must not send another reset link.
    $this->post('/forgot-password/verify', ['email' => $user->email, 'code' => $code])
        ->assertSessionHasErrors('code');
});

test('the verification code self-destructs after 3 failed attempts', function () {
    Mail::fake();
    Notification::fake();

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

    Notification::assertNothingSent();
});