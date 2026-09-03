<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use Normalizer;

/**
 * Domain-nevek EGYETLEN kanonikus alakja: punycode, kisbetűsen — vagy ÜRES, ha érvénytelen.
 *
 * Ez a függvény lett MINDEN domain-szintű engedélyezési döntés alapja (domain-kapu,
 * hitelesítő domain-kötése, per-fogadó-domain throttle, DKIM-domain, suppression), ezért
 * két tulajdonsága nem alku:
 *
 * 1. **INJEKTÍV.** Két KÜLÖNBÖZŐ domain sosem eshet egy kanonikus alakra, és ugyanaz a
 *    domain sosem adhat két alakot. Enélkül: aki a `strasse.de`-t hitelesítette, a
 *    `straße.de` nevében küldhetne (tranzicionális IDNA), a `ｐelda.hu` (fullwidth) pedig a
 *    `pelda.hu` jogán menne ki — miközben a CÍMZETT a nyers, hamis alakot látja, a mi
 *    DKIM-aláírásunkkal. Ezért: ami nem TISZTA leképezés (oda-vissza egyezik), az
 *    ÉRVÉNYTELEN, nem „majdnem jó".
 * 2. **KÖRNYEZET-FÜGGETLEN.** Az `IDNA_DEFAULT` értéke az ICU verziójától függ (ICU < 76:
 *    tranzicionális!), tehát egy base-image frissítés csendben megváltoztatná az egész
 *    rendszer engedélyezési szemantikáját. A zászlók itt EXPLICITEK, és KAT-vektortábla
 *    rögzíti a bemenet → kimenet párokat.
 */
final class DomainNormalizer
{
    /**
     * Nontranzicionális UTS46 + BIDI és CONTEXTJ ellenőrzés.
     *
     * A nontranzicionális a 2016 óta érvényes viselkedés: a `ß` és a `ς` ÖNMAGA marad,
     * nem alakul `ss`-sé és `σ`-vá — azok külön regisztrálható domainek.
     */
    private const IDNA_FLAGS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    /** Kanonikus alak, vagy ÜRES sztring, ha a domain érvénytelen. */
    public static function canonical(string $domain): string
    {
        // SZÁNDÉKOSAN nincs `trim(..., '.')`: a vezető/záró pont, a szóköz és az üres label
        // NEM „elnézhető formázás". A `.pelda.hu` levágásával a `pelda.hu` jogán menne ki
        // egy olyan levél, amiben a címzett `.pelda.hu`-t lát.
        if ($domain === '' || $domain !== trim($domain)) {
            return '';
        }

        if (mb_check_encoding($domain, 'ASCII')) {
            $ascii = strtolower($domain);
        } else {
            // A bemenetnek MÁR normalizáltnak kell lennie — se kanonikus (NFC), se
            // kompatibilitási (NFKC) átalakítás nem változtathat rajta.
            //
            // Miért nem elég a lenti oda-vissza ellenőrzés: a KELVIN SIGN (U+212A) egyszerű
            // kisbetűs alakja `k`, tehát az összehasonlítás átengedné — pedig az a
            // `kelvin.com` jogán menne ki egy olyan levéllel, amiben a címzett `Kelvin`-t
            // lát. A fullwidth `ｐ` ugyanígy: NFKC alatt `p`.
            if (Normalizer::normalize($domain, Normalizer::FORM_C) !== $domain
                || Normalizer::normalize($domain, Normalizer::FORM_KC) !== $domain) {
                return '';
            }

            $converted = idn_to_ascii($domain, self::IDNA_FLAGS, INTL_IDNA_VARIANT_UTS46, $info);

            // A hibát NEM nyeljük le: a korábbi „essünk vissza az eredetire" ág azt
            // jelentette volna, hogy egy érvénytelen IDN is összehasonlítható értéket kap.
            if ($converted === false || ($info['errors'] ?? 0) !== 0) {
                return '';
            }

            $ascii = strtolower($converted);

            // TISZTA leképezés ellenőrzése: a visszaalakított alaknak egyeznie kell a
            // bemenettel (kisbetűsen). Ami nem egyezik — fullwidth `ｐ`, KELVIN SIGN,
            // kompatibilitási karakterek —, az MÁS domain, csak ugyanoda képződne.
            $roundTrip = idn_to_utf8($ascii, self::IDNA_FLAGS, INTL_IDNA_VARIANT_UTS46);

            if ($roundTrip === false || mb_strtolower($roundTrip) !== mb_strtolower($domain)) {
                return '';
            }
        }

        return self::isValidAsciiDomain($ascii) ? $ascii : '';
    }

    /** Egy e-mail cím domain-része, kanonikus alakban (üres, ha érvénytelen). */
    public static function ofEmail(string $email): string
    {
        $position = strrpos($email, '@');

        return $position === false ? '' : self::canonical(substr($email, $position + 1));
    }

    /**
     * SUPPRESSION- és CONSENT-kulcs: a helyi rész is kisbetűs.
     *
     * Itt szándékosan MÁS a szabály, mint a küldésnél: a leiratkozási és consent-listák
     * kis/nagybetű-érzéketlenek (a `User@x.hu` leiratkozása a `user@x.hu`-ra is vonatkozik),
     * különben a lista triviálisan megkerülhető lenne. A küldési úton viszont a helyi rész
     * érintetlen marad — azt csak a fogadó értelmezheti.
     */
    public static function listKey(string $email): string
    {
        $position = strrpos($email, '@');

        if ($position === false) {
            return mb_strtolower(trim($email));
        }

        $canonical = self::canonical(substr($email, $position + 1));
        $local = mb_strtolower(substr($email, 0, $position));

        return $canonical === '' ? mb_strtolower(trim($email)) : $local.'@'.$canonical;
    }

    /**
     * A teljes cím kanonikus alakja: a helyi rész VÁLTOZATLAN marad.
     *
     * A helyi részt SOHA nem alakítjuk át (RFC 5321 §2.4: kizárólag a fogadó értelmezheti),
     * csak a domaint — különben az `SMTPUTF8` melletti `Ünnepi@példa.hu` címzettből más
     * postafiók lenne, mint amit a küldő megadott.
     *
     * Érvénytelen domainnél az EREDETIT adjuk vissza: a hibát a kapu jelzi strukturáltan,
     * nem itt, csendben.
     */
    public static function email(string $email): string
    {
        $position = strrpos($email, '@');

        if ($position === false) {
            return $email;
        }

        $canonical = self::canonical(substr($email, $position + 1));

        return $canonical === '' ? $email : substr($email, 0, $position).'@'.$canonical;
    }

    /** LDH-szabály (RFC 1035 + RFC 5890): label-hossz, karakterkészlet, üres label tiltva. */
    private static function isValidAsciiDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253 || ! str_contains($domain, '.')) {
            return false;
        }

        foreach (explode('.', $domain) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
