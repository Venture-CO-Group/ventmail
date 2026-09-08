<?php

declare(strict_types=1);

namespace App\Modules\Sending\Models;

use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\Sending\Enums\Channel;
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
     * A leiratkozó fejlécek (RFC 8058).
     *
     * KÉT fejléc kell EGYÜTT: a `List-Unsubscribe` megmondja, HOVA menjen a leiratkozás, a
     * `List-Unsubscribe-Post` pedig azt, hogy a levélkliens EGY KATTINTÁSSAL elvégezheti,
     * megerősítő oldal nélkül. Az utóbbi nélkül a fejléc csak egy link.
     *
     * A token az ELSŐ címzetthez szól; a személyre szabott változatot a küldés írja felül
     * címzettenként, mert a fejlécnek arról kell szólnia, aki a levelet kapja.
     *
     * @return array<string, string>
     */
    public function unsubscribeHeaders(): array
    {
        $recipient = (string) (((array) $this->recipients)[0] ?? '');

        $token = app(UnsubscribeTokens::class)->issue(
            (int) $this->workspace_id,
            (string) $this->uuid,
            $recipient,
        );

        $base = rtrim((string) (config('sending.tracking_base_url') ?: config('app.url')), '/');

        return array_merge((array) $this->headers, [
            'List-Unsubscribe' => '<'.$base.'/u/'.$token.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
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
            if ($message->channel !== Channel::Marketing->value) {
                return;
            }

            $headers = array_change_key_case((array) $message->headers, CASE_LOWER);

            // Ha a hívó SAJÁT leiratkozó fejlécet adott (pl. egy migrált rendszer a saját
            // preference centerére mutat), azt tiszteletben tartjuk.
            if (($headers['list-unsubscribe'] ?? '') !== '') {
                return;
            }

            $message->forceFill(['headers' => $message->unsubscribeHeaders()])->saveQuietly();
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
