<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('login is rate limited per IP', function () {
    $user = User::factory()->create();

    $attempt = fn () => $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    // The per-account lockout in LoginRequest fires first, but those attempts
    // still consume the per-IP budget of twenty per minute.
    foreach (range(1, 20) as $ignored) {
        $attempt();
    }

    $attempt()->assertStatus(429);
});
