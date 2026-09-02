<?php

declare(strict_types=1);

namespace App\Modules\Sending\Drivers;

use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Éles MTA-driver (self-hosted Postal). SZÁNDÉKOSAN INAKTÍV: a `SENDING_DRIVER=postal`
 * kapcsolja be, és csak akkor működik, ha a Postal cluster és a hitelesítők élnek
 * (halasztott ops-sáv).
 *
 * Itt van, hogy a kontraktus mérhető legyen: ha a `FakeDriver`-ről a váltás bármi mást is
 * igényelne, mint config-változást, az már most kiderül — nem az éles indulás napján.
 */
final class PostalDriver implements SendingDriver
{
    public function send(Message $message): DispatchResult
    {
        $endpoint = (string) config('sending.postal.endpoint');
        $key = (string) config('sending.postal.api_key');

        if ($endpoint === '' || $key === '') {
            throw new RuntimeException(
                'A PostalDriver nincs konfigurálva (POSTAL_ENDPOINT / POSTAL_API_KEY). '
                .'Amíg a cluster nem él, használd a SENDING_DRIVER=fake beállítást.'
            );
        }

        // Az API-kulcs FEJLÉCBEN megy: titkosítatlan végponton elhagyná a hálózatot.
        if (! str_starts_with($endpoint, 'https://')) {
            throw new RuntimeException(
                'A POSTAL_ENDPOINT csak https lehet: az X-Server-API-Key fejlécben utazik.'
            );
        }

        $response = Http::withHeaders(['X-Server-API-Key' => $key])
            ->withOptions(['verify' => true])
            ->timeout(10)
            ->connectTimeout(5)
            ->post($endpoint.'/api/v1/send/message', [
                'to' => $message->recipients,
                'from' => $message->from_email,
                'subject' => $message->subject,
                // A tartalom-összeállítás az A1 packet feladata; itt a kontraktus a lényeg.
                'headers' => $message->headers,
                // Idempotencia a MÁSIK oldalon is: egy retry különben kétszer kézbesítene.
                'idempotency_key' => $message->uuid,
            ]);

        if (! $response->successful()) {
            return DispatchResult::failed('Postal hiba: '.$response->status());
        }

        // Az MTA átvette — a KÉZBESÍTÉS visszaigazolása a webhookból jön (A3).
        return DispatchResult::accepted((string) $response->json('data.message_id', ''));
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            name: 'postal',
            supportsRawMime: true,
            supportsIpPools: true,
            supportsScheduling: false, // az ütemezést a ventMail sora végzi, nem a Postal
            supportsTracking: true,
        );
    }

    public function health(): DriverHealth
    {
        $endpoint = (string) config('sending.postal.endpoint');

        if ($endpoint === '') {
            return new DriverHealth(healthy: false, detail: 'Nincs POSTAL_ENDPOINT beállítva.');
        }

        try {
            $ok = Http::timeout(3)->get($endpoint.'/health')->successful();
        } catch (\Throwable $e) {
            // A nyers kivétel-üzenet a teljes URL-t hordozza (tokennel együtt), és ez egy
            // operátori felületre menne ki. A részlet a szerver-logba való.
            Log::warning('Postal health-check hiba', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return new DriverHealth(healthy: false, detail: 'A Postal health-check hibára futott.');
        }

        return new DriverHealth(healthy: $ok, detail: $ok ? 'Postal elérhető.' : 'Postal nem válaszol.');
    }
}
