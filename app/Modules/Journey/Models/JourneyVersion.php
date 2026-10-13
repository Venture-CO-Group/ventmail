<?php

declare(strict_types=1);

namespace App\Modules\Journey\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * EGY PUBLIKÁLT JOURNEY-VERZIÓ — megváltoztathatatlan pillanatkép (D5).
 *
 * Az aktív journey módosítása ÚJ verziót hoz létre; a már bent lévő kontaktok a saját
 * verziójuk lépésein haladnak tovább (a futás `journey_version_id`-je és a lépései ehhez a
 * sorhoz kötöttek). Ha egy verzió utólag módosulhatna, egy félúton lévő kontakt hirtelen
 * egy másik levelet kaphatna — ezért a modell az írást elutasítja.
 */
final class JourneyVersion extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = ['journey_id', 'number', 'graph', 'graph_hash', 'entry_step_id', 'published_by', 'published_at'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['graph' => 'array', 'number' => 'integer', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $version): void {
            // A belépési lépés a materializáláskor, EGYSZER íródik be (a lépések a verzió
            // után jönnek létre). Minden más mező írása tilos.
            if (array_keys($version->getDirty()) !== ['entry_step_id'] || $version->getOriginal('entry_step_id') !== null) {
                throw new LogicException('A publikált journey-verzió megváltoztathatatlan.');
            }
        });
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(JourneyStep::class, 'journey_version_id');
    }
}
