<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Services\TemplateRenderer;

/**
 * Tartalom-biztonság a VÉGLEGES, behelyettesített törzsön.
 *
 * Miért a gate-ben: a `htmlspecialchars` a HTML-kontextust védi, de a `href` ATTRIBÚTUMBAN
 * a séma érintetlenül átmegy. Egy `javascript:` vagy `data:text/html` link a tenant
 * DKIM-jével aláírt, webmailben megnyíló tárolt phishing lenne — a saját domainünk
 * hitelességével.
 *
 * A gate a behelyettesített tartalmat vizsgálja, nem a sablont: a veszélyes séma a
 * VÁLTOZÓBÓL is jöhet (`recipient-variables`, kontakt-attribútum).
 *
 * Ide épül majd az A6 spam-score és link-ellenőrzés is — ezért a végleges tartalmon.
 */
final class ContentSafetyGate implements Gate
{
    /** Csak ezek a sémák jelenhetnek meg linkként. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function name(): string
    {
        return 'content_safety';
    }

    public function check(Message $message): GateResult
    {
        $template = (string) ($message->options['body_html'] ?? '');

        if ($template === '') {
            return GateResult::pass('nincs HTML törzs');
        }

        $recipientVariables = (array) ($message->variables['recipient-variables'] ?? []);
        $shared = collect($message->variables)->except('recipient-variables')->all();

        foreach ($message->recipients as $index => $recipient) {
            $rendered = $this->renderer->renderForRecipient(
                $template,
                (string) $recipient,
                $recipientVariables,
                $shared
            );

            $offending = $this->firstDangerousScheme($rendered);

            if ($offending !== null) {
                // Nyers cím SOHA nem kerül a törölhetetlen auditba — index elég.
                return GateResult::reject(
                    GateReason::InvalidRequest,
                    sprintf('címzett #%d: tiltott link-séma a törzsben (%s)', $index, $offending)
                );
            }
        }

        return GateResult::pass();
    }

    private function firstDangerousScheme(string $html): ?string
    {
        preg_match_all('/(?:href|src|action)\s*=\s*["\']?\s*([a-zA-Z][a-zA-Z0-9+.-]*):/i', $html, $matches);

        foreach ($matches[1] ?? [] as $scheme) {
            if (! in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
                return strtolower($scheme);
            }
        }

        return null;
    }
}
