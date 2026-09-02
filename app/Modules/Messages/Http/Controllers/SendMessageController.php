<?php

declare(strict_types=1);

namespace App\Modules\Messages\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Messages\Data\MailgunPayload;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\Tenancy\Services\WorkspaceContext;
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
    public function __invoke(
        Request $request,
        SendMessage $send,
        VentPayClient $ventPay,
        WorkspaceContext $workspace,
    ): JsonResponse {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        // A csatolmányt ma nem támogatjuk — a NÉMA eldobás rosszabb szerződés, mint a hiba:
        // a hívó 202-t kapna, és a levél csatolmány nélkül menne ki.
        if ($request->hasFile('attachment') || $request->hasFile('inline')) {
            return ApiError::badRequest(
                'UNSUPPORTED_ATTACHMENT',
                'A csatolmány-küldés még nem támogatott (ventSec ClamAV-lánc: A4). '
                .'Használj hivatkozást a tartalomra.'
            );
        }

        try {
            $payload = MailgunPayload::fromRequest($request->all());
        } catch (InvalidArgumentException $e) {
            [$code, $message] = array_pad(explode('|', $e->getMessage(), 2), 2, '');

            return ApiError::badRequest($code, $message);
        }

        // Tárolt sablon: a `template=` a Mailgun paritásának része.
        //
        // A sablon törzse VÁLTOZATLANUL megy tovább — NEM renderelünk itt. A korábbi
        // elő-renderelés két passt csinált: az itt behelyettesített érték a küldéskor
        // MÉGEGYSZER átment a `{{ }}` / `%recipient.%` fázison (a `htmlspecialchars` a `{`
        // és a `%` karaktert nem escape-eli), tehát a hívó saját értéke másodszor is
        // kiértékelődött — kész gadget a B1 kontakt-attribútumaihoz. Egy pass, egy helyen:
        // a renderelés a küldési úton történik, a megosztott és a címzettenkénti
        // változókkal EGYSZERRE.
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

            $html = $version->body;
            $text = $version->body_text ?? $text;
        }

        $ipPoolId = null;

        if ($payload->ipPool !== null) {
            // A SAJÁT pool előnyben a platform-szintűvel szemben: névütközésnél különben
            // nem lenne determinisztikus, melyiket kapja a tenant.
            $pool = IpPool::query()
                ->where('name', $payload->ipPool)
                ->orderByRaw('workspace_id is null')
                ->first();

            if ($pool === null) {
                return ApiError::badRequest('UNKNOWN_IP_POOL', "Ismeretlen IP-pool: {$payload->ipPool}");
            }

            // Dedikált pool ENTITLEMENT-hez kötött (ventPay): különben bárki rátehetné a
            // forgalmát egy fizetett, jó reputációjú IP-re.
            // Az ALANY a HÍVÓ, nem a pool tulajdonosa: platform-szintű poolnál a
            // `workspace_id` NULL, tehát a jogosultságot egy nem létező tenantra néztük meg.
            if ($pool->type === 'dedicated' && ! $ventPay->hasEntitlement($workspace->workspaceId(), 'dedicated_ip')) {
                return ApiError::rejected(
                    'ENTITLEMENT_REQUIRED',
                    'A dedikált IP-pool használata a csomagod része kell legyen (dedicated_ip).'
                );
            }

            // Marketing IP nem vihet tranzakciós forgalmat és fordítva (spec 5.):
            // a reputáció-szeparáció az egész ESP-modell alapja.
            if (! $pool->servesChannel($payload->channel)) {
                return ApiError::badRequest('IP_POOL_CHANNEL_MISMATCH', sprintf(
                    'A(z) %s pool a(z) %s csatornára van fenntartva, nem a(z) %s-re.',
                    $pool->name,
                    (string) $pool->channel,
                    $payload->channel
                ));
            }

            $ipPoolId = $pool->getKey();
        }

        $result = $send([
            // A csatornát a payload-parser allowlistázza, a JOGOSULTSÁGOT az `api.channel`
            // middleware dönti el — a kontroller nem hoz biztonsági döntést.
            'channel' => $payload->channel,
            'from_email' => $payload->fromEmail,
            'from_name' => $payload->fromName,
            'subject' => $payload->subject,
            'to' => $payload->recipients,
            'html' => $html,
            'text' => $text,
            'tags' => $payload->tags,
            'variables' => $payload->variables,
            'headers' => $payload->headers,
            'options' => $payload->options,
            'deliverytime' => $payload->deliverytime,
            // A teszt-kulcs testmode-ja NEM kapcsolható le a hívó oldaláról: egy CI-ban
            // hagyott kulcs különben valódi címzettekre küldene (Mailgun-viselkedés).
            'testmode' => $payload->testmode || $apiKey?->testmode === true,
            'ip_pool_id' => $ipPoolId,
            'idempotency_key' => (string) $request->attributes->get('idempotency_key'),
        ]);

        if (! $result->decision->allowed) {
            // Az idempotencia-kulcs újrahasználata a HÍVÓ hibája, nem a gate elutasítása:
            // 409, hogy a kliens tudja, a második levele NEM ment ki.
            if ($result->decision->reason === GateReason::IdempotencyKeyReused->value) {
                return ApiError::response(
                    GateReason::IdempotencyKeyReused->value,
                    'Ezt az Idempotency-Key-t már használtad MÁS kérés-tartalommal. '
                    .'Új levélhez új kulcs kell — különben a második levél csendben elveszne.',
                    409,
                    ['id' => $result->message->uuid]
                );
            }

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
