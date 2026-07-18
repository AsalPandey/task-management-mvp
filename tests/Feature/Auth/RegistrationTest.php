<?php

test('public registration screen is disabled', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('public registration cannot create users', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertNotFound();
});
