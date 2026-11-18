<?php

declare(strict_types=1);

namespace App\Modules\Sending\Http\Controllers;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Actions\RecordDeliveryOutcome;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\PostalMessageRef;
use App\Modules\Sending\Models\PostalWebhookReceipt;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A Postal kézbesítési webhookja (deploy-ovh 2. pont) — a bounce így jut a suppressionbe.
 *
 * BIZALOM: a Postal a törzset a saját RSA-kulcsával írja alá (`X-Postal-Signature-256`,
 * SHA-256, base64). Poolonként KÜLÖN kulcs, mert poolonként külön példány fut: a marketing
 * példány nem írhat a tranzakciós pool nevében. Kulcs nélkül fail-closed (503), rossz
 * aláírásra 401. Hálózatilag az `/internal/*` az edge-en nem is érhető el — ez a második
 * védvonal, nem az egyetlen.
 *
 * IDEMPOTENCIA: a Postal 2xx híján újrapróbál. A webhook uuid-ja a feldolgozással EGY
 * tranzakcióban rögzül: ha a feldolgozás elbukik, a rögzítés is visszagörgetődik, és az
 * újrapróbálás újra lefut — ha sikerült, a második kézbesítés no-op.
 */
final class PostalWebhookController
{
    private const POOLS = ['transactional', 'marketing'];

    public function __invoke(
        Request $request,
        string $pool,
        WorkspaceContext $context,
        RecordDeliveryOutcome $outcome,
    ): Response {
        abort_unless(in_array($pool, self::POOLS, true), 404);

        $key = (string) config("sending.postal.pools.{$pool}.webhook_public_key", '');

        if ($key === '') {
            Log::error('Postal webhook: nincs beállítva aláíró kulcs', ['pool' => $pool]);

            return response()->noContent(503);
        }

        $body = $request->getContent();
        $signature = base64_decode((string) $request->header('X-Postal-Signature-256', ''), true);

        if ($signature === false || $signature === '' || openssl_verify($body, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return response()->noContent(401);
        }

        $data = json_decode($body, true);
        $event = is_array($data) ? (string) ($data['event'] ?? '') : '';
        $uuid = is_array($data) ? (string) ($data['uuid'] ?? '') : '';
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        if ($event === '' || $uuid === '') {
            return response()->noContent(400);
        }

        DB::transaction(function () use ($pool, $uuid, $event, $payload, $context, $outcome): void {
            $fresh = PostalWebhookReceipt::query()->insertOrIgnore([
                'pool' => $pool, 'uuid' => $uuid, 'event' => substr($event, 0, 48), 'received_at' => now(),
            ]);

            if ($fresh === 0) {
                return; // már feldolgoztuk
            }

            $token = (string) ($payload['message']['token'] ?? $payload['original_message']['token'] ?? '');
            $ref = PostalMessageRef::resolve($pool, $token);

            // Ismeretlen token: nem a mi üzenetünk (vagy a hivatkozás már lejárt). 2xx, hogy
            // a Postal ne próbálkozzon újra a végtelenségig — de naplózzuk.
            if ($ref === null) {
                Log::info('Postal webhook: ismeretlen token', ['pool' => $pool, 'event' => $event]);

                return;
            }

            $workspace = Workspace::query()->withTrashed()->find($ref->workspace_id);

            if ($workspace === null) {
                return;
            }

            $context->set($workspace);

            try {
                $message = Message::query()->where('uuid', $ref->message_uuid)->first();

                if ($message !== null) {
                    $this->apply($event, $payload, $message, (string) $ref->recipient, $outcome);
                }
            } finally {
                $context->forget();
            }
        });

        return response()->noContent();
    }

    /** @param array<string, mixed> $payload */
    private function apply(string $event, array $payload, Message $message, string $recipient, RecordDeliveryOutcome $outcome): void
    {
        [$code, $enhanced, $text] = $this->smtpReply((string) ($payload['output'] ?? ''));

        match ($event) {
            'MessageSent' => $this->delivered($message, $recipient, (bool) ($payload['sent_with_ssl'] ?? false)),
            // A késleltetés NEM kudarc: a Postal maga próbálja újra (akár 18-szor). Ha soft
            // kísérletként számolnánk, egy greylistelő vagy egy órára leálló fogadó öt
            // késleltetés után VÉGLEG tiltólistára tenné a címet. Csak trace-esemény.
            'MessageDelayed' => $this->event($message, $recipient, 'deferred', ['reason' => 'MTA_DELAYED', 'code' => $code, 'enhanced_code' => $enhanced]),
            // Kód NÉLKÜLI kimenet (időtúllépés, DNS-hiba) nem a címzett válasza: `SoftFail`-nél
            // nem csinálunk belőle 550-es hard bounce-ot (azonnali suppression) — `failed` lesz.
            'MessageDeliveryFailed' => $code === null && ($payload['status'] ?? null) !== 'HardFail'
                ? $this->event($message, $recipient, 'failed', ['reason' => 'MTA_GAVE_UP'])
                : $this->deliveryFailed($message, $recipient, $code ?? 550, $text ?: 'Delivery failed', $enhanced, $outcome),
            // Az aszinkron bounce (DSN) a kézbesítés UTÁN jön: a fogadó elfogadta, majd
            // visszadobta. Végleges kudarcnak kezeljük — a Postal a DSN-t magát nem bontja
            // kódra, és egy halott címre küldeni tovább drágább, mint egy téves tiltás.
            'MessageBounced' => $this->bounced($message, $recipient, $outcome),
            'MessageHeld' => $this->event($message, $recipient, 'deferred', ['reason' => 'MTA_HELD']),
            default => null,
        };
    }

    /**
     * A Postal FELADTA a kézbesítést — ez végleges, akkor is, ha az utolsó válasz ideiglenes
     * (4xx) volt (újrapróbálások kimerültek). 5xx-nél a `RecordDeliveryOutcome` `bounced`-ot ír;
     * 4xx-nél csak `deferred`-et, ezért itt külön `failed` esemény kell — különben a hívó app
     * sosem tudná meg, hogy a levél nem érkezett meg.
     */
    private function deliveryFailed(Message $message, string $recipient, int $code, string $text, ?string $enhanced, RecordDeliveryOutcome $outcome): void
    {
        $class = $outcome($message, $recipient, $code, $text, $enhanced);

        if ($code < 500) {
            MessageEvent::create([
                'message_uuid' => $message->uuid,
                'type' => 'failed',
                'recipient' => $recipient,
                'channel' => $message->channel,
                'reason' => $class->value,
                'tags' => $message->tags,
                'variables' => $message->echoedVariables(),
                // A fogadó SZÖVEGE itt sem megy ki (lásd `RecordDeliveryOutcome`).
                'payload' => ['source' => 'postal', 'code' => $code, 'enhanced_code' => $enhanced, 'classification' => $class->value],
            ]);
        }
    }

    private function delivered(Message $message, string $recipient, bool $tls): void
    {
        $this->event($message, $recipient, 'delivered', ['tls' => $tls]);

        if ($message->status === 'sent') {
            $message->forceFill(['status' => 'delivered'])->save();
        }
    }

    private function bounced(Message $message, string $recipient, RecordDeliveryOutcome $outcome): void
    {
        $outcome($message, $recipient, 550, 'Asynchronous bounce (DSN) received by the MTA', '5.0.0');

        // Egy címzett visszadobása nem a többieké: csak egycímzettes üzenet lesz `bounced`.
        if (count($message->recipients) === 1) {
            $message->forceFill(['status' => 'bounced'])->save();
        }
    }

    /** @param array<string, mixed> $payload */
    private function event(Message $message, string $recipient, string $type, array $payload): void
    {
        MessageEvent::create([
            'message_uuid' => $message->uuid,
            'type' => $type,
            'recipient' => $recipient,
            'channel' => $message->channel,
            'tags' => $message->tags,
            // A hívó a saját `v:` változóival korrelál (Mailgun-paritás); a címzettenkénti
            // `recipient-variables` nem megy ki, az másik címzett adata is lehet.
            'variables' => $message->echoedVariables(),
            'payload' => ['source' => 'postal', ...$payload],
        ]);
    }

    /**
     * A fogadó válasza a Postal `output` mezőjéből: `550 5.1.1 szöveg`.
     *
     * @return array{0: ?int, 1: ?string, 2: string}
     */
    private function smtpReply(string $output): array
    {
        if (preg_match('/^\s*([245]\d\d)[ -](?:([245]\.\d{1,3}\.\d{1,3})\s+)?(.*)$/s', $output, $m) !== 1) {
            return [null, null, trim($output)];
        }

        return [(int) $m[1], ($m[2] ?? '') !== '' ? $m[2] : null, trim(substr($m[3], 0, 500))];
    }
}
