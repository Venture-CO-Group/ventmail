<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

/**
 * Domain-nevek EGYETLEN kanonikus alakja: punycode, kisbetűsen.
 *
 * Miért nem apróság: az `SMTPUTF8` bevezetésével ugyanaz a domain KÉT alakban érkezhet
 * (`példa.hu` és `xn--plda-tta.hu`). Ha a hitelesített domainek táblájában az egyik van, a
 * beadott levélben a másik, akkor a domain-kapu megkerülhető — a küldő „nem hitelesített"
 * alakot használ, mi meg nem találjuk a tiltást. Ugyanez igaz a hitelesítő domain-kötésére,
 * a per-fogadó-domain throttle-ra és a suppression-listára.
 *
 * A kisbetűsítés az ASCII-alakon történik: a `mb_strtolower` unicode-ra is helyes, de a
 * punycode-konverzió maga már kisbetűs ASCII-t ad.
 */
final class DomainNormalizer
{
    public static function canonical(string $domain): string
    {
        $domain = trim($domain, " \t.");

        if ($domain === '') {
            return '';
        }

        // Már ASCII: nincs mit konvertálni, csak kisbetűsíteni.
        if (mb_check_encoding($domain, 'ASCII')) {
            return strtolower($domain);
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        // Konvertálhatatlan (hibás) IDN: az EREDETIT adjuk vissza kisbetűsen, hogy a
        // hívó összehasonlítása determinisztikus maradjon — a domain-kapu úgyis elbukik rá.
        return $ascii === false ? mb_strtolower($domain) : strtolower($ascii);
    }

    /** Egy e-mail cím domain-része, kanonikus alakban. */
    public static function ofEmail(string $email): string
    {
        $position = strrpos($email, '@');

        return $position === false ? '' : self::canonical(substr($email, $position + 1));
    }

    /**
     * A teljes cím kanonikus alakja: a helyi rész VÁLTOZATLAN marad.
     *
     * A helyi részt SOHA nem alakítjuk át (RFC 5321 §2.4: kizárólag a fogadó értelmezheti),
     * csak a domaint — különben az `SMTPUTF8` melletti `Ünnepi@példa.hu` címzettből más
     * postafiók lenne, mint amit a küldő megadott.
     */
    public static function email(string $email): string
    {
        $position = strrpos($email, '@');

        if ($position === false) {
            return $email;
        }

        return substr($email, 0, $position).'@'.self::canonical(substr($email, $position + 1));
    }
}
