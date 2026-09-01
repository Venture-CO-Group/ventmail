<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Esemény a Timescale hypertable-ben (spec 11., 13.).
 *
 * EZ AZ EGYETLEN esemény-forrás: a platform-napló (A3) és a marketing analitika (B4)
 * ugyanebből dolgozik — különben a két felület eltérő „delivered" számot mutatna.
 * Az események MEGVÁLTOZTATHATATLANOK: a napló nem írható át utólag.
 */
final class MessageEvent extends Model
{
    use BelongsToWorkspace, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'message_uuid', 'type', 'recipient', 'channel', 'reason',
        'tags', 'variables', 'payload', 'occurred_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'variables' => 'array',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \RuntimeException('A message_events sor nem módosítható (napló-integritás).');
        });

        self::creating(function (self $event): void {
            $event->occurred_at ??= now();
        });
    }
}
