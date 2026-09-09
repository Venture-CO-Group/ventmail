<?php

declare(strict_types=1);

namespace App\Modules\Journey\Models;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * EGY KONTAKT EGY FUTÁSA egy journey-ben (68. kritérium).
 *
 * A futás ÁLLAPOTA itt van, nem egy folyamatban: egy journey napokig–hetekig tart, és a
 * worker újraindul, a deploy lecseréli a kódot, a szerver átbootol. A motor csak LÉPTETI —
 * minden tick megkérdezi, hol áll, mi a következő, esedékes-e.
 */
final class JourneyRun extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'journey_id', 'contact_id', 'current_step_id', 'email', 'status',
        'exit_reason', 'resume_at', 'context', 'entered_at',
    ];

    protected $attributes = ['status' => 'active', 'context' => '{}', 'steps_taken' => 0, 'failure_count' => 0];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resume_at' => 'datetime',
            'entered_at' => 'datetime',
            'finished_at' => 'datetime',
            'steps_taken' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->entered_at ??= now();
        });
    }

    public function setEmailAttribute(?string $value): void
    {
        $normalized = DomainNormalizer::listKey(trim((string) $value));

        $this->attributes['email'] = $normalized;
        $this->attributes['email_hash'] = KeyedHash::of('email', $normalized);
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(JourneyStep::class, 'current_step_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function executedSteps(): HasMany
    {
        return $this->hasMany(JourneyRunStep::class);
    }

    /**
     * ESEDÉKES futások — a motor ezt kérdezi minden tickben.
     *
     * A `resume_at IS NULL` azonnal esedékes: egy frissen belépett futás nem vár a
     * következő percre. A `<=` szándékos (az ütemező percenként fut, a pontos másodperc
     * sosem találkozik).
     */
    public function scopeDue(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= now();

        return $query
            ->whereIn('status', ['active', 'waiting'])
            ->where(fn (Builder $inner) => $inner
                ->whereNull('resume_at')
                ->orWhere('resume_at', '<=', $now));
    }

    public function isFinished(): bool
    {
        return in_array((string) $this->status, ['completed', 'exited', 'failed'], true);
    }
}
