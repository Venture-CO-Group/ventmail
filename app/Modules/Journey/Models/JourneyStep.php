<?php

declare(strict_types=1);

namespace App\Modules\Journey\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Journey\Enums\StepType;
use App\Modules\Journey\Services\JourneyValidator;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Egy journey-lépés.
 *
 * A lépések LÁNCOLT LISTA, nem tömb-index: egy beszúrás a közepén különben minden FUTÓ
 * példány pozícióját elcsúsztatná — a `journey_runs` a LÉPÉSRE hivatkozik, nem a sorszámára.
 * Ez nem elméleti: egy hetekig futó journey-t a marketinges menet közben szerkeszt, és a
 * félúton lévő kontaktoknak nem szabad hirtelen egy másik levelet kapniuk.
 */
final class JourneyStep extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'journey_id', 'type', 'config', 'next_step_id', 'else_step_id', 'position',
        'journey_version_id', 'node_key', 'branches',
    ];

    protected $attributes = ['config' => '{}', 'position' => 0];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['config' => 'array', 'position' => 'integer', 'branches' => 'array', 'executed_count' => 'integer'];
    }

    protected static function booted(): void
    {
        /*
         | A KONFIGURÁCIÓ MENTÉSKOR ellenőrződik.
         |
         | Futáskor már késő: a típusos `uuid` oszlopra egy `'vip'` értékkel lekérdezni
         | `SQLSTATE[22P02]`-t ad, a kivétel a tranzakción belül visszagördíti a claimet és
         | a lépés-számlálót, és a futás ÖRÖKKÉ esedékes marad — futásonként napi 1440 hiba,
         | csendben. A motor ezt ma parkoltatja, de az a második védelmi vonal: egy hibás
         | konfigurációt nem menteni kell, hanem el nem fogadni.
         */
        self::saving(function (self $step): void {
            app(JourneyValidator::class)->validateStep($step);
        });

        // A LÁNC a mentés UTÁN vizsgálódik: a `next_step_id` a mentés pillanatában
        // hivatkozhat egy most létrejövő lépésre, tehát előbb létezni kell.
        self::saved(function (self $step): void {
            /*
             | A v1.1 VÁSZON lépése (D5) nem itt validálódik: a publikálás a TELJES gráfot
             | ellenőrzi (`JourneyGraphValidator`: árva csomópont, várakozás nélküli kör, kötelező
             | konfiguráció) a materializálás ELŐTT. A lépésenkénti újrabejárás egy 50 csomópontos
             | verziónál 100 mentésből 100 teljes bejárás lenne.
             */
            if ($step->isGraphStep()) {
                return;
            }

            $journey = $step->journey;

            if ($journey !== null) {
                app(JourneyValidator::class)->validateChain($journey);
            }
        });
    }

    /** A v1.1 vászon verziójához tartozik-e (D5) — a régi láncolt lépésnél `false`. */
    public function isGraphStep(): bool
    {
        return $this->journey_version_id !== null;
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(JourneyVersion::class, 'journey_version_id');
    }

    public function stepType(): StepType
    {
        return StepType::from((string) $this->type);
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function next(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_step_id');
    }

    public function otherwise(): BelongsTo
    {
        return $this->belongsTo(self::class, 'else_step_id');
    }

    /**
     * A LÉPÉS VÉGREHAJTÁSAI.
     *
     * A B4 riportja ezt kérdezi: „hányszor futott le ez a lépés?" — ami NEM ugyanaz, mint
     * „hány levél ment ki". A kapu-elutasítás is végrehajtás, csak `skipped` kimenettel, és
     * egy ciklikus journey-ben ugyanaz a lépés több `iteration`-nal is szerepel.
     */
    public function runSteps(): HasMany
    {
        return $this->hasMany(JourneyRunStep::class, 'journey_step_id');
    }
}
