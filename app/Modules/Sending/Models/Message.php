<?php

declare(strict_types=1);

namespace App\Modules\Sending\Models;

use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
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

    protected $attributes = ['status' => 'queued', 'testmode' => false];

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
