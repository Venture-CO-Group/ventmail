<?php

declare(strict_types=1);

namespace App\Modules\Journey\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Services\JourneyValidator;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Egy journey: trigger + lépéslánc (68–71. kritérium). */
final class Journey extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'trigger_type', 'trigger_config',
        'allow_reentry', 'reentry_cooldown_hours', 'goal_type', 'goal_config', 'first_step_id',
    ];

    protected $attributes = [
        'status' => 'draft',
        'trigger_config' => '{}',
        'goal_config' => '{}',
        'allow_reentry' => false,
        'reentry_cooldown_hours' => 720,
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'goal_config' => 'array',
            'allow_reentry' => 'boolean',
            'reentry_cooldown_hours' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         | A CÉL-KONFIGURÁCIÓ MENTÉSKOR ellenőrződik.
         |
         | A leggyakoribb éles hiba nem elgépelés volt, hanem a séma ÜRES `goal_config`-ja:
         | `goal_type = 'has_tag'` mellett a cél `''`-t adott a típusos `uuid` oszlopra, és
         | ezt a `shouldExit()` MINDEN lépés előtt, a journey MINDEN futására megkérdezte.
         | Egy elmentett journey így egyetlen lépést sem tett meg, csak hibát írt.
         */
        self::saving(function (self $journey): void {
            app(JourneyValidator::class)->validateGoal($journey);
        });
    }

    public function triggerType(): TriggerType
    {
        return TriggerType::from((string) $this->trigger_type);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(JourneyStep::class)->orderBy('position');
    }

    public function firstStep(): BelongsTo
    {
        return $this->belongsTo(JourneyStep::class, 'first_step_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(JourneyRun::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
