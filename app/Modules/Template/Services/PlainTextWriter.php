<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

/**
 * BLOCK-TREE → PLAINTEXT (59. kritérium).
 *
 * ## Miért nem `strip_tags(html)`
 *
 * Mert az eredmény olvashatatlan: a gombok szövege elszakad a linkjüktől, a szakaszok
 * összefolynak, a képek alt-szövege eltűnik vagy fordítva, ott marad a fájlnév. A
 * `text/plain` rész nem melléktermék, hanem MÁSODIK levél: a szűrők a hiányát klasszikus
 * spam-jelként kezelik (a valódi levelezők küldik, a tömeges spam-küldők gyakran nem — ezt
 * az A6 spam-score-a pontozza is), és van, aki tényleg így olvassa.
 *
 * Ezért a plaintext ugyanabból a FÁBÓL épül, mint a HTML: a struktúra megmarad, a linkek a
 * szöveg mellé zárójelben kerülnek, és a merge-tagek érintetlenül mennek át — a
 * behelyettesítés a küldés pillanatában történik, és ott a plaintext ág NEM escape-el
 * (a címzett különben `&amp;`-et látna).
 */
final class PlainTextWriter
{
    /** @param array<int, mixed> $blocks */
    public function write(array $blocks, ?string $preheader = null): string
    {
        $lines = [];

        if ($preheader !== null && trim($preheader) !== '') {
            $lines[] = trim($preheader);
            $lines[] = '';
        }

        foreach ($blocks as $section) {
            $before = count($lines);

            $this->walk(is_array($section) ? $section : [], $lines);

            // SZAKASZ-HATÁR: üres sor a szakaszok között, de csak ha írtunk is valamit.
            if (count($lines) > $before) {
                $lines[] = '';
            }
        }

        // A ZÁRÓ üres sorokat levágjuk, a belsőket megtartjuk: a struktúra a
        // szakaszhatárokból derül ki, és egy tucat üres sor a levél végén hanyagságnak
        // látszik.
        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $lines
     */
    private function walk(array $block, array &$lines): void
    {
        $type = (string) ($block['type'] ?? '');
        $attrs = (array) ($block['attrs'] ?? []);

        match ($type) {
            'heading' => $this->heading($attrs, $lines),
            'text' => $lines[] = $this->flatten($attrs['content'] ?? ''),
            'button' => $lines[] = sprintf(
                '%s: %s',
                $this->flatten($attrs['content'] ?? ''),
                (string) ($attrs['href'] ?? '')
            ),
            'image' => $this->image($attrs, $lines),
            'divider' => $lines[] = '---',
            // A SPACER-nek nincs szöveges megfelelője: egy üres sor a legközelebbi.
            'spacer' => $lines[] = '',
            'unsubscribe' => $this->unsubscribe($attrs, $lines),
            'social' => $this->social($attrs, $lines),
            'html' => $lines[] = $this->flatten(strip_tags((string) ($attrs['content'] ?? ''))),
            default => null,
        };

        foreach ((array) ($block['children'] ?? []) as $child) {
            $this->walk(is_array($child) ? $child : [], $lines);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<string>  $lines
     */
    private function heading(array $attrs, array &$lines): void
    {
        $content = $this->flatten($attrs['content'] ?? '');

        if ($content === '') {
            return;
        }

        $lines[] = $content;
        // ALÁHÚZÁS: a plaintextben ez az egyetlen módja jelezni, hogy ez egy címsor.
        // A hossz a szöveg hossza, mert a fix hosszú vonal rövid címsornál hanyag.
        $lines[] = str_repeat((int) ($attrs['level'] ?? 2) <= 1 ? '=' : '-', min(60, mb_strlen($content)));
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<string>  $lines
     */
    private function image(array $attrs, array &$lines): void
    {
        $alt = $this->flatten($attrs['alt'] ?? '');

        // A KÉP a plaintextben csak az alt-szövegével létezik. Fájlnév nélkül: a
        // `logo-final-v3.png` a címzettnek semmit nem mond, csak zajt ad.
        if ($alt !== '') {
            $lines[] = '['.$alt.']';
        }

        if (isset($attrs['href'])) {
            $lines[] = (string) $attrs['href'];
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<string>  $lines
     */
    private function unsubscribe(array $attrs, array &$lines): void
    {
        // A HELYŐRZŐK maradnak: a valódi URL címzettenként más, és a küldés pillanatában
        // áll elő. A sablonba írt konkrét link egy MÁS ember tokenjét vinné.
        $lines[] = $this->flatten($attrs['content'] ?? 'Leiratkozás').': {{ unsubscribe_url }}';
        $lines[] = 'Levélbeállítások: {{ preferences_url }}';
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<string>  $lines
     */
    private function social(array $attrs, array &$lines): void
    {
        foreach ((array) ($attrs['networks'] ?? []) as $network) {
            if (is_array($network) && isset($network['href'])) {
                $lines[] = ucfirst((string) ($network['name'] ?? 'link')).': '.(string) $network['href'];
            }
        }
    }

    /**
     * A HTML-TARTALOM szöveggé lapítása.
     *
     * A szerkesztő szövegblokkjában van formázás (`<strong>`, `<a>`, `<br>`), tehát a
     * plaintexthez ki kell szedni — de a `<br>` és a `</p>` SORTÖRÉST jelent, nem
     * semmit: egyszerű `strip_tags()`-gal a bekezdések összefolynának egy sorba.
     */
    private function flatten(mixed $value): string
    {
        $html = (string) $value;

        // A LINK szövege ÉS a célja is kell: „Kattints ide" önmagában használhatatlan.
        $html = (string) preg_replace_callback(
            '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $matches): string {
                $text = trim(strip_tags($matches[3]));
                $href = trim($matches[2]);

                // Ha a szöveg MAGA az URL, nem írjuk ki kétszer.
                return $text === '' || $text === $href ? $href : $text.' ('.$href.')';
            },
            $html
        );

        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|div|li|h[1-6])>#i', "\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', '- ', $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // A TÖBBSZÖRÖS whitespace összevonása — de a SORTÖRÉS megmarad.
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
