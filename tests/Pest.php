<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 | A Feature-tesztek a Laravel TestCase-t kapják. A `RefreshDatabase` a séma-packettől
 | (M0-2) lesz alapértelmezett; addig a skeleton-tesztek nem írnak adatbázist.
 */

pest()->extend(TestCase::class)->in('Feature');

// A RefreshDatabase-t az adatbázist érintő tesztek maguk kérik (`uses(RefreshDatabase::class)`),
// hogy a séma nélküli skeleton-tesztek gyorsak maradjanak.

/** base64url, padding nélkül (a ventSec ítélet-aláírásának és kulcsainak kódolása). */
function b64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Egy config-fájl kiértékelése adott env-vel — a VALÓDI alapértékek mérésére. */
function configFileWith(string $file, array $env): array
{
    $saved = [];

    foreach ($env as $key => $value) {
        $saved[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
        putenv($value === null ? $key : "{$key}={$value}");

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    try {
        return require base_path("config/{$file}.php");
    } finally {
        foreach ($saved as $key => $value) {
            putenv($value === null ? $key : "{$key}={$value}");

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
}
