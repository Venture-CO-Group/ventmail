<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Jobs;

use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Services\InboundPayload;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * `forward(url)` — a beérkezett levél ALÁÍRT POST-ja az ügyfél végpontjára (31. kritérium).
 *
 * UGYANAZ az aláírás-séma, mint a webhookoké (A3): `HMAC-SHA256(timestamp.payload)`. Nem
 * kényelmi döntés — az integrátornak nem kell két különböző aláírás-ellenőrzőt írnia, és
 * amit egyszer helyesen megcsináltunk, azt nem írjuk meg másodszor is, kicsit másképp.
 *
 * ÉS ugyanaz az SSRF-guard: a cél-URL-t az ÜGYFÉL adja meg, a kérést MI indítjuk a saját
 * hálózatunkból. A route-szabály `forward("https://169.254.169.254/…")`-je pontosan olyan
 * veszélyes, mint egy webhook-végpont ugyanarra a címre.
 */
final class ForwardInboundToUrl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Az újrapróbálást MI ütemezzük, láthatóan — nem a queue-worker. */
    public int $tries = 1;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $messageUuid,
        private readonly string $url,
        private readonly int $attempt = 1,
    ) {
        $this->afterCommit();
    }

    public function handle(
        WorkspaceContext $context,
        InboundPayload $payloads,
        WebhookSigner $signer,
        WebhookUrlGuard $guard,
    ): void {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $message = InboundMessage::query()->where('uuid', $this->messageUuid)->with('attachments')->first();

            if ($message === null) {
                return;
            }

            try {
                $target = $guard->approve($this->url);
            } catch (InvalidArgumentException $e) {
                // Tiltott cél: nem múlik el magától, nincs értelme újrapróbálni.
                Log::warning('A route forward célja nem publikus címre mutat.', [
                    'workspace_id' => $this->workspaceId,
                ]);

                return;
            } catch (RuntimeException) {
                $this->retryLater();

                return;
            }

            $payload = (string) json_encode($payloads->for($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $timestamp = now()->getTimestamp();

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-VentMail-Timestamp' => (string) $timestamp,
                'X-VentMail-Signature' => $signer->sign($this->secret($workspace), $payload, $timestamp),
                'X-VentMail-Event' => 'inbound.message',
                'X-VentMail-Delivery' => (string) $message->uuid,
            ])
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => false,
                    'curl' => $target->curlOptions(),
                ])
                ->timeout((int) config('inbound.forward.timeout_seconds'))
                ->connectTimeout(5)
                ->withBody($payload, 'application/json')
                ->post($this->url);

            if (! $response->successful()) {
                $this->retryLater();
            }
        } catch (Throwable) {
            $this->retryLater();
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /**
     * Exponenciális visszalépés, plafonnal.
     *
     * A plafon nem formalitás: nélküle egy tartósan halott végpont örökké tartana egy jobot
     * életben, és a visszalépés magas kitevőnél floattá csúszna.
     */
    private function retryLater(): void
    {
        if ($this->attempt >= (int) config('inbound.forward.max_attempts')) {
            Log::warning('A bejövő levél továbbítása véglegesen elhasalt.', [
                'inbound_message' => $this->messageUuid,
            ]);

            return;
        }

        self::dispatch($this->workspaceId, $this->messageUuid, $this->url, $this->attempt + 1)
            ->delay(now()->addSeconds(60 * (2 ** ($this->attempt - 1))));
    }

    /**
     * A TENANT aláíró titka.
     *
     * A workspace-hez kötött, `APP_KEY`-jel származtatott érték: így a fogadó ellenőrizni
     * tudja, hogy tőlünk jött, és két tenant nem tudja egymás payloadját aláírni.
     */
    private function secret(Workspace $workspace): string
    {
        return hash_hmac('sha256', 'inbound-forward:'.$workspace->uuid, (string) config('app.key'));
    }
}
