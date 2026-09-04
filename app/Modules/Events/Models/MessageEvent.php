<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Webhooks\Services\WebhookDispatcher;
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
        // Minden rögzített esemény kimegy a feliratkozott webhook-végpontokra (A3).
        // MODELL-EVENTBEN, mert így nincs olyan út, ami elfelejtené: a küldési lánc, az
        // ütemező és a bounce-feldolgozó mind ugyanezen a modellen ír.
        // TÖRLÉSI KÉRÉS UTÁN NINCS ÚJ SZEMÉLYES ADAT — bármelyik íróról legyen szó.
        //
        // Öt kódút ír eseményt (küldés, ütemező, kézbesítési eredmény, kései bounce,
        // nyomkövetés). Ha az ellenőrzés a hívókban lenne, a következő új író biztosan
        // kimaradna belőle — és épp az a hívó írná vissza a törölt címet, amelyikre senki
        // nem gondolt. Itt egy helyen van, és nem megkerülhető.
        //
        // Az esemény MAGA megszületik: a kézbesítés TÉNYE nem az érintett személyes adata,
        // és a statisztikának egyeznie kell. Csak a SZEMÉLY tűnik el belőle.
        self::creating(function (self $event): void {
            if ($event->recipient !== null && ErasedRecipient::erased((string) $event->recipient)) {
                $event->recipient = null;
                $event->variables = [];
                $event->payload = collect((array) $event->payload)->only(['code', 'enhanced_code', 'classification'])->all();
                $event->redacted_at = now();
            }
        });

        self::created(function (self $event): void {
            app(WebhookDispatcher::class)->dispatch($event);
        });

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
