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
        $prefix = $this->unsubscribeUrlPrefix();
        $ours = false;

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'list-unsubscribe') {
                continue;
            }

            $ours = str_starts_with($prefix, 'http')
                && is_string($value)
                && str_contains($value, $prefix);

            if ($ours) {
                unset($headers[$name]);
            }

            break;
        }

        return $ours ? array_merge($headers, $this->unsubscribePair($recipient)) : $headers;
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

            $headers = array_change_key_case((array) $message->headers, CASE_LOWER);

            // Ha a hívó SAJÁT leiratkozó fejlécet adott (pl. egy migrált rendszer a saját
            // preference centerére mutat), azt tiszteletben tartjuk.
            if (($headers['list-unsubscribe'] ?? '') !== '') {
                return;
            }

            // A TÁROLT fejléc az első címzetté — ez a kapu bemenete, és ez látszik a
            // naplóban. A LEVÉLBE kerülő változatot a `OutboundMimeBuilder` írja újra
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
