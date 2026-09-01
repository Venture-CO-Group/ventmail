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

    /**
     * Érintetti törlési kérés teljesítése: a személyes adat eltűnik, az esemény TÉNYE
     * (típus, idő, workspace) megmarad — enélkül a kézbesítési statisztika és a
     * panasz-bizonyíték is elveszne.
     */
    public function redactPersonalData(): void
    {
        $this->forceFill([
            'recipient' => null,
            'variables' => [],
            'payload' => [],
            'redacted_at' => now(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'variables' => 'array',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'redacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $event): void {
            // Egyetlen engedélyezett módosítás: a GDPR-redakció (PII-mezők nullázása).
            // Minden más átírás tiltott — a naplónak hitelesnek kell maradnia.
            $allowed = ['recipient', 'variables', 'payload', 'redacted_at'];

            if (array_diff(array_keys($event->getDirty()), $allowed) !== [] || $event->redacted_at === null) {
                throw new \RuntimeException('A message_events sor csak redakcióval módosítható.');
            }
        });

        // A retenciós törlés chunk-szinten megy (drop_chunks, A3), nem modellen keresztül:
        // egy panasz-esemény soronkénti törlése bizonyítékot semmisítene meg.
        self::deleting(function (): never {
            throw new \RuntimeException('A message_events sor nem törölhető (retenció: drop_chunks).');
        });

        self::creating(function (self $event): void {
            $event->occurred_at ??= now();
        });
    }
}
