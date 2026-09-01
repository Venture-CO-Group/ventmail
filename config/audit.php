<?php

declare(strict_types=1);

/*
 | Audit- és pszeudonimizálási beállítások.
 |
 | A `hash_pepper` KÜLÖN titok, nem az app kulcsa: az `APP_KEY` rotációja különben minden
 | korábbi `ip_hash`/`email_hash` értéket összehasonlíthatatlanná tenne.
 */

return [
    'hash_pepper' => env('AUDIT_HASH_PEPPER', env('APP_KEY', '')),
];
