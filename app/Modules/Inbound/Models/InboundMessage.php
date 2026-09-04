<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Egy BEÉRKEZETT levél.
 *
 * A nyers MIME nem itt van, hanem a fájltárban (`storage_key`) — egy 25 MB-os `bytea` a
 * naplózó lekérdezéseket is megfojtaná, és a retenciós törlés táblányi holt helyet hagyna.
 *
 * A retenció RÖVID (spec: alap 3 nap): a bejövő levél idegen tartalom, amit nem mi írtunk és
 * nem a mi jogalapunkon tárolunk. A `store()` akció hosszabbíthat rajta, de csak plafonig.
 */
final class InboundMessage extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'domain_id', 'sender', 'recipient', 'subject', 'message_id',
        'storage_key', 'size', 'sha256', 'status', 'reason',
        'body_plain', 'body_html', 'stripped_text', 'headers',
        'spam_score', 'spf_result', 'dkim_result', 'dmarc_result',
        'received_at', 'parsed_at', 'expires_at',
    ];

    protected $attributes = ['status' => 'received', 'headers' => '{}', 'size' => 0];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'spam_score' => 'float',
            'received_at' => 'datetime',
            'parsed_at' => 'datetime',
            'expires_at' => 'datetime',
            'redacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $message): void {
            $message->received_at ??= now();
            // IDEGEN TARTALOM: a retenció itt nem opció, hanem alapértelmezés.
            $message->expires_at ??= now()->addDays((int) config('inbound.retention_days'));
        });
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InboundAttachment::class);
    }
}
