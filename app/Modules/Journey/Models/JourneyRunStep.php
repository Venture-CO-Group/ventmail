<?php

declare(strict_types=1);

namespace App\Modules\Journey\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EGY VÉGREHAJTOTT LÉPÉS — az idempotencia adatbázis-szintű őre (68. kritérium).
 *
 * A `(journey_run_id, journey_step_id, iteration)` EGYEDI. Két párhuzamos tick közül a
 * második beszúrása elhasal, tehát a hatás (levélküldés, címkézés) EGYSZER történik meg — és
 * ez nem az alkalmazás jóindulatán múlik: a beszúrás SIKERE dönti el, hogy a lépést mi
 * végezzük-e el.
 *
 * Az `iteration` azért kell, mert egy ciklikus journey (engedélyezett újrabelépéssel)
 * ugyanazt a lépést többször is végrehajthatja — de minden körben csak egyszer.
 */
final class JourneyRunStep extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'journey_run_id', 'journey_step_id', 'iteration', 'outcome', 'message_uuid', 'executed_at',
    ];

    protected $attributes = ['iteration' => 1];

    protected function casts(): array
    {
        return ['iteration' => 'integer', 'executed_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(JourneyRun::class, 'journey_run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(JourneyStep::class, 'journey_step_id');
    }
}
