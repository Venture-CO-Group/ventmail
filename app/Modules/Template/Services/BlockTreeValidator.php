<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

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
                if (is_array($children) && $children !== []) {
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

            $value = trim($attrs[$field]);

            $allowed = str_starts_with($value, 'https://')
                || str_starts_with($value, 'mailto:')
                // A merge-tag ITT legitim: a link célja lehet személyre szabott.
                || preg_match('/^\{\{\s*[A-Za-z0-9_.-]+\s*(\|\|[^}]*)?\}\}$/', $value) === 1;

            if (! $allowed) {
                $this->errors[] = sprintf(
                    '%s: a `%s` csak `https://`, `mailto:` vagy merge-tag lehet (kapott: %s).',
                    $path,
                    $field,
                    mb_substr($value, 0, 40)
                );
            }
        }

        // A NYERS HTML blokk: `<script>` és eseménykezelő nem megy.
        if ($type === 'html' && isset($attrs['content']) && is_string($attrs['content'])) {
            if (preg_match('#<\s*script#i', $attrs['content']) === 1) {
                $this->errors[] = $path.': a nyers HTML blokk nem tartalmazhat `<script>`-et.';
            }

            if (preg_match('/\son[a-z]+\s*=/i', $attrs['content']) === 1) {
                $this->errors[] = $path.': a nyers HTML blokk nem tartalmazhat eseménykezelőt (`on*=`).';
            }
        }

        // A SZÁMOK számok legyenek: a `width: "nagyon"` a fordításban csendben eltűnne.
        foreach (['level', 'font_size', 'height', 'icon_size'] as $field) {
            if (isset($attrs[$field]) && ! is_numeric($attrs[$field])) {
                $this->errors[] = sprintf('%s: a `%s` szám kell legyen.', $path, $field);
            }
        }

        if ($type === 'heading' && isset($attrs['level'])) {
            $level = (int) $attrs['level'];

            if ($level < 1 || $level > 4) {
                $this->errors[] = $path.': a `level` 1 és 4 között lehet.';
            }
        }

        if ($type === 'social' && isset($attrs['networks']) && ! is_array($attrs['networks'])) {
            $this->errors[] = $path.': a `networks` listát vár.';
        }
    }
}
