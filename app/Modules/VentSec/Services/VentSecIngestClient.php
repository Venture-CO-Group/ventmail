<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Services;

use App\Modules\VentSec\Support\SecureUrl;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * `POST {VENTSEC_INGEST_URL}/ingest/v1/events` — Bearer m2m JWT + HMAC a NYERS törzsre.
 *
 * Visszatérés: `true` (202, elfogadva) / `false` (végleges elutasítás — pl. 403 `ingest_gated`,
 * 400/422 —, újrapróbálni nem érdemes). Újrapróbálható hibára (hálózat, 5xx, 429, 401 a token
 * eldobása után, hiányzó token) RuntimeException — a hívó job ezt retry-olja. Az üzenet nem
 * hordoz titkot, tokent, törzset.
 */
final class VentSecIngestClient
{
    public function __construct(private readonly ServiceTokenProvider $tokens) {}

    /**
     * @param  list<array<string, mixed>>  $events
     *
     * @throws RuntimeException újrapróbálható hiba
     */
    public function send(array $events): bool
    {
        $base = rtrim((string) config('ventsec.events.url'), '/');
        $secret = (string) config('ventsec.events.source_secret');

        if ($base === '' || $secret === '' || ! SecureUrl::ok($base)) {
            return false;
        }

        $token = $this->tokens->token() ?? throw new RuntimeException('ventSec ingest: nincs m2m token.');

        // EGYSZER kódoljuk: a HMAC és a küldött törzs bájtra azonos.
        $body = (string) json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = Http::withToken($token)
                ->withHeaders(['X-VentSec-Signature' => IngestSignature::header($body, $secret)])
                ->withBody($body, 'application/json')
                ->timeout((int) config('ventsec.events.timeout'))->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->post($base.'/ingest/v1/events');
        } catch (Throwable $e) {
            throw new RuntimeException('ventSec ingest: hálózati hiba ('.$e::class.').');
        }

        $status = $response->status();

        if ($status === 202) {
            return true;
        }

        if ($status === 401) {
            $this->tokens->forget();
        }

        if ($status === 401 || $status === 429 || $status >= 500) {
            throw new RuntimeException("ventSec ingest: HTTP {$status}.");
        }

        return false;
    }
}
