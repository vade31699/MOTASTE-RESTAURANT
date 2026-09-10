<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
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

        return true;
    });
});

test('password reset requires the elevated 12-character policy', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        // 11 characters — meets the default policy but not the reset policy.
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'Str0ng-Pas0',
            'password_confirmation' => 'Str0ng-Pas0',
        ])->assertSessionHasErrors('password');

        return true;
    });
});
