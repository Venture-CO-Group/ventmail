<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use RuntimeException;

/**
 * Kulcsolt hash — az EGYETLEN hely, ahol a rendszer HMAC-et számol.
 *
 * Miért egy helyen: három különböző cél (API-kulcs hitelesítése, e-mail és IP
 * pszeudonimizálása) használ HMAC-et. Ha ezek külön-külön, nyers `hash_hmac` hívásokként
 * élnek az app-kódban, akkor (a) nincs domain-elválasztás — egy e-mail-hash és egy
 * API-kulcs-hash összevethető lenne, (b) a pepper rotációja némán érvényteleníti az
 * összes kulcsot, (c) egy csendes refaktor bármelyiket elronthatja.
 *
 * DOMAIN-ELVÁLASZTÁS: a cél (`purpose`) a HMAC-kulcs származtatásába megy, nem az üzenetbe.
 * ROTÁCIÓ: az `AUDIT_PREVIOUS_PEPPER` átfedéses ideig elfogadott ellenőrzéskor — enélkül a
 * pepper cseréje egyszerre lőné ki minden ügyfél minden API-kulcsát.
 */
final class KeyedHash
{
    private const MIN_PEPPER_BYTES = 32;

    public static function of(string $purpose, string $input): string
    {
        return hash_hmac('sha256', $input, self::key($purpose, self::pepper()));
    }

    /**
     * Ellenőrzés időben állandó összehasonlítással, a rotációs átfedést is figyelembe véve.
     */
    public static function matches(string $purpose, string $input, string $expected): bool
    {
        if (hash_equals($expected, self::of($purpose, $input))) {
            return true;
        }

        $previous = (string) config('audit.previous_pepper');

        if ($previous === '') {
            return false;
        }

        return hash_equals($expected, hash_hmac('sha256', $input, self::key($purpose, $previous)));
    }

    /** A cél-specifikus kulcs a master pepperből származik (HKDF-szerű elválasztás). */
    private static function key(string $purpose, string $pepper): string
    {
        return hash_hmac('sha256', 'ventmail:'.$purpose, $pepper, binary: true);
    }

    private static function pepper(): string
    {
        $pepper = (string) config('audit.pepper');

        // Fail-closed: üres pepperrel a `hash_hmac` CSENDBEN adna értéket, és a
        // pszeudonimizálás értéktelen lenne. Inkább hangosan álljunk meg.
        if (strlen($pepper) < self::MIN_PEPPER_BYTES) {
            throw new RuntimeException(
                'Az AUDIT_PEPPER hiányzik vagy túl rövid (min. '.self::MIN_PEPPER_BYTES.' bájt). '
                .'Generálás: php artisan ventmail:pepper'
            );
        }

        return $pepper;
    }
}
