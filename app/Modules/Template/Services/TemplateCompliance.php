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
    public function missing(array $blocks): array
    {
        $found = [self::POSTAL_ADDRESS => false, self::UNSUBSCRIBE => false, self::PREFERENCES => false];

        $this->walk($blocks, $found);

        return array_keys(array_filter($found, static fn (bool $present) => ! $present));
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, bool>  $found
     */
    private function walk(array $blocks, array &$found): void
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

                    if (is_string($attrs['address'] ?? null) && trim($attrs['address']) !== '') {
                        $found[self::POSTAL_ADDRESS] = true;
                    }

                    break;

                case 'unsubscribe':
                    $found[self::UNSUBSCRIBE] = true;
                    $found[self::PREFERENCES] = true;

                    break;
            }

            $this->walk((array) ($block['children'] ?? []), $found);
        }
    }
}
