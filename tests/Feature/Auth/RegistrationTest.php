<?php

use App\Models\User;

test('registration is closed', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('the login screen does not offer sign up', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee(__('Sign up'));
});
