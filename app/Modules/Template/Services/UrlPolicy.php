<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

/**
 * A LINKEK EGYETLEN SZABÁLYA — validátor, sanitizer és fordító ugyanezt kérdezi.
 *
 * Miért külön osztály: a szabály korábban a `BlockTreeValidator`-ban élt egyetlen
 * `str_starts_with`-láncként, a `MjmlWriter::url()` pedig csak escape-elt, „a validátor már
 * kizárta" felkiáltással. Amikor a NYERS HTML blokk és a merge-tag fallback megjelent, két új
 * út nyílt ugyanahhoz az attribútumhoz, és mindkettő kikerülte a szabályt.
 *
 * A MERGE-TAG FALLBACK a mért rés: `{{ cta || javascript:alert(1) }}`. A minta a fallbacket
 * `[^}]*`-gal engedte, tehát a validátor ÁTENGEDTE, a fordító beépítette, és a kimenet
 * `href="javascript:alert(1)"` lett — a `render()` és a batch úton egyaránt. A tenant
 * DKIM-jével aláírt, a mi originünkben megnyíló levélben ez tárolt XSS, illetve nyílt
 * átirányító. A `http://`-t ugyanez engedte át, holott a nyers alakot a validátor tiltja.
 *
 * A fallback tehát UGYANAZT a szabályt kapja, mint a nyers érték. Az ÜRES fallback
 * (`{{ cta }}`) más eset: ott a küldés pillanatában behelyettesített értéket a
 * `ContentSafetyGate` `scanVariables()`-e vizsgálja — a szabályt nem itt kell megismételni,
 * de a kapunak ott KELL lennie, ezért a `ContentScanner` a fallbackre is ránéz.
 */
final class UrlPolicy
{
    /**
     * A merge-tag alakja, a fallbackkel mint NEVESÍTETT csoport.
     *
     * A `[^}]*?` lusta, hogy a záró `}}` ne kerüljön a fallbackbe.
     */
    public const MERGE_TAG = '/^\{\{\s*[A-Za-z0-9_.-]+\s*(?:\|\|\s*(?<fallback>[^}]*?)\s*)?\}\}$/';

    /** @var list<string> */
    private const ALLOWED_PREFIXES = ['https://', 'mailto:'];

    /**
     * Megengedett-e ez az attribútum-érték egy `href`/`src` helyén?
     *
     * Nem `?bool`-t adunk vissza, hanem a HIBA OKÁT: a szerkesztőben a „nem jó a link"
     * használhatatlan visszajelzés, a „a fallback nem `https://`" viszont megjavítható.
     *
     * @return string|null a hiba oka, vagy `null`, ha rendben
     */
    public static function violation(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return 'üres';
        }

        if (preg_match(self::MERGE_TAG, $value, $match) === 1) {
            $fallback = trim($match['fallback'] ?? '');

            // ÜRES fallback: a behelyettesített értéket a küldés kapuja vizsgálja.
            if ($fallback === '') {
                return null;
            }

            return self::literalViolation($fallback) === null
                ? null
                : 'a merge-tag fallbackje sem lehet más, mint `https://` vagy `mailto:`';
        }

        return self::literalViolation($value);
    }

    /** A NYERS (nem merge-tag) érték szabálya. */
    public static function literalViolation(string $value): ?string
    {
        $value = trim($value);

        // A HORGONY (`#valami`) rendben: nem navigál ki a levélből.
        if ($value !== '' && str_starts_with($value, '#')) {
            return null;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            // Kis-nagybetűre érzéketlen: a séma az RFC szerint az, és a `HTTPS://` alak
            // egy `str_starts_with`-en elhasalna, majd a hibalistában landolna — a
            // szerkesztő pedig egy teljesen jó linket utasítana el.
            if (stripos($value, $prefix) === 0) {
                return null;
            }
        }

        return 'csak `https://`, `mailto:`, horgony (`#`) vagy merge-tag lehet';
    }

    public static function isAllowed(string $value): bool
    {
        return self::violation($value) === null;
    }
}
