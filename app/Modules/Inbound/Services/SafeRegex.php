<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

/**
 * Az ÜGYFÉL reguláris kifejezésének futtatása — korlátok között.
 *
 * A route-szabályok kifejezéseit az ügyfél írja, és egy rosszul (vagy rosszindulatúan)
 * megírt regex a visszalépéses illesztőt exponenciális időre viheti. Ezt már megégettük egyszer:
 * az A1-ben egy SAJÁT base64-regexünk 500 kB-os bemeneten 76 másodpercig futott.
 *
 * Három zár:
 *  1. a KIFEJEZÉS hossza korlátozott (a mentéskor is, itt is);
 *  2. a BEMENET hossza korlátozott — a hosszú tárgysor nem indokolja a korlátlan illesztést;
 *  3. a PCRE saját visszalépés-korlátja (`pcre.backtrack_limit`) beállítva, és a `false`
 *     visszatérést NEM tekintjük „nem illeszkedik"-nek: a korlátba ütközött illesztés
 *     ELUTASÍTÁS, és látszik a naplóban.
 *
 * A `preg_match` sosem dob: hibás mintára `false`-t ad. Egy elgépelt ügyfél-regex tehát nem
 * dönti el a jobot — a szabály egyszerűen nem illeszkedik.
 */
final class SafeRegex
{
    public const MAX_PATTERN_BYTES = 512;

    public const MAX_SUBJECT_BYTES = 8192;

    /** @return array{matched: bool, error: ?string} */
    public function matches(string $pattern, string $subject): array
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return ['matched' => false, 'error' => 'A kifejezés hossza meghaladja a korlátot.'];
        }

        // A bemenetet VÁGJUK, nem utasítjuk el: egy hosszú tárgysor legitim, csak nem kell az
        // egészére illeszteni.
        $subject = mb_substr($subject, 0, self::MAX_SUBJECT_BYTES);

        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        try {
            // Az ügyfél mintája HATÁROLÓ NÉLKÜL érkezik (`^support@…$`), tehát mi tesszük rá.
            // A `#` határoló és a `preg_quote`-tal védett tartalom helyett `~`-t használunk,
            // és a mintában lévő `~`-t escape-eljük — így az ügyfél nem tud saját
            // módosítót (`/e`-szerű veszély) vagy határolót becsempészni.
            $delimited = '~'.str_replace('~', '\~', $pattern).'~iu';

            $result = @preg_match($delimited, $subject);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }

        if ($result === false) {
            return [
                'matched' => false,
                'error' => preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR
                    ? 'A kifejezés a visszalépési korlátba ütközött.'
                    : 'A kifejezés érvénytelen.',
            ];
        }

        return ['matched' => $result === 1, 'error' => null];
    }

    /** Érvényes-e a minta? A MENTÉSKOR kell, hogy az ügyfél azonnal lássa. */
    public function valid(string $pattern): bool
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }

        return @preg_match('~'.str_replace('~', '\~', $pattern).'~iu', '') !== false;
    }
}
