<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'New-Str0ng-Passw0rd',
            'password_confirmation' => 'New-Str0ng-Passw0rd',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertTrue(Hash::check('New-Str0ng-Passw0rd', $user->refresh()->password));
});

test('password cannot be updated to the current password', function () {
    $user = User::factory()->create();
    $current = 'March031699!';
    $user->forceFill(['password' => Hash::make($current)])->save();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => $current,
            'password' => $current,
            'password_confirmation' => $current,
        ]);

    $response->assertSessionHasErrors('password');

    // The stored hash is unchanged.
    expect(Hash::check($current, $user->refresh()->password))->toBeTrue();
});

test('password update rejects common passwords', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ]);

    $response->assertSessionHasErrors('password');
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'New-Str0ng-Passw0rd',
            'password_confirmation' => 'New-Str0ng-Passw0rd',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect('/profile');
});
