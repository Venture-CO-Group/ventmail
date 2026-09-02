<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Sending\Services\ContentScanner;

/**
 * Tartalom-biztonság a kimenő levélen.
 *
 * Miért kell: a `htmlspecialchars` a HTML-kontextust védi, de a `href` ATTRIBÚTUMBAN a séma
 * érintetlenül átmegy. Egy `javascript:` vagy `data:text/html` link a tenant DKIM-jével
 * aláírt, webmailben megnyíló tárolt phishing lenne — a saját domainünk hitelességével.
 *
 * MIT NÉZ (és miért pont ezt):
 * - a kanonikus HTML- és szöveges törzset EGYSZER,
 * - a behelyettesítendő VÁLTOZÓ-ÉRTÉKEKET külön (`v:*`, `recipient-variables`) — ez a
 *   valódi támadási csatorna, mert a sablon a tenanté, az érték viszont a hívóé,
 * - a NYERS MIME-ot (a szomszédos végponton különben trükk nélkül kiment volna).
 *
 * Amit SZÁNDÉKOSAN nem tesz: nem rendereli újra a teljes törzset címzettenként. Az ezer
 * címzett × 1 MB sablon a rate-limit ELŐTT álló CPU-erősítő volt (mérve ~12 s egyetlen
 * kérésre); a változó-értékek vizsgálata ugyanazt fogja meg a törzs mérete nélkül.
 */
final class ContentSafetyGate implements Gate
{
    public function __construct(private readonly ContentScanner $scanner) {}

    public function name(): string
    {
        return 'content_safety';
    }

    public function check(Message $message): GateResult
    {
        $html = (string) ($message->options['body_html'] ?? '');
        $text = (string) ($message->options['body_text'] ?? '');

        $violation = ($html === '' ? null : $this->scanner->firstViolation($html))
            ?? ($text === '' ? null : $this->scanner->firstViolationInText($text))
            ?? $this->scanVariables($message)
            ?? $this->scanRawMime($message);

        if ($violation !== null) {
            return GateResult::reject(GateReason::InvalidRequest, 'tiltott tartalom: '.$violation);
        }

        return GateResult::pass();
    }

    /**
     * A hívótól jövő értékek — a megosztott `v:*` és a címzettenkénti behelyettesítések.
     * Nyers cím SOHA nem kerül a törölhetetlen auditba, ezért csak a szabály neve utazik.
     */
    private function scanVariables(Message $message): ?string
    {
        foreach ($message->variables as $key => $value) {
            if ($key === 'recipient-variables') {
                foreach ((array) $value as $perRecipient) {
                    $violation = $this->scanner->firstViolationInValue($perRecipient);

                    if ($violation !== null) {
                        return $violation;
                    }
                }

                continue;
            }

            $violation = $this->scanner->firstViolationInValue($value);

            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }

    private function scanRawMime(Message $message): ?string
    {
        if (($message->options['raw_mime'] ?? false) !== true) {
            return null;
        }

        $raw = RawMimeMessage::query()->where('message_uuid', $message->uuid)->first();

        return $raw === null ? null : $this->scanner->firstViolationInMime($raw->raw);
    }
}
