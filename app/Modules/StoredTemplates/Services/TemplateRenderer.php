<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Services;

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
    /** @param array<string, mixed> $variables */
    public function render(string $template, array $variables, bool $escape = true): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $matches) use ($variables, $escape): string {
                $value = data_get($variables, $matches[1], '');

                return $escape ? $this->escape($value) : (string) (is_array($value) ? json_encode($value) : $value);
            },
            $template
        );
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
     */
    public function renderForRecipient(
        string $template,
        string $recipient,
        array $recipientVariables,
        array $shared = [],
        bool $escape = true,
    ): string {
        // A hívó írja a `recipient-variables` kulcsait, a címzett viszont kisbetűsítve
        // tárolódik: illesztés előtt normalizálunk, különben a nem egyező kulcs ÜRES
        // behelyettesítést adna, és a levél mégis kimenne.
        $normalized = [];

        foreach ($recipientVariables as $email => $values) {
            $normalized[strtolower(trim((string) $email))] = $values;
        }

        $variables = array_merge($shared, (array) ($normalized[strtolower(trim($recipient))] ?? []));
        $variables['recipient_email'] = $recipient;

        // EGYETLEN PASS mindkét szintaxisra. Korábban a `%recipient.x%` EREDMÉNYE újra
        // átment a `{{ }}` renderelésen — a `htmlspecialchars` a `{` és a `%` karaktert
        // nem escape-eli, tehát a hívótól jövő érték MÁSODSZOR is kiértékelődött.
        return (string) preg_replace_callback(
            '/%recipient\.([a-zA-Z0-9_]+)%|%recipient_email%|\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $matches) use ($variables, $escape): string {
                $name = match (true) {
                    ($matches[1] ?? '') !== '' => $matches[1],
                    ($matches[2] ?? '') !== '' => $matches[2],
                    default => 'recipient_email',
                };

                $value = data_get($variables, $name, '');

                // A text/plain részben a HTML-escape hibás lenne (a címzett `&amp;`-et
                // látna); a tartalmi kontrollt ott a ContentSafetyGate végzi.
                return $escape
                    ? $this->escape($value)
                    : (string) (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
            },
            $template
        );
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
