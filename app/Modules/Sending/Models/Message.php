<?php

declare(strict_types=1);

namespace App\Modules\Sending\Models;

use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Suppression\Services\UnsubscribeBaseUrl;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kimenő üzenet (spec 8., 25.).
 *
 * A `channel` KÖTELEZŐ és nem keverhető: a tranzakciós forgalmat marketing-leiratkozás
 * nem blokkolhatja, és a kampány sosem előzheti meg a sorban (golden rule 4).
 */
final class Message extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'domain_id', 'ip_pool_id', 'ip_address_id', 'channel', 'from_email', 'from_name', 'subject',
        'recipients', 'tags', 'variables', 'headers', 'options', 'deliverytime',
        'testmode', 'status', 'reason', 'idempotency_key', 'request_fingerprint',
        'queued_at', 'sent_at',
        'sending_started_at', 'attempts', 'next_attempt_at', 'gate_decided_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * A fejlécnevek KANONIZÁLÁSA — egy név, egy kulcs.
     *
     * A fejlécnév az RFC 5322 szerint kis-nagybetűre ÉRZÉKETLEN, a `headers` viszont `jsonb`:
     * ott két külön kulcs `LIST-UNSUBSCRIBE` és `List-Unsubscribe` néven simán elférne, és a
     * SORRENDJÜKET sem mi szabjuk meg, hanem a Postgres normalizálása (hossz, majd
     * bájtsorrend).
     *
     * Ez nem elméleti: az ügyfél a `h:LIST-UNSUBSCRIBE=` fejlécet ÜRES értékkel megadva a
     * `headersFor()` felismerését a saját, üres kulcsára futtatta, a MI kulcsunk pedig a
     * `break` miatt már nem került sorra — vagyis MINDEN levél az ELSŐ címzett tokenjét
     * vitte. Mérve, végponttól végpontig; és egy MÁSIK workspace elkapott tokenjét
     * beillesztve a kimenő levelek egy IDEGEN tenant leiratkozó tokenjét hordozták, a mi
     * DKIM-aláírásunkkal.
     *
     * Emellett két `List-Unsubscribe` fejléc a kimenő MIME-ban önmagában is one-click
     * megfelelési hiba: a fogadó nem tudja, melyiket hívja.
     *
     * Az UTOLSÓ érték nyer — a `h:` fejlécek sorrendje a kérésé, és az ügyfél szándéka a
     * legutolsó megadás. A név kanonikus alakja szegmensenként nagy kezdőbetűs
     * (`List-Unsubscribe`, `X-Kampany`), ahogy a MIME is írja.
     *
     * @param  array<string, mixed>|string|null  $value
     */
    public function setHeadersAttribute(mixed $value): void
    {
        if (! is_array($value)) {
            $this->attributes['headers'] = $value === null ? null : (string) $value;

            return;
        }

        $canonical = [];
        $seen = [];

        foreach ($value as $name => $headerValue) {
            $name = self::canonicalHeaderName((string) $name);
            $lower = strtolower($name);

            // Ugyanaz a név MÁS betűzéssel: a korábbi kulcsot ELDOBJUK, hogy egy név egyszer
            // létezzen. Az UTOLSÓ érték nyer — a `h:` fejlécek sorrendje a kérésé, és az
            // ügyfél szándéka a legutolsó megadás.
            if (isset($seen[$lower])) {
                unset($canonical[$seen[$lower]]);
            }

            $seen[$lower] = $name;
            $canonical[$name] = $headerValue;
        }

        $this->attributes['headers'] = (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A név kanonikus alakja — CSAK az általunk kezelt fejlécekre.
     *
     * A `List-Unsubscribe`, a `List-Unsubscribe-Post` és a `Reply-To` betűzését MI szabjuk
     * meg: ezeket mi írjuk, mi olvassuk, és a `headersFor()` ezek alá írja a címzettenkénti
     * változatot — a kulcsnak tehát pontosan egyeznie kell azzal, amit beírunk.
     *
     * Minden MÁS fejléc (`X-*`) betűzése az ÜGYFÉLÉ, és úgy marad. Az általános
     * újra-betűzés (szegmensenként nagy kezdőbetű) a saját `X-VentMail-Hops` fejlécünkből
     * `X-Ventmail-Hops`-ot csinált — a kimenő levélben látható formátumváltozás, amit senki
     * nem kért, és ami a saját fejléc-neveinket írta át.
     */
    private static function canonicalHeaderName(string $name): string
    {
        $name = trim($name);

        return match (strtolower($name)) {
            'list-unsubscribe' => 'List-Unsubscribe',
            'list-unsubscribe-post' => 'List-Unsubscribe-Post',
            'reply-to' => 'Reply-To',
            default => $name,
        };
    }

    /**
     * A leiratkozó fejlécek (RFC 8058) — EGY KONKRÉT címzettre.
     *
     * KÉT fejléc kell EGYÜTT: a `List-Unsubscribe` megmondja, HOVA menjen a leiratkozás, a
     * `List-Unsubscribe-Post` pedig azt, hogy a levélkliens EGY KATTINTÁSSAL elvégezheti,
     * megerősítő oldal nélkül. Az utóbbi nélkül a fejléc csak egy link.
     *
     * A CÍMZETT KÖTELEZŐ PARAMÉTER, és ez nem stílus kérdése. A token a leiratkozó SZEMÉLYÉT
     * hordozza; ha egy 3 címzettes batch mindhárom levele az ELSŐ címzett tokenjét vinné,
     * Béla leiratkozása ANNÁT tenné suppression-listára — Béla pedig továbbra is kapná a
     * levelet. Consent-sértés (golden rule 4) és rossz irányú GDPR-hatás, csendben.
     *
     * @return array<string, string>
     */
    public function unsubscribeHeaders(string $recipient): array
    {
        return array_merge((array) $this->headers, $this->unsubscribePair($recipient));
    }

    /**
     * A KIMENŐ fejlécek egy adott címzettre — ezt használja a MIME-építés és a Postal-hívás.
     *
     * A tárolt fejléc az ELSŐ címzetté (a kapu bemenete és a napló sora); a levélbe kerülő
     * változatot itt írjuk újra a TÉNYLEGES címzettre. A saját linkünket az előtagjáról
     * ismerjük fel: ha az ügyfél a saját preference centerére mutató fejlécet adott, ahhoz
     * nem nyúlunk — a token nélküli, idegen URL-t nem tudnánk és nem is akarnánk átírni.
     *
     * @return array<string, mixed>
     */
    public function headersFor(string $recipient): array
    {
        $headers = (array) $this->headers;

        // NEM ISMERÜNK FEL semmit: a consent-köteles csatornán a leiratkozó fejléc MINDIG a
        // miénk, és MINDIG a tényleges címzettre szól. A felismerés-alapú változat négyszer
        // nyílt ki egymás után (az első címzett tokenje mindenkinek → az URL-előtag
        // elcsúszása → más betűzésű kulcs → százalék-kódolt token), mert egy KÉPESSÉGI
        // tokent kerestünk regexszel egy kívülről írható szövegben. Ha az érték nem jöhet
        // kívülről, nincs mit felismerni.
        //
        // A tranzakciós csatornán nem írunk ilyen fejlécet: egy jelszó-visszaállítóról nem
        // lehet leiratkozni, és a fejléc ott félrevezető lenne.
        if (Channel::tryFrom((string) $this->channel)?->requiresConsent() !== true) {
            return $headers;
        }

        if (! str_starts_with($this->unsubscribeUrlPrefix(), 'http')) {
            return $headers;
        }

        // MINDEN létező változat eltűnik — betűzéstől függetlenül. Két `List-Unsubscribe`
        // fejléc a kimenő levélben önmagában is one-click megfelelési hiba: a fogadó nem
        // tudja, melyiket hívja.
        foreach (array_keys($headers) as $name) {
            if (str_starts_with(strtolower((string) $name), 'list-unsubscribe')) {
                unset($headers[$name]);
            }
        }

        return array_merge($headers, $this->unsubscribePair($recipient));
    }

    /**
     * Van a fejlécben IDEGEN ventMail-leiratkozó token?
     *
     * Ez MÉLYSÉGI VÉDELEM, nem az elsődleges zár. Az elsődleges zár az, hogy a leiratkozó
     * fejléc értéke nem jöhet kívülről (`MailgunPayload`, `SendMimeController`), a
     * `headersFor()` pedig felismerés nélkül, mindig felülírja a sajátjával.
     *
     * Ügyféltől megfejthető ventMail-token SOHA nem jöhet: ha jön, az vagy elkapott
     * (proxy-napló, továbbított levél), vagy egy másik tenanté. Mindkettő elutasítás.
     */
    public function carriesForeignUnsubscribeToken(): bool
    {
        // MINDEN fejlécet nézünk, nem csak a `List-Unsubscribe`-ot: egy idegen token egy
        // `X-*` fejlécben is kijutott a levélbe (mérve). One-click nincs rajta, tehát emberi
        // kattintás kell hozzá — de a szűrés nem lehet szűkebb, mint a felület, amin a
        // token kijuthat.
        foreach ((array) $this->headers as $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach ($this->unsubscribeClaimsIn($value) as $claim) {
                if ($claim['workspaceId'] !== (int) $this->workspace_id
                    || $claim['messageUuid'] !== (string) $this->uuid) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A fejlécben szereplő, MEGFEJTHETŐ leiratkozó tokenek igényei.
     *
     * @return list<array{workspaceId: int, messageUuid: string, recipient: string}>
     */
    private function unsubscribeClaimsIn(string $header): array
    {
        // A fejléc `<…>`-be zárt címek listája (RFC 2369); a token az útvonal `/u/` szegmense
        // után áll, és az ábécéje a route-szűrővel egyezik.
        //
        // DEKÓDOLVA is keresünk: a router a DEKÓDOLT útvonalon illeszt, tehát a
        // `/u/AAAA%42BBB` és a `/%75/AAAABBBB` is élő, hívható one-click URL — a nyers
        // szövegen futó minta viszont mindkettőt átengedte (mérve).
        $decoded = rawurldecode($header);

        preg_match_all('#/u/([A-Za-z0-9\-_~]+)#', $header."\n".$decoded, $matches);

        $tokens = app(UnsubscribeTokens::class);
        $claims = [];

        foreach ($matches[1] as $token) {
            $claim = $tokens->parse((string) $token);

            if ($claim !== null) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    /** @return array<string, string> */
    private function unsubscribePair(string $recipient): array
    {
        $token = app(UnsubscribeTokens::class)->issue(
            (int) $this->workspace_id,
            (string) $this->uuid,
            $recipient,
        );

        return [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrlPrefix().$token.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * A SAJÁT leiratkozó linkjeink előtagja — egyben az ismertetőjelük.
     *
     * A MIME-építés ebből tudja megmondani, hogy a tárolt fejléc a MIÉNK-e (tehát
     * címzettenként újra kell írni), vagy az ügyfél sajátja (tehát érintetlen marad).
     */
    public function unsubscribeUrlPrefix(): string
    {
        return app(UnsubscribeBaseUrl::class)->for($this->domain).'/u/';
    }

    protected $attributes = ['status' => 'queued', 'testmode' => false];

    protected static function booted(): void
    {
        // ONE-CLICK LEIRATKOZÁS (RFC 8058) — MINDEN marketing üzenetre, minden úton.
        //
        // A Gmail és a Yahoo 2024 februárja óta MEGKÖVETELI a tömeges küldőktől; a hiánya
        // nem „rosszabb kézbesítés", hanem elutasítás. A logika mögötte egyszerű: ha a
        // leiratkozás nehéz, az olvasó a spam-gombot nyomja meg helyette — és a panasz a
        // küldő reputációját viszi, nem a levélét.
        //
        // MODELL-HOOKBAN, nem a küldő actionben: négy út hoz létre üzenetet (REST, SMTP
        // relay, ütemező, kampány), és ha bármelyik elfelejtené, az `UnsubscribeHeaderGate`
        // (helyesen) elutasítaná a levelet — a hibát a mi mulasztásunkból az ügyfél hibájává
        // téve. A tokenhez az üzenet uuid-ja kell, ezért a `created` hookban áll elő.
        self::created(function (self $message): void {
            // MINDEN consent-köteles csatorna, nem csak a `marketing` sztring. A `Channel`
            // enumban NÉGY érték van, és a `campaign` meg a `journey` a Studio TELJES
            // tömegforgalma: nyers sztring-egyezéssel épp az a levél menne ki
            // `List-Unsubscribe` nélkül, amit a Gmail és a Yahoo 2024 óta elutasít.
            if (Channel::tryFrom((string) $message->channel)?->requiresConsent() !== true) {
                return;
            }

            // A FEJLÉC MINDIG A MIÉNK — nincs „ha a hívó sajátot adott" kivétel.
            //
            // Volt ilyen kivétel, és pontosan azon nyílt ki NÉGYSZER ugyanez a hiba: amíg az
            // érték kívülről jöhetett, nekünk FEL KELLETT ISMERNI, hogy a benne lévő token a
            // miénk-e. A `MailgunPayload` és a `SendMimeController` ezért már a BEMENETEN
            // elutasítja az ügyfél `List-Unsubscribe` fejlécét — itt tehát nincs mit
            // tiszteletben tartani, és a felülírás a mélységi védelem.
            //
            // A TÁROLT fejléc az első címzetté: ez a kapu bemenete, és ez látszik a
            // naplóban. A LEVÉLBE kerülő változatot a `headersFor()` írja újra
            // címzettenként; a tárolt token sosem megy ki több emberhez.
            // Nincs használható leiratkozó hoszt (`http://` vagy hiányzó konfiguráció): NEM
            // írunk be egy hibás linket. A tokent tartalmazó, mégis működésképtelen fejléc
            // rosszabb, mint a hiánya — a kapu így a valódi okot mondja meg.
            if (! str_starts_with($message->unsubscribeUrlPrefix(), 'http')) {
                return;
            }

            $first = (string) (((array) $message->recipients)[0] ?? '');

            $message->forceFill(['headers' => $message->unsubscribeHeaders($first)])->saveQuietly();
        });
    }

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'tags' => 'array',
            'variables' => 'array',
            'headers' => 'array',
            'options' => 'array',
            'deliverytime' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'gate_decided_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'testmode' => 'boolean',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** A KIVÁLASZTOTT küldő IP — az A3 trace kötelező eleme („melyik IP-ről ment?"). */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }

    /**
     * A címzettenként személyre szabott törzsek — SZÁNDÉKOSAN NEM perzisztált attribútum.
     *
     * A driver ezt használja a MIME összeállításához; az adatbázisba csak a kanonikus
     * törzs kerül, mert a személyre szabott változat titkot (magic-link, token) hordozhat.
     *
     * @var array<string, array{html: ?string, text: ?string}>
     */
    public array $renderedBodies = [];

    /** @param array<string, array{html: ?string, text: ?string}> $bodies */
    public function withRenderedBodies(array $bodies): self
    {
        $this->renderedBodies = $bodies;

        return $this;
    }

    public function isTransactional(): bool
    {
        return $this->channel === 'transactional';
    }

    public function isMarketing(): bool
    {
        return $this->channel === 'marketing';
    }
}
