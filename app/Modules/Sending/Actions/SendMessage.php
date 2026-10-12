<?php

declare(strict_types=1);

namespace App\Modules\Sending\Actions;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Services\Metering;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Data\SendQueue;
use App\Modules\Sending\Data\SendResult;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateDecision;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Gate\PreSendGate;
use App\Modules\Sending\Jobs\DeliverMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Sending\Services\MessageDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A küldés belépési pontja — a PreSendGate-en át.
 *
 * Ez az M0 globális DoD megvalósítása: hitelesített domainről a FakeDriveren küldött üzenet
 * átmegy a hat kapun és `delivered` lesz. A PUBLIKUS `/v1/messages` felület az A1 packet
 * gazdája; ez a belső action, amire az is rá fog épülni — a gate megkerülése nélkül.
 */
final class SendMessage
{
    public function __construct(
        private readonly PreSendGate $gate,
        private readonly MessageDispatcher $dispatcher,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): SendResult
    {
        // A bemenet ÉRVÉNYESSÉGE a sor létrehozása ELŐTT dől el: egy hibás kérés ne hozzon
        // létre perzisztens sort (korlátlan írás-erősítés lenne rossz kliensből), és a
        // hívó strukturált elutasítást kapjon, ne nyers adatbázis-hibát.
        $invalid = $this->rejectInvalidInput($attributes);

        if ($invalid !== null) {
            return $invalid;
        }

        $idempotencyKey = Arr::get($attributes, 'idempotency_key');

        // Idempotencia (golden rule 5): ugyanaz a kulcs ugyanazt a választ adja, nem küld újra.
        if ($idempotencyKey !== null) {
            $existing = Message::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $this->replay($existing, $attributes);
            }
        }

        $message = $this->createMessage($attributes, $idempotencyKey);

        // A DB döntötte el a versenyt, és MI VESZTETTÜNK: a győztes üzenet már átment a
        // kapukon és ki is ment. Itt TOVÁBBMENNI dupla kézbesítés lett volna — a győztes
        // sort visszaállítottuk `accepted`-re, a dispatcher lízingje pedig újra engedett.
        if ($message === null) {
            // Kulcs nélkül nem lehet unique-ütközés — ha mégis ide jutnánk, az más
            // kényszer sérülése, és a csendes „nem találtam" 500 lenne. Hangos hiba jobb.
            $winner = $idempotencyKey === null
                ? null
                : Message::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($winner === null) {
                throw new RuntimeException(
                    'Az üzenet létrehozása egyediségi kényszerbe ütközött, de a nyertes sor '
                    .'nem található — ez nem az idempotencia-kulcs ütközése.'
                );
            }

            return $this->replay($winner, $attributes);
        }

        // A NYERS MIME a levél MAGA — a gate és a dispatcher ELŐTT kell perzisztálnia.
        // Korábban a hívás UTÁN történt, a küldés viszont ugyanabban a hívásban, szinkron
        // fut: a driver olyan pillanatban kapta meg az üzenetet, amikor a törzs még nem
        // létezett. `firstOrCreate`, mert a sor megváltoztathatatlan: egy üzenethez EGY
        // nyers levél tartozik (unique kényszer is védi).
        if (is_string($raw = Arr::get($attributes, 'raw_mime')) && $raw !== '') {
            RawMimeMessage::query()->firstOrCreate(
                ['message_uuid' => $message->uuid],
                ['raw' => $raw]
            );
        }

        $decision = $this->gate->evaluate($message);

        if (! $decision->allowed) {
            $message->forceFill([
                'status' => 'rejected',
                'reason' => $decision->reason,
                // Az elutasított levél titkos render-változóira soha nem lesz szükség.
                'render_variables' => null,
                'options' => [...$message->options, 'gate_trace' => $decision->trace()],
            ])->save();

            $this->event($message, 'rejected', $decision->reason);

            return new SendResult($message, $decision);
        }

        // A gate döntése az üzenetre kerül: a `gate_decided_at` teszi lehetővé, hogy egy
        // ütemezett küldésnél (deliverytime) a dispatcher újraértékeltethesse a gyorsan
        // változó kapukat — a közben érkezett leiratkozás különben nem számítana.
        $message->forceFill([
            'status' => 'accepted',
            'gate_decided_at' => now(),
            // A gate MINDEN kapujának eredménye az üzenetre kerül: az A3 trace-nek nem
            // csak az elutasítás okára van szüksége, hanem arra is, hogy MIÉRT MENT KI.
            // Az audit-sor erre nem alkalmas: az tenant-független és nem lekérdezhető
            // üzenetenként.
            'options' => [...$message->options, 'gate_trace' => $decision->trace()],
        ])->save();
        $this->event($message, 'accepted');

        // Az ÜTEMEZETT üzenet nem megy ki azonnal: a `deliverytime` a Mailgun-paritás része
        // (o:deliverytime). A sorból az A2 ütemezője veszi majd ki — és mivel a gate-döntésnek
        // szavatossági ideje van, a küldés előtt újraértékelés kell.
        if ($message->deliverytime !== null && $message->deliverytime->isFuture()) {
            /*
             | AZ ÜTEMEZETT levél a KIKÜLDÉSKOR fizet, nem az elfogadáskor.
             |
             | A review megmérte: `deliverytime = +5 min` → `sends_counter` +1; utána a
             | domain deverifikálva → a dispatcher `DOMAIN_UNVERIFIED`-del ELUTASÍTOTTA a
             | levelet — és az inkrementum BENT MARADT. Vagyis a packet fő garanciája („a
             | kapu által elutasított küldés nem számlázódik") pont azon az úton volt hamis,
             | ahol a kapu-döntés VÁLTOZNI szokott: leiratkozás, hard bounce,
             | kvóta-kimerülés, deverifikáció — amiket a `DispatchScheduledCommand`
             | docblockja maga sorol fel.
             |
             | A mérés ezért a `MessageDispatcher`-ben, a tényleges kiküldésnél történik.
             */
            $message->forceFill(['status' => 'scheduled'])->save();

            return new SendResult($message->fresh(), $decision);
        }

        /*
         | A TESTMODE naplóz, de NEM küld (Mailgun-paritás: o:testmode) — és NEM IS FIZET.
         |
         | A mérés korábban a kapu után, a testmode-ellenőrzés ELŐTT volt: a review megmérte,
         | hogy `testmode => true` mellett a levél nem ment ki, a `sends_counter` mégis nőtt.
         | A Mailgun (a paritás-cél) a testmode-ot nem számlázza, és az SMTP-n ez nem
         | egyszeri állapot: egy tartósan tesztmódban álló hitelesítő MINDEN beadott levél
         | után fizetett volna.
         */
        if (! $message->testmode) {
            $this->deliver($message);
            $this->meter($message);
        }

        return new SendResult($message->fresh(), $decision);
    }

    /**
     * A FOGYÁS MÉRÉSE — a levél TÉNYLEGES kiküldésénél (C1 · 75. kritérium).
     *
     * ## A HÁROM feltétel, ami eddig eljut
     *
     * A levél átment a kapun (nincs suppression, van consent, van kvóta), NEM testmode, és
     * NEM ütemezett. Mindhárom kizárás mért hibából született:
     *
     * - a kapu ELŐTT mérve a tenant azért fizetne, amit a rendszer HELYESEN meg sem
     *   próbált elküldeni — épp a listahigiéniát büntetnénk;
     * - a testmode nem megy ki, tehát nem fogyás (Mailgun-paritás);
     * - az ütemezett levél kapu-döntése a kiküldésig MEGVÁLTOZHAT, és mérve az elutasítás
     *   után is számlázva maradt.
     *
     * CÍMZETTENKÉNT, nem hívásonként: a kvóta-kapu is így mér („különben 300 hívás × 1000
     * címzett »limiten belül« 300 000 levelet jelentene").
     *
     * ## A HIBA NEM BUKTATJA EL A KÜLDÉST
     *
     * A levél már átment a kapun; egy mérés-hiba nem tarthatja vissza. A hiányzó fogyás
     * bevétel-kiesés, a visszatartott tranzakciós levél viszont ügyfél-probléma — a kettő
     * nem egy súlycsoport. A napló a VISSZAPÓTLÁSHOZ elég adatot visz (workspace, üzenet,
     * mennyiség), nem csak a kivétel nevét.
     */
    public function meter(Message $message): void
    {
        try {
            app(Metering::class)->increment(
                UsageDimension::Sends,
                max(1, count((array) $message->recipients)),
            );
        } catch (Throwable $e) {
            Log::warning('A küldés fogyása nem került a meteringbe', [
                'workspace_id' => (int) $message->workspace_id,
                'message_uuid' => (string) $message->uuid,
                'recipients' => count((array) $message->recipients),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Kizárólag azokat a hibákat fogja, amelyeket NEM lehet perzisztált üzenettel
     * kifejezni (ismeretlen csatorna → a DB check-constraint amúgy is elutasítaná).
     * A többi ellenőrzés a kapuké — ott a döntés auditált és trace-elhető.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function rejectInvalidInput(array $attributes): ?SendResult
    {
        // NUL bájt és érvénytelen UTF-8: a `jsonb`/`text` oszlop ezeket ELUTASÍTJA
        // („unsupported Unicode escape sequence", „invalid byte sequence"), és mivel a sor
        // a kapuk ELŐTT íródik, a hívó `QueryException` → 500-at kapott az egységes
        // `error.code` alak helyett. A nyers MIME kivétel: az `bytea`-ba megy.
        $unstorable = $this->firstUnstorableField(Arr::except($attributes, ['raw_mime']));

        if ($unstorable !== null) {
            // A MEZŐNÉV maga is tartalmazhatja a hibás bájtot (`v:"ne\0v"`), és ez a szöveg
            // az auditba megy — ott ugyanaz a `jsonb` fogadná, tehát a hibaüzenet ölte
            // volna meg a kérést. Ezért a névből is kitakarítjuk, amit nem lehet tárolni.
            return $this->invalid(
                $attributes,
                sprintf('A(z) `%s` mező NUL bájtot vagy érvénytelen UTF-8 szekvenciát tartalmaz.', $this->printable($unstorable))
            );
        }

        $channel = (string) Arr::get($attributes, 'channel', Channel::Transactional->value);

        if (Channel::tryFrom($channel) !== null) {
            return null;
        }

        return $this->invalid(
            $attributes,
            'Ismeretlen csatorna; engedélyezett: '.implode(', ', Channel::values())
        );
    }

    /**
     * Perzisztálás ELŐTTI elutasítás: nincs sor, van strukturált válasz és audit.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invalid(array $attributes, string $detail): SendResult
    {
        $channel = (string) Arr::get($attributes, 'channel', Channel::Transactional->value);

        $message = new Message([
            'channel' => $channel,
            'from_email' => Arr::get($attributes, 'from_email'),
            'recipients' => Arr::wrap(Arr::get($attributes, 'to', [])),
        ]);

        $decision = GateDecision::rejected(GateReason::InvalidRequest->value, [
            'recipient_sanity' => GateResult::reject(GateReason::InvalidRequest, $detail),
        ]);

        $this->audit->log('sending.gate.rejected', null, after: [
            'reason' => $decision->reason,
            'channel' => $channel,
            'gates' => $decision->trace(),
        ]);

        return new SendResult($message, $decision);
    }

    /** Tárolható, olvasható alak: a vezérlőkarakterek és a hibás bájtok eltávolítva. */
    private function printable(string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $value);

        return mb_check_encoding($clean, 'UTF-8')
            ? $clean
            : (string) mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
    }

    /**
     * Az első olyan mező neve, amit az adatbázis nem tudna eltárolni — vagy `null`.
     *
     * @param  array<string, mixed>  $values
     */
    private function firstUnstorableField(array $values, string $path = ''): ?string
    {
        foreach ($values as $key => $value) {
            $name = $path === '' ? (string) $key : $path.'.'.$key;

            // A KULCS ugyanúgy a `jsonb`-be kerül: `v:"ne\0v"` vagy egy hibás UTF-8 kulcs
            // szintén 500-at adott (a szűrő korábban csak az értékeket nézte).
            if (is_string($key) && (str_contains($key, "\0") || ! mb_check_encoding($key, 'UTF-8'))) {
                return $name;
            }

            if (is_array($value)) {
                $nested = $this->firstUnstorableField($value, $name);

                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }

            if (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Kiküldés — soron vagy szinkron.
     *
     * A SOR a prioritás egyetlen érvényesítési helye: a `campaign` sosem előzheti meg a
     * `transactional`-t (golden rule 4). Szinkron úton ilyen prioritás nem létezik, ezért
     * élesben a sor a helyes mód; fejlesztésben és tesztben viszont a determinisztikus
     * lefutás többet ér, mint a sor gyakorlása.
     */
    private function deliver(Message $message): void
    {
        if (! config('sending.dispatch_via_queue')) {
            $this->dispatcher->dispatch($message);

            return;
        }

        DeliverMessage::dispatch((int) $message->workspace_id, (string) $message->uuid)
            ->onQueue(SendQueue::forChannel((string) $message->channel)->value);
    }

    /**
     * Ismételt (vagy versenyt vesztett) Idempotency-Key: ugyanaz a válasz, ÚJRAKÜLDÉS NÉLKÜL.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function replay(Message $existing, array $attributes): SendResult
    {
        // MÁS tartalom ugyanazzal a kulccsal: a hívó hibája, és a csendes eldobás
        // elveszítené a második levelet. Ezt a Stripe és a Mailgun is hibának veszi.
        if ($existing->request_fingerprint !== null
            && $existing->request_fingerprint !== $this->fingerprint($attributes)) {
            return new SendResult(
                $existing,
                GateDecision::rejected(GateReason::IdempotencyKeyReused->value, [])
            );
        }

        return new SendResult($existing, $this->replayDecision($existing));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Message|null `null`, ha a kulcsot egy PÁRHUZAMOS hívás nyerte meg
     */
    private function createMessage(array $attributes, ?string $idempotencyKey): ?Message
    {
        $payload = [
            'channel' => Arr::get($attributes, 'channel', Channel::Transactional->value),
            // A DOMAIN kanonikus (punycode) alakban tárolódik, a helyi rész változatlanul:
            // enélkül a `példa.hu` és a `xn--plda-bpa.hu` két külön feladónak látszana a
            // domain-kapunak, a suppressionnek és a statisztikának is.
            'from_email' => DomainNormalizer::email(trim((string) Arr::get($attributes, 'from_email'))),
            'from_name' => Arr::get($attributes, 'from_name'),
            'subject' => Arr::get($attributes, 'subject'),
            'recipients' => array_map(
                fn ($recipient) => is_string($recipient) ? DomainNormalizer::email(trim($recipient)) : $recipient,
                Arr::wrap(Arr::get($attributes, 'to', []))
            ),
            'tags' => Arr::get($attributes, 'tags', []),
            'variables' => Arr::get($attributes, 'variables', []),
            'render_variables' => Arr::get($attributes, 'render_variables') ?: null,
            'headers' => Arr::get($attributes, 'headers', []),
            // A törzs EGYETLEN kanonikus helyen él (`body_html` / `body_text`), nem
            // duplikálva: 100 KB-os levélnél a kettős tárolás 200 KB/sor lenne.
            //
            // A SZEMÉLYRE SZABOTT törzs SEHOL nem perzisztál: a küldéskor determinisztikusan
            // újrarenderelődik. Egy 90 napig élő naplóba írt, kirenderelt levél jelszó-
            // visszaállító linket és magic-link tokent hordozna — az credential leak.
            'options' => array_merge(
                Arr::get($attributes, 'options', []),
                array_filter([
                    'body_html' => Arr::get($attributes, 'rendered_html') ?? Arr::get($attributes, 'html'),
                    'body_text' => Arr::get($attributes, 'text'),
                ], fn ($value) => $value !== null)
            ),
            'deliverytime' => Arr::get($attributes, 'deliverytime'),
            'ip_pool_id' => Arr::get($attributes, 'ip_pool_id'),
            'testmode' => (bool) Arr::get($attributes, 'testmode', false),
            'idempotency_key' => $idempotencyKey,
            // Az idempotencia-kulcs ÖNMAGÁBAN nem elég: ugyanaz a kulcs MÁS tartalommal
            // korábban csendben eldobta a második levelet (pl. `order-123` a visszaigazolóra
            // ÉS a szállítási értesítőre). A lenyomat teszi lehetővé a 409-et.
            'request_fingerprint' => $this->fingerprint($attributes),
            'queued_at' => now(),
        ];

        try {
            // SAVEPOINT (beágyazott tranzakcióban): egy egyediségi ütközés Postgresen a
            // TELJES tranzakciót elrontja („current transaction is aborted"), tehát egy
            // tranzakcióba ágyazott hívónál a következő lekérdezés is elszállna — épp az,
            // amelyikkel a győztes üzenetet megkeressük.
            return DB::transaction(fn () => Message::create($payload));
        } catch (UniqueConstraintViolationException) {
            // Versenyhelyzet: két párhuzamos, azonos kulcsú hívás közül mindkettő „nem
            // találtam"-ot olvasott. A DB döntött — a vesztes NEM küld, csak visszatükrözi
            // a győztes válaszát (golden rule 5).
            return null;
        }
    }

    /**
     * A kérés kanonikus lenyomata. A kulcs- és a hálózati zaj (sorrend) nem befolyásolja,
     * a TARTALOM viszont igen.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fingerprint(array $attributes): string
    {
        $canonical = Arr::only($attributes, [
            'channel', 'from_email', 'from_name', 'subject', 'to', 'html', 'text',
            'tags', 'variables', 'render_variables', 'headers', 'deliverytime', 'testmode', 'rendered_html',
            // A MIME-úton a TARTALOM kizárólag itt van: `options` (vezérlők) és a nyers
            // levél lenyomata. Enélkül ugyanaz a kulcs KÉT különböző MIME-levéllel replayt
            // adott, és a második soha nem ment ki.
            'options', 'ip_pool_id', 'raw_sha256',
        ]);

        ksort($canonical);

        // Érvénytelen UTF-8 (latin-1 törzsű legacy integráció) esetén a `json_encode`
        // `false`-t adott, tehát MINDEN ilyen kérés lenyomata `hash('')` lett — két
        // különböző levél ütközött, a második csendben elveszett.
        // KULCSOLT lenyomat: a `t:variables` (pl. egy 6 jegyű OTP) a kérés része, és a
        // többi mező ismert — kulcs nélküli SHA-256-ból offline visszafejthető lenne.
        return KeyedHash::of('request_fingerprint', json_encode($canonical, JSON_INVALID_UTF8_SUBSTITUTE) ?: serialize($canonical));
    }

    /**
     * Esemény MINDEN címzettre — a naplóba tartalom helyett LENYOMAT kerül (a kirenderelt
     * törzs magic-linket és tokent hordozhatna egy 90 napig élő táblában).
     */
    private function event(Message $message, string $type, ?string $reason = null): void
    {
        $body = (string) ($message->options['body_html'] ?? '');
        $shared = collect($message->variables)->except('recipient-variables')->all();

        foreach ($message->recipients as $recipient) {
            MessageEvent::create([
                'message_uuid' => $message->uuid,
                'type' => $type,
                'recipient' => $recipient,
                'channel' => $message->channel,
                'reason' => $reason,
                'tags' => $message->tags,
                'variables' => $shared,
                'payload' => ['body_sha256' => $body === '' ? null : KeyedHash::of('body', $body)],
            ]);
        }
    }

    /** Ismételt Idempotency-Key: az eredeti döntést tükrözzük vissza, új gate-futás nélkül. */
    private function replayDecision(Message $message): GateDecision
    {
        return $message->status === 'rejected'
            ? GateDecision::rejected($message->reason, [])
            : GateDecision::allowed([]);
    }
}
