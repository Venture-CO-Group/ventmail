<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy webhook-kézbesítés és a sorsa.
 *
 * A `dead_letter` NEM végállomás: az UI-ból újrajátszható, és a sikeres replay kivezeti a
 * sort. Enélkül a DLQ csak egy növekvő szemétdomb lenne, amit senki nem néz meg.
 */
final class WebhookDelivery extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'webhook_endpoint_id', 'event_type', 'message_uuid', 'payload',
        'attempt', 'status', 'response_code', 'response_excerpt',
        'next_attempt_at', 'delivered_at', 'dead_lettered_at', 'expires_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $delivery): void {
            // A payload címzett-címet és `v:*` változókat tartalmaz — PII, tehát lejár.
            $delivery->expires_at ??= now()->addDays((int) config('sending.delivery_attempt_retention_days'));
        });
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
