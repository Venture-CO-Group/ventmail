<?php

declare(strict_types=1);

namespace App\Modules\Messages\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Messages\Data\MailgunPayload;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * `POST /v1/messages` — a Mailgun Messages API paritása (spec 8.).
 *
 * A kontroller SEMMILYEN küldési döntést nem hoz: értelmezi a kérést, majd átadja a
 * `SendMessage` actionnek, ami a PreSendGate-en át küld. A gate strukturált `reason` kódja
 * VÁLTOZATLANUL kerül a válaszba — így a hívó ugyanazt látja, mint az üzenet-trace (A3).
 */
final class SendMessageController
{
    public function __invoke(Request $request, SendMessage $send, TemplateRenderer $renderer): JsonResponse
    {
        try {
            $payload = MailgunPayload::fromRequest($request->all());
        } catch (InvalidArgumentException $e) {
            [$code, $message] = array_pad(explode('|', $e->getMessage(), 2), 2, '');

            return ApiError::badRequest($code, $message);
        }

        // Tárolt sablon: a `template=` a Mailgun paritásának része. A behelyettesítés ITT
        // történik, hogy a gate már a VÉGLEGES tartalmat lássa (a spam-score és a
        // link-ellenőrzés A6-ban erre épül).
        $html = $payload->html;
        $text = $payload->text;

        if ($payload->template !== null) {
            $template = StoredTemplate::query()->where('name', $payload->template)->first();
            $version = $template?->activeVersion();

            if ($version === null) {
                return ApiError::badRequest(
                    'UNKNOWN_TEMPLATE',
                    "Ismeretlen vagy aktív verzió nélküli sablon: {$payload->template}"
                );
            }

            $variables = collect($payload->variables)->except('recipient-variables')->all();

            $html = $renderer->render($version->body, $variables);
            $text = $version->body_text !== null ? $renderer->render($version->body_text, $variables, escape: false) : $text;
        }

        $ipPoolId = null;

        if ($payload->ipPool !== null) {
            $pool = IpPool::query()->where('name', $payload->ipPool)->first();

            if ($pool === null) {
                return ApiError::badRequest('UNKNOWN_IP_POOL', "Ismeretlen IP-pool: {$payload->ipPool}");
            }

            $ipPoolId = $pool->getKey();
        }

        $result = $send([
            'channel' => (string) $request->input('o:channel', 'transactional'),
            'from_email' => $payload->fromEmail,
            'from_name' => $payload->fromName,
            'subject' => $payload->subject,
            'to' => $payload->recipients,
            'html' => $html,
            'text' => $text,
            'rendered_html' => $html,
            'tags' => $payload->tags,
            'variables' => $payload->variables,
            'headers' => $payload->headers,
            'options' => $payload->options,
            'deliverytime' => $payload->deliverytime,
            'testmode' => $payload->testmode,
            'ip_pool_id' => $ipPoolId,
            'idempotency_key' => (string) $request->attributes->get('idempotency_key'),
        ]);

        if (! $result->decision->allowed) {
            return ApiError::rejected(
                (string) $result->decision->reason,
                $this->explain($result->decision->reason),
                ['id' => $result->message->uuid]
            );
        }

        return response()->json([
            'id' => $result->message->uuid,
            'message' => 'Queued. Thank you.',
            'status' => $result->message->status,
        ], 202);
    }

    /**
     * A gate-kódok emberi magyarázata. A KÓD a szerződés, az üzenet segítség — ezért a
     * kód sosem változik, a szöveg finomítható.
     */
    private function explain(?string $reason): string
    {
        return match ($reason) {
            'DOMAIN_UNVERIFIED' => 'A feladó domain nincs hitelesítve. Vedd fel a DNS-rekordokat a wizardban.',
            'SUPPRESSED' => 'A címzett suppression-listán van (bounce, panasz vagy leiratkozás).',
            'NO_CONSENT' => 'Marketing küldéshez megerősített (double opt-in) consent kell.',
            'QUOTA_EXCEEDED' => 'A havi küldési kvóta elfogyott.',
            'RATE_LIMITED' => 'Túl sok küldés rövid idő alatt; próbáld később.',
            'VALIDATION_RISK' => 'A címzett címe a kockázati küszöb felett van.',
            'INVALID_REQUEST' => 'A kérés nem felel meg a küldési szabályoknak (címzett, csatorna vagy fejléc).',
            'GATE_ERROR' => 'Egy ellenőrzés nem tudott dönteni; a küldés biztonsági okból elmaradt.',
            default => 'A küldést a PreSendGate elutasította.',
        };
    }
}
