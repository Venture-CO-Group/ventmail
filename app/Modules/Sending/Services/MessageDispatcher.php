<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Events\Services\TrackingBaseUrl;
use App\Modules\Events\Services\TrackingRewriter;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\IpPools\Services\IpPoolSelector;
use App\Modules\Sending\Actions\RecordDeliveryOutcome;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Data\DispatchResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A `SendingDriver::send()` EGYETLEN hívója (golden rule 2).
 *
 * Garanciák:
 * 1. Csak `accepted` — tehát a PreSendGate kapuin átment — üzenetet küld.
 * 2. IDEMPOTENS: az `accepted → sending` átmenet feltételes UPDATE, amit az adatbázis
 *    egyszer enged. Kétszeri dispatch egy küldés, versenyhelyzetben is.
 * 3. NEM VESZÍT EL ÜZENETET: a `sending` állapot LÍZINGELT. Ha a driver kivételre fut vagy
 *    a process meghal, a lízing lejárta után az üzenet újraigényelhető — enélkül a levél
 *    csendben eltűnt volna, esemény és riasztás nélkül.
 */
final class MessageDispatcher
{
    /** Az az állapot, ahonnan a küldés indulhat: a PreSendGate kapuin átment üzenet. */
    private const DISPATCHABLE = 'accepted';

    /** Ezekbe az állapotokba a küldés MÁR eljutott — az ismételt hívás no-op. */
    private const TERMINAL = ['delivered', 'sent', 'bounced'];

    /** Hibára futott, de újrapróbálható állapotok. */
    private const RETRYABLE = ['deferred'];

    public function __construct(
        private readonly SendingDriver $driver,
        private readonly WorkspaceContext $context,
        private readonly IpPoolSelector $pools,
        private readonly OutboundMimeBuilder $mime,
        private readonly DkimSigner $dkim,
        private readonly RawMimeDkimSigner $rawDkim,
        private readonly DomainThrottle $throttle,
        private readonly RecordDeliveryOutcome $outcome,
        private readonly RetryPolicy $retry,
        private readonly TemplateRenderer $renderer = new TemplateRenderer,
        private readonly TrackingRewriter $tracking = new TrackingRewriter(new TrackingTokens),
        private readonly TrackingBaseUrl $trackingBase = new TrackingBaseUrl,
    ) {}

    public function dispatch(Message $message): void
    {
        // A gate MEGKERÜLÉSE hiba; az ISMÉTELT küldés viszont csak no-op.
        if (in_array($message->status, self::TERMINAL, true)) {
            return;
        }

        if ($message->status === 'sending' && ! $this->leaseExpired($message)) {
            return; // egy másik futás épp küldi
        }

        if (! in_array($message->status, [self::DISPATCHABLE, 'sending', ...self::RETRYABLE], true)) {
            throw new RuntimeException(sprintf(
                'A(z) %s üzenet állapota „%s" — csak a PreSendGate-en átment, `accepted` '
                .'üzenet küldhető (golden rule 2).',
                $message->uuid,
                $message->status
            ));
        }

        // A tenant-kontextus itt is kötelező: enélkül a scope 0 sort adna, és a küldés
        // NÉMÁN elmaradna. Jobban járunk hangos hibával.
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException(
                'A küldéshez workspace-kontextus kell — állítsd be a jobban a WorkspaceContextet.'
            );
        }

        // A gate-döntés SZAVATOSSÁGI IDEJE: ütemezett küldésnél a közben érkezett
        // leiratkozás vagy domain-deverifikáció különben nem számítana.
        if ($this->decisionStale($message)) {
            throw new RuntimeException(sprintf(
                'A(z) %s üzenet gate-döntése elavult (%s) — újraértékelés szükséges küldés előtt.',
                $message->uuid,
                (string) $message->gate_decided_at
            ));
        }

        if (! $this->claim($message)) {
            return; // más futás nyerte meg
        }

        // A `claim()` az ADATBÁZISBAN növelte a számlálót: a memóriabeli példány különben
        // eggyel kevesebbet mutatna, és a visszalépés meg a feladás-plafon MÁS eredményre
        // jutna, mint a job oldali számítás.
        $message->attempts = (int) $message->attempts + 1;

        // A FOGADÓ-DOMAIN sebességkorlátja. A nagy fogadók előbb lassítanak (4xx), aztán
        // blokkolnak: jobb nekünk visszafogni, mint tőlük 4xx-et gyűjteni.
        if (! $this->withinDomainThrottle($message)) {
            return;
        }

        // A KÜLDŐ IP lefoglalása. A warmup napi kerete nem tanács: egy friss IP-ről
        // kiengedett tízezer levél azonnali blocklist. Ha nincs szabad keret, az üzenet
        // NEM megy ki — SORBAN MARAD (`deferred`), és a következő futás újrapróbálja.
        $rendered = [];

        try {
            if (! $this->assignIp($message)) {
                return;
            }

            // A személyre szabott törzs EGYSZER készül el, és ugyanaz megy a drivernek és
            // az esemény-lenyomatnak. Korábban kétszer-háromszor renderelt ugyanaz a küldés.
            //
            // A RENDERELÉS ÉS AZ ALÁÍRÁS IS A TRY-BAN VAN: hibás címzett-szintaxis vagy
            // hiányzó DKIM-kulcs esetén a kivétel korábban a `dispatch()`-ból szállt ki,
            // az üzenet `sending` állapotban ragadt (esemény és ok nélkül, tehát a trace
            // vakon maradt) — a napi IP-keret viszont már elfogyott.
            $rendered = $this->renderAll($message);

            // A driver a SZEMÉLYRE SZABOTT törzset kapja címzettenként — a batch lényege,
            // hogy mindenki a sajátját kapja. (A címzettenkénti ÚJRAPRÓBÁLÁS granularitása
            // az A2 sorkezelőjének a feladata; itt üzenet-szintű a claim.)
            $result = $this->driver->send($message->withRenderedBodies($rendered));
        } catch (Throwable $e) {
            // A hiba RÖGZÜL: állapot + esemény, hogy az újrapróbálás elindulhasson és a
            // trace megmutassa, mi történt. A kivétel SZÖVEGE nem kerül a sorba
            // (titok-szivárgás ellen) — az a szerver-logba megy.
            $this->markDeferred($message, $e, $rendered);

            throw $e;
        }

        $this->recordOutcome($message, $result, $rendered);
    }

    /**
     * Beleférünk-e a fogadó domain percenkénti keretébe?
     *
     * Ha nem, az üzenet VÁR — a `next_attempt_at`-ot a `RetryPolicy` adja, tehát az
     * újrapróbálás nem azonnal jön (az csak tovább terhelné ugyanazt a fogadót).
     */
    private function withinDomainThrottle(Message $message): bool
    {
        // DOMAINENKÉNT egy token, nem címzettenként: egy vegyes batch különben a saját maga
        // elé húzta volna a limitet, és az újrapróbálás mindet ÚJRA elhasználta volna.
        $domains = collect($message->recipients)
            ->map(fn ($recipient) => DomainNormalizer::ofEmail((string) $recipient))
            ->filter()
            ->unique();

        foreach ($domains as $domain) {
            if (! $this->throttle->allow($domain)) {
                $message->forceFill([
                    'status' => 'deferred',
                    'reason' => 'DOMAIN_THROTTLED',
                    'next_attempt_at' => now()->addSeconds($this->retry->delaySeconds((int) $message->attempts + 1)),
                ])->save();

                $this->events($message, 'deferred', [], ['reason' => 'DOMAIN_THROTTLED']);

                return false;
            }
        }

        return true;
    }

    /**
     * Küldő IP lefoglalása a warmup keretéből.
     *
     * Ha nincs szabad IP (napi limit elfogyott, vagy minden IP karanténban), akkor a levél
     * VÁR — nem megy ki „valamivel". A karanténba tett IP-t hajtani pont a legrosszabb:
     * azzal a domain reputációját is elvinné.
     */
    private function assignIp(Message $message): bool
    {
        // Ha a poolokat még nem konfigurálták (fejlesztés, fake driver), a küldés megy —
        // a warmup akkor kezd számítani, amikor valódi IP-k vannak.
        if (IpPool::query()->count() === 0) {
            return true;
        }

        $address = $this->pools->claim($message);

        if ($address === null) {
            $message->forceFill([
                'status' => 'deferred',
                'reason' => 'NO_SENDING_IP',
                'next_attempt_at' => now()->addMinutes(15),
            ])->save();

            $this->events($message, 'deferred', [], ['reason' => 'NO_SENDING_IP']);

            return false;
        }

        $message->forceFill([
            'ip_address_id' => $address->getKey(),
            'ip_pool_id' => $message->ip_pool_id ?? $address->ip_pool_id,
        ])->save();

        return true;
    }

    private function claim(Message $message): bool
    {
        // Atomikus állapotátmenet ELOQUENTEN, tehát a tenant global scope-pal együtt.
        // (Nyers DB::table itt megkerülné az izolációt — golden rule 6.)
        $claimed = Message::query()
            ->whereKey($message->getKey())
            ->where(function ($query) {
                $query->where('status', self::DISPATCHABLE)
                    ->orWhereIn('status', self::RETRYABLE)
                    ->orWhere(fn ($stale) => $stale->where('status', 'sending')
                        ->where('sending_started_at', '<=', now()->subSeconds($this->leaseSeconds())));
            })
            ->update([
                'status' => 'sending',
                'sending_started_at' => now(),
                'attempts' => $message->attempts + 1,
                'updated_at' => now(),
            ]);

        return $claimed > 0;
    }

    /** @param array<string, array{html: ?string, text: ?string}> $rendered */
    private function recordOutcome(Message $message, DispatchResult $result, array $rendered): void
    {
        // A driver „elfogadta" NEM azonos a „kézbesítve"-vel: egy valódi MTA 2xx-e csak
        // annyit jelent, hogy sorba állt. A `delivered` a driver visszaigazolásából jön
        // (a FakeDriver szimulálja az MTA visszajelzését is).
        $status = match (true) {
            ! $result->accepted => 'deferred',
            $result->delivered => 'delivered',
            default => 'sent',
        };

        // A RÉSZBEN kiment batch: akiket az MTA már átvett, azokat megjegyezzük, hogy az
        // újrapróbálás NE kézbesítsen nekik másodszor, és a trace se írjon rájuk
        // `deferred`-et. (A teljes címzettenkénti állapotgép az A2 sorkezelőjéé.)
        $alreadyDelivered = (array) ($message->options['delivered_recipients'] ?? []);

        $delivered = array_values(array_unique(array_merge(
            $alreadyDelivered,
            $result->accepted ? $message->recipients : $result->acceptedRecipients
        )));

        $message->forceFill([
            'status' => $status,
            'reason' => $result->accepted ? null : 'DRIVER_REJECTED',
            'sent_at' => now(),
            'options' => [...$message->options, 'delivered_recipients' => $delivered],
        ])->save();

        // A fogadó VÁLASZA feldolgozva: hard bounce → azonnali suppression, soft → N próba
        // után. Enélkül a bounce-osztályozó egy sosem hívott, tiszta függvény lenne.
        if (! $result->accepted && $result->code !== null) {
            foreach (array_diff($message->recipients, $delivered) as $recipient) {
                ($this->outcome)(
                    $message,
                    (string) $recipient,
                    $result->code,
                    (string) $result->response,
                    $result->enhancedCode,
                    // CÍMZETTENKÉNT: a közös átirat idegen címzett címét vinné a naplóba.
                    (array) ($result->transcript[$recipient] ?? []),
                );
            }
        }

        $successful = $status === 'delivered' ? 'delivered' : 'accepted';

        if ($result->accepted) {
            $this->events($message, $successful, $rendered, ['provider_message_id' => $result->providerMessageId]);

            return;
        }

        // Kevert kimenet: akik megkapták, azokra sikeres esemény megy, a többire halasztás.
        $newlyAccepted = array_values(array_diff($result->acceptedRecipients, $alreadyDelivered));

        if ($newlyAccepted !== []) {
            $this->events($message, 'accepted', $rendered, ['provider_message_id' => $result->providerMessageId], $newlyAccepted);
        }

        $this->events(
            $message,
            'deferred',
            $rendered,
            ['provider_message_id' => $result->providerMessageId],
            array_values(array_diff($message->recipients, $delivered))
        );
    }

    /** @param array<string, array{html: ?string, text: ?string}> $rendered */
    private function markDeferred(Message $message, Throwable $e, array $rendered): void
    {
        Log::error('A küldés meghiúsult a drivernél', [
            'message_uuid' => $message->uuid,
            'driver' => $this->driver->capabilities()->name,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        $attempt = (int) $message->attempts;

        $message->forceFill([
            // A `failed` VÉGLEGES: a korlátlan újrapróbálás a sort sosem ürítené ki, és a
            // fogadó felé is agresszió lenne.
            'status' => $this->retry->shouldGiveUp($attempt) ? 'failed' : 'deferred',
            'reason' => 'DRIVER_ERROR',
            'next_attempt_at' => $this->retry->shouldGiveUp($attempt)
                ? null
                : now()->addSeconds($this->retry->delaySeconds($attempt)),
        ])->save();

        $this->events($message, 'deferred', $rendered, ['attempt' => $message->attempts]);
    }

    /**
     * A címzettenkénti esemény-írás.
     *
     * A naplóba SOHA nem kerül kirenderelt levéltörzs — csak a LENYOMATA. Egy 90 napig élő
     * eseménytáblában a törzs jelszó-visszaállító linket és magic-link tokent hordozna, és
     * ugyanezt a payloadot küldi ki az A3 webhook harmadik félnek is. A reprodukcióhoz a
     * lenyomat + a kanonikus törzs (a `messages` soron) elég.
     *
     * @param  array<string, array{html: ?string, text: ?string}>  $rendered
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $recipients  csak ezekre — `null` esetén mindenkire
     */
    private function events(Message $message, string $type, array $rendered, array $payload = [], ?array $recipients = null): void
    {
        $shared = collect($message->variables)->except('recipient-variables')->all();

        // MINDEN címzett kap eseményt: egyetlen sor batch esetén 999 címzettről nem tudna
        // semmit az A3 trace és a kézbesítési statisztika.
        foreach ($recipients ?? $message->recipients as $recipient) {
            $body = $rendered[(string) $recipient]['html'] ?? null;

            MessageEvent::create([
                'message_uuid' => $message->uuid,
                'type' => $type,
                'recipient' => $recipient,
                'channel' => $message->channel,
                'reason' => $message->reason,
                'tags' => $message->tags,
                // A `v:*` változók VISSZAJÖNNEK az eseményben és a webhookban (Mailgun-paritás):
                // az integrációk ebből azonosítják a saját rekordjaikat.
                'variables' => $shared,
                'payload' => [
                    ...$payload,
                    // Tartalom helyett LENYOMAT: bizonyítja, hogy melyik törzs ment ki,
                    // de nem őrzi meg a titkokat.
                    'body_sha256' => $body === null ? null : hash('sha256', $body),
                ],
            ]);
        }
    }

    /**
     * A címzettre személyre szabott törzsek — a KÜLDÉSI úton, nem a naplóban.
     *
     * Determinisztikusan újrarenderelhető a kanonikus törzsből, ezért nem kell perzisztálni.
     * A SZÖVEGES rész is renderelődik: enélkül a text/plain változatban a `%recipient.x%`
     * NYERSEN ment volna ki a címzettnek.
     *
     * @return array<string, array{html: ?string, text: ?string}>
     */
    private function renderAll(Message $message): array
    {
        $html = (string) ($message->options['body_html'] ?? '');
        $text = (string) ($message->options['body_text'] ?? '');
        $recipientVariables = (array) ($message->variables['recipient-variables'] ?? []);
        $shared = collect($message->variables)->except('recipient-variables')->all();

        // A nyomkövetés a TENANT SAJÁT hosztján fut (`email.<domain>`), nem a miénken —
        // különben minden ügyfél link-reputációja egy közös hosztra folyna össze, és a
        // klikk-átirányító a saját session-originünkön ülne. Ha nincs verifikált
        // nyomkövetési domain, nincs nyomkövetés.
        $trackingBase = $this->trackingBase->for($this->domainOf($message));

        $rendered = [];

        foreach ($message->recipients as $recipient) {
            $recipient = (string) $recipient;

            $recipientHtml = $html === ''
                ? null
                : $this->renderer->renderForRecipient($html, $recipient, $recipientVariables, $shared);

            // A NYOMKÖVETÉS a személyre szabás UTÁN és az ALÁÍRÁS ELŐTT kerül be: a pixel és
            // a becsomagolt link címzettenként más (különben nem lehetne megmondani, KI
            // nyitotta meg), a DKIM viszont a VÉGLEGES törzsre készül — fordított sorrendben
            // minden aláírásunk érvénytelen lenne.
            if ($recipientHtml !== null) {
                $recipientHtml = $this->tracking->rewrite(
                    $recipientHtml,
                    (int) $message->workspace_id,
                    (string) $message->uuid,
                    $recipient,
                    $trackingBase,
                    opens: $this->tracks($message, 'tracking_opens'),
                    clicks: $this->tracks($message, 'tracking_clicks'),
                );
            }

            $rendered[$recipient] = [
                'html' => $recipientHtml,
                'text' => $text === '' ? null : $this->renderer->renderForRecipient($text, $recipient, $recipientVariables, $shared, escape: false),
                'mime' => null,
            ];
        }

        return $this->sign($message, $rendered);
    }

    /**
     * Követjük-e ezt a levelet?
     *
     * Az `o:tracking` a fő kapcsoló, az `o:tracking-opens` / `-clicks` finomít rajta
     * (Mailgun-paritás). A `htmlonly` a mi esetünkben ugyanaz, mint az `igen`: a
     * nyomkövetést amúgy is csak a HTML törzsbe írjuk — a sima szöveges változatba
     * pixelt tenni nem lehet, linket becsomagolni pedig ott látható csúfítás lenne.
     *
     * Az ALAPÉRTELMEZÉS CSATORNÁNKÉNT MÁS, és ez tudatos eltérés a Mailgun-paritástól:
     *
     *  - `marketing`: BE. Ott a nyitási/kattintási arány maga a termék, és a címzett
     *    kifejezett, dupla opt-inos hozzájárulásával került a listára.
     *  - `transactional`: KI. Egy jelszó-visszaállító vagy számla-levél olvasásának ténye
     *    nem üzleti metrika, hanem a címzett magánügye — és a hozzájárulása ehhez nincs meg.
     *    Aki mégis akarja, egyetlen `o:tracking=yes`-szel bekapcsolja.
     *
     * A hívó mindkét irányban felülírhat (`o:tracking`, `o:tracking-opens`, `-clicks`).
     */
    private function tracks(Message $message, string $key): bool
    {
        $default = $message->channel !== 'transactional';

        $master = $message->options['tracking'] ?? $default;

        if ($master === false) {
            return false;
        }

        return ($message->options[$key] ?? $master) !== false;
    }

    /**
     * DKIM-aláírás a küldés előtt (21. kritérium).
     *
     * A levél a MI nevünkben megy ki: aláírás nélkül a fogadó nem tudja megkülönböztetni a
     * hamisítványtól, és a DMARC-politika elutasítja. A kulcsot a `signingSelector()` (M0-4)
     * választja — rotáció alatt szándékosan a RÉGIT, mert az újnak a DNS-rekordja még nem
     * propagált.
     *
     * @param  array<string, array{html: ?string, text: ?string, mime: ?string}>  $rendered
     * @return array<string, array{html: ?string, text: ?string, mime: ?string}>
     */
    private function sign(Message $message, array $rendered): array
    {
        $domain = $this->domainOf($message);

        if ($domain === null || $domain->signingSelector() === null) {
            // Aszimmetria elkerülve: a nyers ág korábban KIVÉTELT dobott, a strukturált
            // némán aláíratlanul küldött. Egységesen: a levél kimegy (a domain hitelesített,
            // a kézbesítés fontosabb), de HANGOSAN naplózzuk — a DKIM hiánya
            // kézbesíthetőségi hiba, amit az A6 suite jelez majd a felületen is.
            if ($domain !== null) {
                $this->recordUnsigned($message, $domain->name, 'no_signing_key');
            }

            return $rendered;
        }

        // NYERS MIME: a levelet a feladó állította össze, azt NEM építjük újra (bármilyen
        // újraszerkesztés megváltoztatná a tartalmát). Az aláírás a nyers bájtokra megy.
        // Enélkül az SMTP-n beadott levél ALÁÍRÁS NÉLKÜL ment volna ki — pont azon az úton,
        // amiről ez a packet szól.
        if (($message->options['raw_mime'] ?? false) === true) {
            $raw = RawMimeMessage::query()->where('message_uuid', $message->uuid)->first();

            if ($raw !== null) {
                try {
                    $signed = $this->rawDkim->sign($raw->raw, $domain);
                } catch (Throwable $e) {
                    // Ugyanaz a szabály, mint a strukturált ágon: nem állítjuk meg a
                    // küldést, de NYOMOT hagyunk.
                    $this->recordUnsigned($message, $domain->name, 'signing_failed', $e->getMessage());

                    return $rendered;
                }

                foreach ($rendered as $recipient => $bodies) {
                    $rendered[$recipient]['mime'] = $signed;
                }
            }

            return $rendered;
        }

        foreach ($rendered as $recipient => $bodies) {
            // CSAK az adott címzett törzsét adjuk át. A teljes tömb átadása minden
            // iterációban másolatot csinált egy egyre nagyobb tömbről (ami már tartalmazta
            // a korábban aláírt MIME-okat is) — ezer címzettnél gigabájtos, fölösleges
            // memóriamozgatás küldésenként.
            $rendered[$recipient]['mime'] = $this->dkim->sign(
                $this->mime->build($message->withRenderedBodies([$recipient => $bodies]), (string) $recipient),
                $domain
            );
        }

        return $rendered;
    }

    /**
     * Aláíratlanul kiment levél — NYOMMAL.
     *
     * A néma `Log::warning` kevés: az aláíratlan levél DMARC-bukást és IP-pool
     * reputációromlást okoz, tehát az üzenet-trace-ben (A3) és a kézbesíthetőségi
     * suite-ban (A6) is látszania kell. A nyom az ÜZENETEN van, nem külön eseményben:
     * új esemény-TÍPUST nem vezetünk be (az esemény-lánc a kézbesítés állapotait írja le,
     * és a webhookok arra épülnek), a `dkim_unsigned` viszont lekérdezhető és riasztható.
     */
    private function recordUnsigned(Message $message, string $domain, string $reason, ?string $detail = null): void
    {
        Log::warning('Aláíratlan levél ment ki', [
            'domain' => $domain,
            'message_uuid' => $message->uuid,
            'reason' => $reason,
            'error' => $detail,
        ]);

        $message->forceFill([
            'options' => [...$message->options, 'dkim_unsigned' => $reason],
        ])->save();
    }

    /** A feladó domainje — a DKIM-kulcs gazdája. */
    private function domainOf(Message $message): ?Domain
    {
        $name = DomainNormalizer::ofEmail((string) $message->from_email);

        return $name === '' ? null : Domain::query()->where('name', $name)->first();
    }

    private function decisionStale(Message $message): bool
    {
        $maxAge = (int) config('sending.decision_max_age_seconds');

        return $message->gate_decided_at !== null
            && $message->gate_decided_at->lt(now()->subSeconds($maxAge));
    }

    private function leaseExpired(Message $message): bool
    {
        return $message->sending_started_at === null
            || $message->sending_started_at->lte(now()->subSeconds($this->leaseSeconds()));
    }

    private function leaseSeconds(): int
    {
        return (int) config('sending.lease_seconds');
    }
}
