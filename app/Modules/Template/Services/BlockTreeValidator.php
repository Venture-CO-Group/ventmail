<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\Sending\Services\ContentScanner;
use App\Modules\Template\Data\BlockSchema;
use InvalidArgumentException;

/**
 * A BLOCK-TREE VALIDÁLÁSA (58. kritérium).
 *
 * ## Miért KIVÉTEL, és nem csendes tisztítás
 *
 * A hibás blokkot NEM dobjuk el. A csendes eldobás itt azt jelentené, hogy a szerkesztőben
 * összeállított levélből eltűnik egy szakasz, a felhasználó viszont a mentés után „sikert"
 * lát — és a hibát a címzett fedezi fel, amikor egy fél levelet kap. Ugyanez a
 * gondolatmenet, mint a szegmens-motorban: ott az elhagyott szabály SZÉLESEBB közönséget
 * ad, itt a HIÁNYOS levelet.
 *
 * ## A hibaüzenet MEGNEVEZI a helyet
 *
 * Egy általános JSON Schema validátor `#/0/attrs: does not match`-et ír; a szerkesztő
 * felhasználójának ez semmit nem mond. Itt az útvonal blokk-indexekkel és típusokkal
 * épül (`section[0] > column[1] > button[0]`), tehát a felület rá tud mutatni.
 */
final class BlockTreeValidator
{
    /**
     * A TARTALMI KORLÁT egy attribútum-értékre.
     *
     * Mérve egy 2 MB-os `text.content` átment a validátoron. 500 blokk × korlátlan tartalom
     * úgy, hogy a mentés SZINKRONBAN fordít belőle még három oszlopot (`mjml`/`html`/`text`),
     * a publikálás pedig minden üzenetbe átmásolja: a sablon mérete a küldés
     * memória-igényét sokszorozza. 64 KB egy levélblokkra bőven elég.
     */
    public const MAX_ATTR_LENGTH = 65536;

    public function __construct(private readonly ContentScanner $scanner) {}

    /** @var list<string> */
    private array $errors = [];

    private int $blockCount = 0;

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<string> a hibák (üres, ha a fa érvényes)
     */
    public function errorsIn(array $blocks): array
    {
        $this->errors = [];
        $this->blockCount = 0;

        $this->walk($blocks, BlockSchema::ROOT_BLOCKS, 1, '');

        if ($this->blockCount > BlockSchema::MAX_BLOCKS) {
            $this->errors[] = sprintf(
                'A sablon %d blokkot tartalmaz, a felső korlát %d.',
                $this->blockCount,
                BlockSchema::MAX_BLOCKS
            );
        }

        return $this->errors;
    }

    /** @param array<int, mixed> $blocks */
    public function validate(array $blocks): void
    {
        $errors = $this->errorsIn($blocks);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' | ', $errors));
        }
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  list<string>  $allowed
     */
    private function walk(array $blocks, array $allowed, int $depth, string $path): void
    {
        if ($depth > BlockSchema::MAX_DEPTH) {
            $this->errors[] = sprintf(
                '%s: a fa mélyebb, mint a megengedett %d szint.',
                $path === '' ? 'gyökér' : $path,
                BlockSchema::MAX_DEPTH
            );

            return;
        }

        // A LISTA-alak kötelező: egy objektum a gyökérben azt jelenti, hogy a szerkesztő
        // más sémát használ, és a fordítás csendben egyetlen blokkot adna.
        if ($blocks !== [] && array_keys($blocks) !== range(0, count($blocks) - 1)) {
            $this->errors[] = ($path === '' ? 'gyökér' : $path).': listát vár, nem objektumot.';

            return;
        }

        foreach ($blocks as $index => $block) {
            $here = ($path === '' ? '' : $path.' > ').'['.$index.']';

            if (! is_array($block)) {
                $this->errors[] = $here.': a blokk objektum kell legyen.';

                continue;
            }

            $type = $block['type'] ?? null;

            if (! is_string($type) || $type === '') {
                $this->errors[] = $here.': hiányzó `type`.';

                continue;
            }

            $here = ($path === '' ? '' : $path.' > ').$type.'['.$index.']';
            $this->blockCount++;

            if (! isset(BlockSchema::BLOCKS[$type])) {
                $this->errors[] = sprintf(
                    '%s: ismeretlen blokktípus. Engedélyezett: %s.',
                    $here,
                    implode(', ', array_keys(BlockSchema::BLOCKS))
                );

                continue;
            }

            if (! in_array($type, $allowed, true)) {
                $this->errors[] = sprintf(
                    '%s: ez a blokk itt nem állhat. Engedélyezett ezen a szinten: %s.',
                    $here,
                    implode(', ', $allowed)
                );

                continue;
            }

            $spec = BlockSchema::BLOCKS[$type];
            $attrs = $block['attrs'] ?? [];

            if (! is_array($attrs)) {
                $this->errors[] = $here.': az `attrs` objektum kell legyen.';

                $attrs = [];
            }

            foreach ($spec['required'] as $field) {
                if (! isset($attrs[$field]) || (is_string($attrs[$field]) && trim($attrs[$field]) === '')) {
                    $this->errors[] = sprintf('%s: kötelező mező hiányzik: `%s`.', $here, $field);
                }
            }

            // AZ ISMERETLEN MEZŐ IS HIBA, nem csendes eldobás: egy elgépelt `aling`
            // különben azt jelentené, hogy a felhasználó beállított valamit, ami nem hat.
            $known = array_merge($spec['required'], $spec['optional']);

            foreach (array_keys($attrs) as $field) {
                if (! in_array((string) $field, $known, true)) {
                    $this->errors[] = sprintf(
                        '%s: ismeretlen mező: `%s`. Engedélyezett: %s.',
                        $here,
                        (string) $field,
                        $known === [] ? '(nincs)' : implode(', ', $known)
                    );
                }
            }

            $this->validateAttrs($type, $attrs, $here);

            $children = $block['children'] ?? [];

            if ($spec['children'] === null) {
                // A NEM-LISTA ALAK IS HIBA. Korábban csak a nem-üres LISTÁT néztük, tehát
                // `divider` + `children: "x"` CSENDBEN átment — miközben a lista-alakot a
                // szülő-blokkokon kikényszerítjük. Ugyanaz a „beállított valamit, ami nem
                // hat" hibaosztály: a felhasználó odaírt egy gyermeket, és nem tudja meg,
                // hogy elveszett.
                if ($children !== [] && $children !== null) {
                    $this->errors[] = $here.': ez a blokk nem tartalmazhat gyermeket.';
                }

                continue;
            }

            if (! is_array($children)) {
                $this->errors[] = $here.': a `children` listát vár.';

                continue;
            }

            $this->walk($children, $spec['children'], $depth + 1, $here);
        }
    }

    /**
     * A MEZŐK ÉRTÉKÉNEK ellenőrzése — típus és biztonsági megkötések.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function validateAttrs(string $type, array $attrs, string $path): void
    {
        // A LINKEK csak `https`, `mailto` vagy merge-tag lehetnek.
        //
        // Nem esztétika: a `javascript:` séma egy levélkliensben ritkán fut le, de a
        // WEBES ELŐNÉZETBEN (a szerkesztő és a „megnyitás böngészőben" nézet) igen — és
        // az a mi originünk. A `http://` pedig a linkre kattintó címzettet titkosítatlan
        // csatornára viszi.
        foreach (['href', 'src'] as $field) {
            if (! isset($attrs[$field]) || ! is_string($attrs[$field])) {
                continue;
            }

            // A SZABÁLY a `UrlPolicy`-ban van, EGY helyen. Itt korábban egy külön
            // `str_starts_with`-lánc állt, és a merge-tag mintája a fallbacket `[^}]*`-gal
            // engedte: a `{{ cta || javascript:alert(1) }}` átment, és `href="javascript:…"`
            // lett a fordított HTML-ben.
            $violation = UrlPolicy::violation($attrs[$field]);

            if ($violation !== null) {
                $this->errors[] = sprintf(
                    '%s: a `%s` %s (kapott: %s).',
                    $path,
                    $field,
                    $violation,
                    mb_substr(trim($attrs[$field]), 0, 40)
                );
            }
        }

        /*
         | A NYERS HTML blokk: a KIMENŐ LEVÉL SZABÁLYA érvényes rá, nem egy külön regexpár.
         |
         | Itt korábban két minta állt (`<script` és `\son[a-z]+=`). Mérve mind átment
         | rajtuk: `<img/onerror=…>` (a `/` separator), `<iframe srcdoc=…>`, `<base href=…>`,
         | `<meta http-equiv=refresh>`, `<form action="http://…">`, `<style>…url(javascript:)…`.
         | A `ContentScanner` UGYANEZT a kérdést teszi fel a kimenő levélre, és ott már meg
         | van válaszolva — tehát a validátor is AZT kérdezi, nem egy második, gyengébb
         | változatot. Így nincs két igazság, ami elcsúszhat egymástól.
         |
         | Ez a HANGOS visszajelzés a szerkesztőnek. A biztonsági garanciát a kimeneten a
         | `HtmlSanitizer` allowlistje adja (a `MjmlWriter`-ben) — a validátor kikapcsolása
         | vagy megkerülése önmagában nem nyit rést.
         */
        if ($type === 'html' && isset($attrs['content']) && is_string($attrs['content'])) {
            $violation = $this->scanner->firstViolation($attrs['content']);

            if ($violation !== null) {
                $this->errors[] = $path.': a nyers HTML blokk nem tartalmazhat '.$violation.'.';
            }
        }

        // A TARTALOM MÉRETE — lásd a `MAX_ATTR_LENGTH` indoklását.
        foreach ($attrs as $field => $value) {
            if (is_string($value) && strlen($value) > self::MAX_ATTR_LENGTH) {
                $this->errors[] = sprintf(
                    '%s: a `%s` hossza %d bájt, a felső korlát %d.',
                    $path,
                    (string) $field,
                    strlen($value),
                    self::MAX_ATTR_LENGTH
                );
            }
        }

        // A SZÁMOK számok legyenek: a `width: "nagyon"` a fordításban csendben eltűnne.
        //
        // A TARTOMÁNY is szabály, nem csak a típus: a `font_size: 0` szám, átmegy, és
        // `font-size:0px`-et ad — LÁTHATATLAN címsort a levélben, hangos hiba nélkül.
        $ranges = ['level' => [1, 4], 'font_size' => [8, 72], 'height' => [1, 2000], 'icon_size' => [8, 128]];

        foreach ($ranges as $field => [$min, $max]) {
            if (! isset($attrs[$field])) {
                continue;
            }

            if (! is_numeric($attrs[$field])) {
                $this->errors[] = sprintf('%s: a `%s` szám kell legyen.', $path, $field);

                continue;
            }

            $number = (float) $attrs[$field];

            if ($number < $min || $number > $max) {
                $this->errors[] = sprintf('%s: a `%s` %d és %d között lehet.', $path, $field, $min, $max);
            }
        }

        if ($type === 'social' && isset($attrs['networks']) && ! is_array($attrs['networks'])) {
            $this->errors[] = $path.': a `networks` listát vár.';
        }
    }
}
