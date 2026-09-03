<?php

declare(strict_types=1);

namespace App\Modules\Sending\Drivers;

use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
 *
 * A TÖRZSET TÉNYLEG ELKÜLDI. Korábban csak fejléceket adott át: a `SENDING_DRIVER=postal`
 * napján tartalom nélküli levél ment volna ki, és a személyre szabás (a batch egész
 * értelme) nyomtalanul elveszett volna. Ezért címzettenként külön hívás megy — a Postal
 * `send/message` egy hívásban egy törzset visz, tehát a személyre szabás másképp nem fér el.
 */
final class PostalDriver implements SendingDriver
{
    public function send(Message $message): DispatchResult
    {
        $endpoint = $this->endpoint();
        $key = (string) config('sending.postal.api_key');

        if ($key === '') {
            throw new RuntimeException(
                'A PostalDriver nincs konfigurálva (POSTAL_ENDPOINT / POSTAL_API_KEY). '
                .'Amíg a cluster nem él, használd a SENDING_DRIVER=fake beállítást.'
            );
        }

        return ($message->options['raw_mime'] ?? false) === true
            ? $this->sendRaw($message, $endpoint, $key)
            : $this->sendStructured($message, $endpoint, $key);
    }

    /** A nyers MIME VÁLTOZATLANUL megy ki — ez a `/v1/messages.mime` szerződése. */
    private function sendRaw(Message $message, string $endpoint, string $key): DispatchResult
    {
        // Ha aláírtuk, az ALÁÍRT bájtok mennek: az aláírás a nyers levélre szól, tehát a
        // tárolt (aláíratlan) változat kiküldése érvénytelen aláírást jelentene.
        $signed = collect($message->renderedBodies)->pluck('mime')->filter()->first();

        if (is_string($signed)) {
            return $this->result($this->request($key)->post($endpoint.'/api/v1/send/raw', [
                'mail_from' => $message->from_email,
                'rcpt_to' => $message->recipients,
                'data' => base64_encode($signed),
            ]), $message);
        }

        $raw = RawMimeMessage::query()->where('message_uuid', $message->uuid)->first();

        if ($raw === null) {
            // Fail-closed: üres levelet kiküldeni rosszabb, mint nem küldeni. A dispatcher
            // ebből `deferred`-et csinál, és a trace megmutatja, mi hiányzott.
            throw new RuntimeException(sprintf(
                'A(z) %s üzenethez nincs eltárolt nyers MIME — üres levelet nem küldünk ki.',
                $message->uuid
            ));
        }

        $response = $this->request($key)->post($endpoint.'/api/v1/send/raw', [
            'mail_from' => $message->from_email,
            'rcpt_to' => $message->recipients,
            'data' => base64_encode($raw->raw),
        ]);

        return $this->result($response, $message);
    }

    private function sendStructured(Message $message, string $endpoint, string $key): DispatchResult
    {
        $ids = [];

        // Akiket egy KORÁBBI, félbeszakadt próbálkozás már kiküldött, azokat kihagyjuk:
        // a batch címzettenként megy ki, tehát az újrapróbálás különben nekik másodszor
        // is kézbesítene (golden rule 5 a hálózat felé is).
        $already = (array) ($message->options['delivered_recipients'] ?? []);
        $accepted = [];

        foreach ($message->recipients as $recipient) {
            $recipient = (string) $recipient;

            if (in_array($recipient, $already, true)) {
                continue;
            }
            $bodies = $message->renderedBodies[$recipient] ?? ['html' => null, 'text' => null];

            // Ha MI írtuk alá (DKIM), akkor a kész, ALÁÍRT levél megy ki — az MTA nem
            // nyúlhat hozzá. Bármilyen újraszerkesztés (fejléc-átrendezés, újrakódolás)
            // érvénytelenné tenné az aláírást, és a fogadó hamisítványnak látná.
            if (($bodies['mime'] ?? null) !== null) {
                $response = $this->request($key)->post($endpoint.'/api/v1/send/raw', [
                    'mail_from' => $message->from_email,
                    'rcpt_to' => [$recipient],
                    'data' => base64_encode((string) $bodies['mime']),
                ]);

                if (! $response->successful()) {
                    return DispatchResult::partial(
                        'Postal hiba: '.$response->status(),
                        $accepted,
                        // A fogadó SMTP-kódját a Postal a válaszban adja vissza; hiányában az
                        // 5xx/4xx különbség marad az egyetlen jel.
                        (int) ($response->json('data.code') ?? ($response->status() >= 500 ? 451 : 550)),
                        (string) ($response->json('data.message') ?? 'Postal '.$response->status())
                    );
                }

                $accepted[] = $recipient;
                $ids[] = (string) $response->json('data.message_id', '');

                continue;
            }

            if (($bodies['html'] ?? null) === null && ($bodies['text'] ?? null) === null) {
                throw new RuntimeException(sprintf(
                    'A(z) %s üzenetnek nincs kirenderelt törzse a(z) %s címzettre — '
                    .'tartalom nélküli levelet nem küldünk ki.',
                    $message->uuid,
                    hash('sha256', $recipient)
                ));
            }

            $response = $this->request($key)->post($endpoint.'/api/v1/send/message', [
                'to' => [$recipient],
                'from' => $message->from_name === null
                    ? $message->from_email
                    : $message->from_name.' <'.$message->from_email.'>',
                'subject' => $message->subject,
                'html_body' => $bodies['html'],
                'plain_body' => $bodies['text'],
                'headers' => $message->headers,
                'tag' => $message->tags[0] ?? null,
                // Idempotencia a MÁSIK oldalon is: egy retry különben kétszer kézbesítene.
                // CÍMZETTENKÉNT egyedi, különben a második címzett a firstre futna rá.
                'idempotency_key' => $message->uuid.':'.hash('sha256', $recipient),
            ]);

            if (! $response->successful()) {
                // A már kiment címzettek NEM vesznek el a jelentésből: enélkül a trace
                // mindenkire `deferred`-et írna, az újrapróbálás pedig duplán kézbesítene.
                return DispatchResult::partial(
                    'Postal hiba: '.$response->status(),
                    $accepted,
                    // A fogadó SMTP-kódját a Postal a válaszban adja vissza; hiányában az
                    // 5xx/4xx különbség marad az egyetlen jel.
                    (int) ($response->json('data.code') ?? ($response->status() >= 500 ? 451 : 550)),
                    (string) ($response->json('data.message') ?? 'Postal '.$response->status())
                );
            }

            $accepted[] = $recipient;
            $ids[] = (string) $response->json('data.message_id', '');
        }

        // Az MTA átvette — a KÉZBESÍTÉS visszaigazolása a webhookból jön (A3).
        return DispatchResult::accepted(implode(',', $ids));
    }

    private function result(Response $response, Message $message): DispatchResult
    {
        if (! $response->successful()) {
            return DispatchResult::failed(
                'Postal hiba: '.$response->status(),
                (int) ($response->json('data.code') ?? ($response->status() >= 500 ? 451 : 550)),
                (string) ($response->json('data.message') ?? 'Postal '.$response->status())
            );
        }

        return DispatchResult::accepted((string) $response->json('data.message_id', ''));
    }

    private function request(string $key): PendingRequest
    {
        // Az API-kulcs FEJLÉCBEN megy: titkosítatlan végponton elhagyná a hálózatot.
        return Http::withHeaders(['X-Server-API-Key' => $key])
            ->withOptions(['verify' => true])
            ->timeout(30)
            ->connectTimeout(5);
    }

    private function endpoint(): string
    {
        $endpoint = rtrim((string) config('sending.postal.endpoint'), '/');

        if ($endpoint === '') {
            throw new RuntimeException(
                'A PostalDriver nincs konfigurálva (POSTAL_ENDPOINT / POSTAL_API_KEY). '
                .'Amíg a cluster nem él, használd a SENDING_DRIVER=fake beállítást.'
            );
        }

        if (! str_starts_with($endpoint, 'https://')) {
            throw new RuntimeException(
                'A POSTAL_ENDPOINT csak https lehet: az X-Server-API-Key fejlécben utazik.'
            );
        }

        return $endpoint;
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
            $ok = Http::timeout(3)->get(rtrim($endpoint, '/').'/health')->successful();
        } catch (\Throwable $e) {
            // A nyers kivétel-üzenet a teljes URL-t hordozza (tokennel együtt), és ez egy
            // operátori felületre menne ki. A részlet a szerver-logba való.
            Log::warning('Postal health-check hiba', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return new DriverHealth(healthy: false, detail: 'A Postal health-check hibára futott.');
        }

        return new DriverHealth(healthy: $ok, detail: $ok ? 'Postal elérhető.' : 'Postal nem válaszol.');
    }
}
