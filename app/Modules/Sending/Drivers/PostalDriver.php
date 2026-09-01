<?php

declare(strict_types=1);

namespace App\Modules\Sending\Drivers;

use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Facades\Http;
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

        $response = Http::withHeaders(['X-Server-API-Key' => $key])
            ->timeout(10)
            ->post($endpoint.'/api/v1/send/message', [
                'to' => $message->recipients,
                'from' => $message->from_email,
                'subject' => $message->subject,
                // A tartalom-összeállítás az A1 packet feladata; itt a kontraktus a lényeg.
                'headers' => $message->headers,
            ]);

        if (! $response->successful()) {
            return DispatchResult::failed('Postal hiba: '.$response->status());
        }

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
            return new DriverHealth(healthy: false, detail: $e->getMessage());
        }

        return new DriverHealth(healthy: $ok, detail: $ok ? 'Postal elérhető.' : 'Postal nem válaszol.');
    }
}
