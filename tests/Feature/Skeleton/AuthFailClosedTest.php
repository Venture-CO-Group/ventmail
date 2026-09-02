<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 | Viselkedési (nem szerkezeti) bizonyíték arra, hogy jelszó-alapú belépés nem létezik.
 | A security-review kérte a szerkezeti tesztek mellé.
 */

it('jelszóval nem lehet bejelentkezni', function () {
    $user = User::factory()->create();

    expect(Auth::attempt(['email' => $user->email, 'password' => 'akarmi']))->toBeFalse();
    expect(Auth::attempt(['email' => $user->email, 'password' => '']))->toBeFalse();
});

it('a remember-me nem ad vissza felhasználót', function () {
    User::factory()->create();

    expect(Auth::viaRemember())->toBeFalse();
});

it('a factory nem gyárt jelszót', function () {
    $attributes = User::factory()->raw();

    expect($attributes)->not->toHaveKey('password');
    expect($attributes)->not->toHaveKey('remember_token');
});
