<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

/**
 * A KAMPÁNYRA HASZNÁLHATÓ (marketing) sablon KÖTELEZŐ ELEMEI (v1.1 · lábléc).
 *
 * Három elem: postai cím, leiratkozó link, beállítás-link (preference center). Az első a
 * CAN-SPAM és az EU-s fogyasztóvédelmi gyakorlat, a második az RFC 8058 / Gmail–Yahoo 2024,
 * a harmadik a `.claude/rules/20-consent-gdpr.md` szabálya.
 *
 * ## Hol fut
 *
 * A szerkesztő MINDEN mentési útján (autosave, mentés, másolat, indulósablonból létrehozás,
 * visszaállítás) — a marketing sablon nem menthető nélkülük. A modell `saving` hookja és a
 * DB-trigger a régi, szűkebb szabályt (leiratkozó blokk) tartja; azt a v1.1 előtti sablonok
 * miatt nem szigorítjuk adatbázis-szinten (expand-only: egy meglévő sablon ne váljon
 * menthetetlenné egy deploytól). A kampány-indítás ugyanezt az osztályt kérdezheti meg.
 *
 * ## Mi számít
 *
 * - `footer` blokk: mindhármat hordozza (a cím kötelező mezője, a két link nem kapcsolható ki);
 * - `unsubscribe` blokk: a leiratkozó és a beállítás-linket hordozza, a postai címet NEM.
 *
 * ## A helyőrző cím (S1)
 *
 * A katalógus kitalált címe („1 Example Street") SZIGORÚ módban hiányzónak számít: az
 * optimalizáló mutatja, a kampányindítás elutasítja. A szerkesztő MENTÉSE nem-szigorú —
 * egy indulósablonból létrehozott piszkozat menthető, amíg a felhasználó kitölti.
 */
final class TemplateCompliance
{
    public const POSTAL_ADDRESS = 'postal_address';

    public const UNSUBSCRIBE = 'unsubscribe';

    public const PREFERENCES = 'preferences';

    /**
     * A HIÁNYZÓ kötelező elemek (üres lista = rendben).
     *
     * @param  array<int, mixed>  $blocks
     * @return list<string>
     */
    public function missing(array $blocks, bool $strict = true): array
    {
        $found = [self::POSTAL_ADDRESS => false, self::UNSUBSCRIBE => false, self::PREFERENCES => false];

        $this->walk($blocks, $found, $strict);

        return array_keys(array_filter($found, static fn (bool $present) => ! $present));
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, bool>  $found
     */
    private function walk(array $blocks, array &$found, bool $strict): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $attrs = (array) ($block['attrs'] ?? []);

            switch ($block['type'] ?? null) {
                case 'footer':
                    $found[self::UNSUBSCRIBE] = true;
                    $found[self::PREFERENCES] = true;

                    if (is_string($attrs['address'] ?? null) && trim($attrs['address']) !== ''
                        && (! $strict || ! self::isPlaceholderAddress($attrs['address']))) {
                        $found[self::POSTAL_ADDRESS] = true;
                    }

                    break;

                case 'unsubscribe':
                    $found[self::UNSUBSCRIBE] = true;
                    $found[self::PREFERENCES] = true;

                    break;
            }

            $this->walk((array) ($block['children'] ?? []), $found, $strict);
        }
    }

    /**
     * Van-e a fában HELYŐRZŐ postai címmel kitöltött lábléc? (A kampányindítás kérdezi.)
     *
     * @param  array<int, mixed>  $blocks
     */
    public function hasPlaceholderAddress(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'footer' && is_string($block['attrs']['address'] ?? null)
                && self::isPlaceholderAddress($block['attrs']['address'])) {
                return true;
            }

            if ($this->hasPlaceholderAddress((array) ($block['children'] ?? []))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A KATALÓGUS kitalált helyőrző-címe (S1) — bármelyik nyelven — HIÁNYZÓ címnek számít.
     * Egy „1 Example Street" a levélben nem postai cím, csak annak látszik.
     */
    public static function isPlaceholderAddress(string $address): bool
    {
        $normalize = static fn (string $value): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
        $needle = $normalize($address);

        foreach ((array) config('app.supported_locales', ['en']) as $locale) {
            if ($needle === $normalize((string) __('editor.defaults.footerAddress', [], (string) $locale))) {
                return true;
            }
        }

        return false;
    }
}
