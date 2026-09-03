<?php

declare(strict_types=1);

/*
 | Jelszó-hash beállítások.
 |
 | A `rounds` ITT van rögzítve, nem a PHP alapértelmezésén hagyva. Miért számít: a
 | `PASSWORD_DEFAULT` költsége PHP-verziónként változik (8.3-ban 10, 8.4-től 12). Ha a
 | valódi hash 10-es költséggel készül, a hitelesítés dummy összehasonlítása viszont
 | 12-essel, akkor a NEM LÉTEZŐ felhasználó válasza LASSABB — és a különbségből (mérve
 | ~59 ms vs ~235 ms) kiderül, melyik felhasználónév létezik.
 */

return [
    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],
];
