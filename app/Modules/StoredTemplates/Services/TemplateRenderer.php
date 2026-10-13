<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Services;

use App\Modules\Sending\Services\DomainNormalizer;

/**
 * Merge-tag behelyettesítés (`{{ változó }}`).
 *
 * KÉT szabály, amitől nem térünk el:
 * 1. **AUTO-ESCAPE.** A behelyettesített érték SOHA nem lehet nyers HTML: a változó a
 *    hívótól jön (API-kérés, kontakt-attribútum), tehát tartalmazhat `<script>`-et. Egy
 *    e-mail-platformon ez azt jelentené, hogy az egyik ügyfél XSS-t küld a másik nevében.
 * 2. **HIÁNYZÓ VÁLTOZÓ = FALLBACK, nem hiba.** Egy kimaradt mező nem akadályozhatja meg a
 *    tranzakciós levél kiküldését (Mailgun-paritás).
 */
final class TemplateRenderer
{
    /**
     * A MERGE-TAG mintája — FALLBACKKEL (B2 · 60. kritérium).
     *
     * `{{ nev }}` vagy `{{ nev || Vásárló }}`. A fallback nem kényelmi elem: hiányzó változó
     * esetén a behelyettesítés ÜRESRE cserél, és a levélben „Kedves !" jelenik meg. Ez a
     * fajta hiba nem áll meg semmilyen kapun — szintaktikailag helyes a levél —, tehát csak
     * a címzett veszi észre, és a küldő reputációját viszi.
     *
     * A `-` a névben szándékos: a block editor és a `v:*` változónevek megengedik.
     */
    private const TAG_INNER = '\{\{\s*([a-zA-Z0-9_.-]+)\s*(?:\|\|\s*([^}]*?)\s*)?\}\}';

    private const TAG = '/'.self::TAG_INNER.'/';

    /** @param array<string, mixed> $variables */
    public function render(string $template, array $variables, bool $escape = true, bool $conditions = false): string
    {
        // A FELTÉTELES TARTALOM a behelyettesítés ELŐTT dől el: egy behelyettesített érték
        // (a plaintext ág nem escape-el) így nem tud feltétel-jelölőt becsempészni. Nem
        // Studio-eredetű törzsön (`$conditions = false`) csak a jelölő tűnik el (D58).
        $template = $conditions ? ConditionalContent::apply($template, $variables) : ConditionalContent::unmark($template);

        return self::checked(preg_replace_callback(
            self::TAG,
            function (array $matches) use ($variables, $escape): string {
                $value = $this->value($variables, $matches[1], $matches[2] ?? '');

                return $escape ? $this->escape($value) : (string) (is_array($value) ? json_encode($value) : $value);
            },
            $template
        ));
    }

    /**
     * FAIL-CLOSED (SB1): a `preg_*` hibánál `null`-t ad, amit egy `(string)` cast ÜRES
     * törzzsé alakítana — és az üres levél kimenne. Itt hangos kivétel lesz belőle; a
     * dispatcher ilyenkor nem küld.
     */
    private static function checked(?string $result): string
    {
        if ($result === null) {
            throw new \RuntimeException('A merge-tag behelyettesítés nem futott le: '.preg_last_error_msg());
        }

        return $result;
    }

    /**
     * Batch-behelyettesítés: minden címzett a SAJÁT változóit kapja.
     *
     * A `%recipient.kulcs%` a Mailgun szintaxisa; a `recipient-variables` JSON címzettre
     * bontva adja meg az értékeket. Enélkül a batch egyetlen, mindenkinek azonos levél
     * lenne — épp az ellenkezője annak, amiért a batch létezik.
     *
     * @param  array<string, array<string, mixed>>  $recipientVariables
     * @param  array<string, mixed>  $shared
     * @param  array<string, string>  $reserved  a hívó által NEM felülírható változók
     */
    public function renderForRecipient(
        string $template,
        string $recipient,
        array $recipientVariables,
        array $shared = [],
        bool $escape = true,
        array $reserved = [],
        bool $conditions = false,
    ): string {
        // A hívó írja a `recipient-variables` kulcsait, a címzett viszont kisbetűsítve
        // tárolódik: illesztés előtt normalizálunk, különben a nem egyező kulcs ÜRES
        // behelyettesítést adna, és a levél mégis kimenne.
        $normalized = [];

        foreach ($recipientVariables as $email => $values) {
            // KANONIKUS kulcs: a hívó unicode alakban adja meg a címet, a mentett címzett
            // viszont punycode domainnel szerepel — enélkül a személyre szabás IDN-címnél
            // némán elveszne (mindenki üres behelyettesítést kapna).
            $normalized[DomainNormalizer::listKey(trim((string) $email))] = $values;
        }

        $variables = array_merge($shared, (array) ($normalized[DomainNormalizer::listKey(trim($recipient))] ?? []));
        $variables['recipient_email'] = $recipient;

        // A FENNTARTOTT VÁLTOZÓK LEGUTOLSÓK, tehát a hívó nem tudja felülírni őket.
        //
        // Ezek a mi generált linkjeink (`unsubscribe_url`, `preferences_url`). Ha a hívó
        // `v:unsubscribe_url`-t adhatna, a levél alján lévő „Leiratkozás" az Ő címére
        // vinne — a mi DKIM-ünkkel aláírt levélben. A sorrend itt a védelem.
        $variables = array_merge($variables, $reserved);

        // A FELTÉTELES TARTALOM a behelyettesítés ELŐTT — lásd `render()`.
        $template = $conditions ? ConditionalContent::apply($template, $variables) : ConditionalContent::unmark($template);

        // EGYETLEN PASS mindkét szintaxisra. Korábban a `%recipient.x%` EREDMÉNYE újra
        // átment a `{{ }}` renderelésen — a `htmlspecialchars` a `{` és a `%` karaktert
        // nem escape-eli, tehát a hívótól jövő érték MÁSODSZOR is kiértékelődött.
        return self::checked(preg_replace_callback(
            '/%recipient\.([a-zA-Z0-9_]+)%|%recipient_email%|'.self::TAG_INNER.'/',
            function (array $matches) use ($variables, $escape): string {
                $name = match (true) {
                    ($matches[1] ?? '') !== '' => $matches[1],
                    ($matches[2] ?? '') !== '' => $matches[2],
                    default => 'recipient_email',
                };

                $value = $this->value($variables, $name, $matches[3] ?? '');

                // A text/plain részben a HTML-escape hibás lenne (a címzett `&amp;`-et
                // látna); a tartalmi kontrollt ott a ContentSafetyGate végzi.
                return $escape
                    ? $this->escape($value)
                    : (string) (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
            },
            $template
        ));
    }

    /**
     * AZ ÉRTÉK a változóból, vagy a FALLBACKBŐL.
     *
     * A fallback akkor lép be, ha a változó NINCS MEG, vagy ha üres sztring. A második
     * szándékos: egy üres `{{ nev }}` a gyakorlatban ugyanolyan rossz, mint a hiányzó — a
     * levélben „Kedves !" jelenik meg. Egy `0` vagy `false` viszont VALÓDI érték, azt nem
     * cseréljük ki (a `?:` operátor ezeket is elnyelte volna).
     *
     * @param  array<string, mixed>  $variables
     */
    private function value(array $variables, string $name, string $fallback): mixed
    {
        $value = data_get($variables, $name);

        // A BEÉPÍTETT `current_year` (v1.1 merge-tag választó): a lábléc „© {{ current_year }}"
        // sora ne függjön attól, hogy a hívó átadja-e. A hívó értéke elsőbbséget élvez.
        if (($value === null || $value === '') && $name === 'current_year') {
            return now()->format('Y');
        }

        if ($value === null || $value === '') {
            return $fallback;
        }

        return $value;
    }

    private function escape(mixed $value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // ENT_SUBSTITUTE: érvénytelen UTF-8 esetén a hibás bájt cserélődik, nem az EGÉSZ
        // érték tűnik el (különben a behelyettesítés némán üres lenne).
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
