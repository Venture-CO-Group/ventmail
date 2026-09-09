<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

/**
 * A MERGE-TAG HELYREÁLLÍTÁSA a kimeneten — EGY helyen.
 *
 * ## Miért van erre külön osztály
 *
 * A libxml az URI-attribútumokban PERCENT-KÓDOL, és hogy MIT kódol, az a libxml
 * VERZIÓJÁTÓL függ. Ez nem elmélet: a golden fixture első CI-futásán bukott ki, hogy
 * lokálisan a `href="{{ unsubscribe_url }}"` a szóközt kapja `%20`-ként (a kapcsos zárójel
 * megmarad), a CI libxml-jén viszont a ZÁRÓJEL IS kódolódik:
 *
 *     lokális: href="{{%20unsubscribe_url%20}}"
 *     CI:      href="%7B%7B%20unsubscribe_url%20%7D%7D"
 *
 * A visszaállítás korábban KÉT helyen élt, két külön mintával (a fordítóban és a
 * sanitizerben), és mindkettő csak az egyik alakot ismerte. A CI-ban ezért a leiratkozó
 * link `href="%7B%7B%20unsubscribe_url%20%7D%7D"` lett — amit a behelyettesítő minta már
 * nem illeszt, tehát a címzett HALOTT linket kapott volna. Pontosan az a csendes hiba,
 * amit ez a projekt már többször megtalált: „minden sikeresnek látszik", és a hibát a
 * címzett fedezi fel.
 *
 * Ezért a minta MINDKÉT alakot ismeri, és egyetlen helyen van. Aki új kimeneti utat ír, ezt
 * hívja — nem ír harmadik regexet.
 *
 * ## A FALLBACK BELSEJÉT NEM DEKÓDOLJUK
 *
 * A `{{ url || https://a.hu/x%20y }}` fallbackje egy JOGOSAN URL-kódolt link: a `%20 →
 * szóköz` visszaírás mérve `https://a.hu/x y`-ra rontotta el. És ha a `%20` mégis a
 * libxml-től jön (mert az író szóközt írt), akkor is helyes kódolva hagyni: egy URL-ben a
 * szóköz kódolva tartozik.
 */
final class MergeTag
{
    /**
     * A HATÁROLÓK mindkét alakja: literál és percent-kódolt.
     *
     * A fallback karakterosztálya `[^<>"]` — nem `[^}]`, mert a kódolt záró határoló
     * (`%7D%7D`) nem tartalmaz `}`-t, és a lusta illesztés különben átfutna a következő
     * tagre.
     */
    private const PATTERN = '/(?:\{\{|%7B%7B)(?:%20|\s)*([A-Za-z0-9_.-]+)'
        .'(?:(?:%20|\s)*(?:\|\||%7C%7C)(?:%20|\s)*([^<>"]*?))?'
        .'(?:%20|\s)*(?:\}\}|%7D%7D)/i';

    public static function restore(string $html): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static function (array $matches): string {
                $fallback = isset($matches[2]) && trim($matches[2]) !== ''
                    ? ' || '.trim($matches[2])
                    : '';

                return '{{ '.$matches[1].$fallback.' }}';
            },
            $html
        );
    }
}
