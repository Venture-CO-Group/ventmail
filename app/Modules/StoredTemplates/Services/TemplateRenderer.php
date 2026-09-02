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

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $value = (string) $value;

                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
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
    ): string {
        $variables = array_merge($shared, $recipientVariables[$recipient] ?? []);

        // Mailgun-szintaxis: %recipient.kulcs% és %recipient_email%
        $rendered = (string) preg_replace_callback(
            '/%recipient\.([a-zA-Z0-9_]+)%/',
            fn (array $m) => htmlspecialchars((string) ($variables[$m[1]] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $template
        );

        $rendered = str_replace('%recipient_email%', htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8'), $rendered);

        // A `{{ }}` szintaxis is működik ugyanazokkal a változókkal.
        return $this->render($rendered, $variables);
    }
}
