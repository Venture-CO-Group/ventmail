<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

/**
 * A LEIRATKOZÓ FEJLÉC A MIÉNK — ez az egyetlen hely, ami ezt kimondja.
 *
 * ## Miért van külön osztály erre
 *
 * A `List-Unsubscribe` fejléc egy bemutatóra szóló KÉPESSÉGI TOKENT hordoz, ami egy olyan
 * suppression-listára ír, amiről a tenant API-ból nem vehet le senkit. Amíg az értéke
 * kívülről jöhetett, nekünk FEL KELLETT ISMERNI, hogy a benne lévő token a miénk-e — és ez a
 * felismerés négy egymást követő review-körben négyszer nyílt ki (első címzett tokenje
 * mindenkinek → URL-előtag elcsúszása → más betűzésű kulcs → százalék-kódolt token). Egy
 * támadó által írt szabadtextben regexszel keresni egy képességet nem tehető teljessé.
 *
 * A szerkezeti válasz az, hogy az érték NEM JÖHET KÍVÜLRŐL. Az ötödik variáns viszont már
 * nem a felismerésen, hanem MAGÁN A BEMENETI ZÁRON nyílt ki: a `h: List-Unsubscribe` kulcs
 * VEZETŐ SZÓKÖZZEL átment, mert a zár nem trimmelt, a `Message::canonicalHeaderName()`
 * viszont trimmel — a fejléc így kanonikus néven landolt a levélben, a MI DKIM-aláírásunkkal
 * hitelesítve.
 *
 * A tanulság, amit ez az osztály testesít meg: **egy zár, egy normalizálás, minden úton.**
 * Három belépési pont van (`/v1/messages`, `/v1/messages.mime`, SMTP relay), és korábban
 * kettőn volt zár, egymástól függetlenül megírva — a harmadik (a relay) csendben nyitva
 * maradt, mérve: idegen tenant tokenje kiment a levélben, egyetlen kapu sem látta.
 *
 * A `MessageEntryPointGuardTest` őrzi, hogy új belépési pont ne születhessen a zár nélkül.
 */
final class UnsubscribeHeaderPolicy
{
    /** A fejlécek, amiket KIZÁRÓLAG mi állítunk elő. */
    public const MANAGED = ['list-unsubscribe', 'list-unsubscribe-post'];

    /**
     * A MI fejlécünk-e ez a név? Egyetlen normalizálás, minden hívónak ugyanaz.
     *
     * A `trim()` NEM kényelmi elem: nélküle a `h: List-Unsubscribe` (vezető szóköz) átment a
     * záron, majd a modell kanonizálása levágta a szóközt — és az ügyfél fejléce kanonikus
     * néven került a levélbe. RFC 5322 szerint a fejlécnév és a kettőspont között amúgy sem
     * lehet whitespace, tehát a trimmelt egyezés nem enged át semmit, amit szabad lenne.
     */
    public static function isManaged(string $name): bool
    {
        $normalized = strtolower(trim($name, " \t\r\n\0\x0B"));

        foreach (self::MANAGED as $managed) {
            if (str_starts_with($normalized, $managed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<string, mixed>|list<string>  $names  fejlécnevek (kulcsként vagy értékként)
     * @return list<string> a talált, ÁLTALUNK kezelt fejlécnevek
     */
    public static function managedAmong(iterable $names): array
    {
        $found = [];

        foreach ($names as $key => $value) {
            $name = is_string($key) ? $key : (string) $value;

            if (self::isManaged($name)) {
                $found[] = trim($name);
            }
        }

        return array_values(array_unique($found));
    }

    /** A hívónak adandó magyarázat — egy szövegváltozat, hogy a három út ugyanazt mondja. */
    public static function reason(): string
    {
        return 'A leiratkozó fejlécet a ventMail kezeli (RFC 8058 one-click), és nem adható meg '
            .'kívülről: a benne lévő token a CÍMZETT személyéhez kötött, és visszavonhatatlan '
            .'hatású. A saját preference centeredre mutató linket a levél törzsébe tedd.';
    }
}
