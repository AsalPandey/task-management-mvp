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
    $response->assertRedirect(route('login'));
});

test('logout returns to login inside the mounted application', function () {
    $prefix = '/qualification/task-management';
    $user = User::factory()->create();
    $response = $this->actingAs($user)->withServerVariables([
        'SCRIPT_NAME' => $prefix.'/index.php',
        'PHP_SELF' => $prefix.'/index.php',
        'SCRIPT_FILENAME' => public_path('index.php'),
    ])->post($prefix.'/logout');

    $this->assertGuest();
    $response->assertRedirect();
    $this->assertSame($prefix.'/login', parse_url($response->headers->get('Location'), PHP_URL_PATH));
});
