<?php

declare(strict_types=1);

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Alapértelmezett guard
    |--------------------------------------------------------------------------
    |
    | A munkamenetet a session guard tartja, de a BEJELENTKEZÉST kizárólag a
    | ventSSO OIDC-folyamata végzi (Identity modul, M0-1). Jelszó-broker
    | szándékosan nincs — a ventMail nem kezel jelszót (golden rule 1).
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User provider
    |--------------------------------------------------------------------------
    |
    | A `users` tábla a ventSSO-alany lokális tükre (jelszó nélkül). A provider
    | csak a már hitelesített felhasználó visszatöltésére szolgál.
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Jelszó-brokerek — SZÁNDÉKOSAN ÜRESEN
    |--------------------------------------------------------------------------
    |
    | A ventMail nem tárol jelszót és nem küld visszaállítót — az identitás gazdája a
    | ventSSO (golden rule 1). FIGYELEM: a Laravel a keretrendszer alapértelmezéseit
    | rekurzívan visszamergeli, ezért ez az üres tömb önmagában nem elég: a valódi
    | garancia az, hogy a broker TÁBLÁJA nem létezik (lásd `NoLocalIdentityTest`).
    |
    */

    'passwords' => [],

];
