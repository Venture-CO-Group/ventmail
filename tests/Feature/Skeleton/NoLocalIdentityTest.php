<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Support\Facades\Schema;

/*
 | Golden rule 1: az identitás gazdája a ventSSO — a ventMail jelszót SOHA nem kezel.
 | A nyers Laravel-skeleton jelszó-infrastruktúrája ezért nem maradhat benne.
 */

it('a users tábla nem tárol jelszót', function () {
    expect(Schema::hasColumn('users', 'password'))->toBeFalse('a users tábla jelszó-oszlopot tart');
    expect(Schema::hasColumn('users', 'remember_token'))->toBeFalse('a users tábla remember_token-t tart');
});

it('nincs jelszó-visszaállító tábla', function () {
    expect(Schema::hasTable('password_reset_tokens'))->toBeFalse('password_reset_tokens tábla létezik');
});

it('a User modell nem hasít jelszót', function () {
    $casts = (new User)->getCasts();

    expect($casts)->not->toHaveKey('password');
});

it('a jelszó-visszaállító broker nem használható', function () {
    // A Laravel a keretrendszer alapértelmezéseit REKURZÍVAN visszamergeli, ezért a
    // `passwords` kulcsot configból nem lehet eltüntetni. A ténylegesen kikényszerített
    // invariáns: a broker táblája nem létezik, így jelszó-visszaállítás nem indítható.
    $table = (string) config('auth.passwords.users.table');

    expect(Schema::hasTable($table))->toBeFalse("a jelszó-broker táblája létezik: {$table}");
});

it('a User modell nem használ jelszó-alapú traiteket', function () {
    $traits = class_uses_recursive(User::class);

    expect($traits)->not->toContain(CanResetPassword::class);
});
