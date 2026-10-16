<?php

declare(strict_types=1);

namespace App\Modules\Sending\Drivers;

use App\Modules\IpPools\Models\IpAddress;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Data\DriverCapabilities;
use App\Modules\Sending\Data\DriverHealth;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\PostalMessageRef;
use App\Modules\Sending\Models\RawMimeMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mime\Address;

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
    /** Az aktuális küldés pool-osztálya — a webhook-hivatkozás ehhez kötődik. */
    private string $pool = 'transactional';

    public function send(Message $message): DispatchResult
    {
        [$endpoint, $key, $this->pool] = $this->target($message);

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
                $this->remember($message, $response);

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
                // A NÉV RFC 5322 szerint idézve / kódolva (review N6): egy vesszős vagy idézőjeles
                // név a nyers `név <cím>` alakban két címzettnek vagy hibás fejlécnek látszott.
                'from' => $message->from_name === null
                    ? $message->from_email
                    : (new Address((string) $message->from_email, (string) $message->from_name))->toString(),
                'subject' => $message->subjectFor($recipient),
                'html_body' => $bodies['html'],
                'plain_body' => $bodies['text'],
                // CÍMZETTENKÉNT: a leiratkozó token a leiratkozó SZEMÉLYÉT hordozza,
                // tehát a batch minden levelébe a sajátja kell (lásd `headersFor`).
                'headers' => $message->headersFor($recipient),
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
            $this->remember($message, $response);
        }

        // Az MTA átvette — a KÉZBESÍTÉS visszaigazolása a webhookból jön (A3).
        return DispatchResult::accepted(implode(',', $ids));
    }

    /**
     * A Postal címzettenkénti tokenje → ventMail-üzenet. A kézbesítési webhook CSAK ezt a
     * tokent hozza: e nélkül a bounce nem lenne kihez kötni.
     */
    private function remember(Message $message, Response $response): void
    {
        // A levelet a Postal MÁR átvette: a hivatkozás mentésének hibája nem fordíthatja
        // vissza az eredményt (az újrapróbálás dupla kézbesítés lenne). A webhook ilyenkor
        // „ismeretlen token"-t naplóz — ez a kisebbik rossz.
        try {
            foreach ((array) $response->json('data.messages', []) as $recipient => $ref) {
                $token = is_array($ref) ? (string) ($ref['token'] ?? '') : '';

                if ($token === '') {
                    continue;
                }

                // SAVEPOINT: egy hibás írás Postgresen különben a hívó TELJES tranzakcióját
                // elrontaná („current transaction is aborted").
                DB::transaction(fn () => PostalMessageRef::query()->updateOrCreate(
                    ['pool' => $this->pool, 'token' => $token],
                    ['message_uuid' => $message->uuid, 'recipient' => strtolower((string) $recipient)]
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Postal-hivatkozás mentése sikertelen (a levél kiment)', [
                'message_uuid' => $message->uuid,
                'exception' => $e::class,
            ]);
        }
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

        $this->remember($message, $response);

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

    /**
     * A csatorna POOL-OSZTÁLYÁHOZ tartozó Postal-példány (deploy-ovh, kemény szabály 5).
     *
     * Fail-closed minden ponton: hiányzó példány, közös végpont vagy közös Return-Path
     * domain esetén NINCS küldés — a „majd a másikon kimegy" pont a keveredés lenne.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function target(Message $message): array
    {
        $channel = Channel::tryFrom((string) $message->channel)
            ?? throw new RuntimeException('Ismeretlen csatorna — a pool nem dönthető el.');
        $class = $channel->poolClass();

        $this->assertPoolsSeparated();

        // Ha a dispatcher már IP-t foglalt, annak a poolja IS ugyanebbe az osztályba kell
        // essen. Egy rossz pool-hozzárendelés itt áll meg, nem a fogadónál.
        $poolChannel = $message->ip_address_id === null
            ? null
            : IpAddress::query()->with('pool')->find($message->ip_address_id)?->pool?->channel;

        if ($poolChannel !== null && Channel::tryFrom((string) $poolChannel)?->poolClass() !== $class) {
            throw new RuntimeException(sprintf(
                'A(z) %s üzenet (%s) %s osztályú IP-re van foglalva — a poolok nem keveredhetnek.',
                $message->uuid,
                $channel->value,
                (string) $poolChannel
            ));
        }

        $endpoint = rtrim((string) config("sending.postal.pools.{$class}.endpoint"), '/');
        $key = (string) config("sending.postal.pools.{$class}.api_key");

        // A régi, egy-végpontos beállítás CSAK tranzakciós forgalomra esik vissza.
        if ($endpoint === '' && $class === 'transactional') {
            $endpoint = rtrim((string) config('sending.postal.endpoint'), '/');
            $key = (string) config('sending.postal.api_key');
        }

        if ($endpoint === '' || $key === '') {
            throw new RuntimeException(sprintf(
                'A PostalDriver %s poolja nincs konfigurálva (POSTAL_%s_ENDPOINT / POSTAL_%s_API_KEY). '
                .'Amíg a cluster nem él, használd a SENDING_DRIVER=fake beállítást.',
                $class,
                $class === 'transactional' ? 'TX' : 'MK',
                $class === 'transactional' ? 'TX' : 'MK'
            ));
        }

        $this->assertSecureEndpoint($endpoint);

        return [$endpoint, $key, $class];
    }

    private function assertPoolsSeparated(): void
    {
        $tx = (array) config('sending.postal.pools.transactional', []);
        $mk = (array) config('sending.postal.pools.marketing', []);

        $same = static fn (string $field): bool => ($tx[$field] ?? '') !== ''
            && strtolower(rtrim((string) $tx[$field], '/')) === strtolower(rtrim((string) ($mk[$field] ?? ''), '/'));

        if ($same('endpoint') || $same('api_key') || $same('return_path_domain')) {
            throw new RuntimeException(
                'A tranzakciós és a marketing pool ugyanarra a Postal-példányra / Return-Path '
                .'domainre van konfigurálva — a két pool nem keveredhet, küldés nincs.'
            );
        }
    }

    /**
     * Az API-kulcs FEJLÉCBEN utazik, tehát a végpont `https://` — egyetlen kivétellel: az
     * EGYCÍMKÉS hostnév (`postal-tx`). Ilyen nevet csak a docker belső (internal) hálózat
     * DNS-e old fel, a forgalom a gépet nem hagyja el. Minden pontot tartalmazó név —
     * vagy IP-cím — kívülről is feloldható/elérhető lehet, ezért ott marad a https.
     */
    private function assertSecureEndpoint(string $endpoint): void
    {
        if (str_starts_with($endpoint, 'https://')) {
            return;
        }

        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        if (str_starts_with($endpoint, 'http://') && preg_match('/^[a-z][a-z0-9-]{0,62}$/i', $host) === 1) {
            return;
        }

        throw new RuntimeException(
            'A Postal végpont csak https lehet (vagy http egycímkés belső hostnévvel): '
            .'az X-Server-API-Key fejlécben utazik.'
        );
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
        // A tranzakciós példány a mérvadó: ő viszi a jelszó-visszaállítót.
        $endpoint = (string) (config('sending.postal.pools.transactional.endpoint') ?: config('sending.postal.endpoint'));

        if ($endpoint === '') {
            return new DriverHealth(healthy: false, detail: 'Nincs POSTAL_ENDPOINT beállítva.');
        }

        try {
            // A Postal WEBSZERVERÉNEK nincs `/health` végpontja (az csak a worker és az SMTP
            // külön health-portján van) — a bejelentkező oldal a legolcsóbb valódi válasz.
            $ok = Http::timeout(3)->get(rtrim($endpoint, '/').'/login')->successful();
        } catch (\Throwable $e) {
            // A nyers kivétel-üzenet a teljes URL-t hordozza (tokennel együtt), és ez egy
            // operátori felületre menne ki. A részlet a szerver-logba való.
            Log::warning('Postal health-check hiba', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return new DriverHealth(healthy: false, detail: 'A Postal health-check hibára futott.');
        }

        return new DriverHealth(healthy: $ok, detail: $ok ? 'Postal elérhető.' : 'Postal nem válaszol.');
    }
}
