<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Egy kampány (62–67. kritérium).
 *
 * ## AZ IDŐZÓNA A TENANTÉ (65. kritérium)
 *
 * A `scheduled_at` UTC-ben tárolódik, a `timezone` viszont a MEGJELENÍTÉSHEZ és az
 * ÚJRASZÁMÍTÁSHOZ kell. „Kedd 9:00" egy budapesti ügyfélnek nem ugyanaz az UTC-pillanat
 * nyáron és télen — ha csak az UTC-t tároljuk, a nyári időszámítás váltása után a kampány
 * egy órával elcsúszik, és a hibát senki nem tudja megmagyarázni.
 */
final class Campaign extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = [
        'template_id', 'name', 'subject', 'preheader', 'from_email', 'from_name',
        'scheduled_at', 'timezone',
        'target_lists', 'target_segments', 'exclude_lists', 'exclude_segments',
        // AZ A/B AUTOMATA KIHIRDETÉS beállításai (64. kritérium). A `winner_declared_at`
        // szándékosan NEM `fillable`: azt a kihirdető parancs írja, nem a hívó.
        'winner_criterion', 'winner_after_minutes',
    ];

    protected $attributes = [
        'status' => 'draft',
        'timezone' => 'UTC',
        'target_lists' => '[]',
        'target_segments' => '[]',
        'exclude_lists' => '[]',
        'exclude_segments' => '[]',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'winner_declared_at' => 'datetime',
            'target_lists' => 'array',
            'target_segments' => 'array',
            'exclude_lists' => 'array',
            'exclude_segments' => 'array',
            'scheduled_at' => 'datetime',
            'estimated_at' => 'datetime',
            'materialized_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $campaign): void {
            // A CÉLZÁS és a SABLON csak szerkeszthető állapotban változhat.
            //
            // Küldés közben a célzás átírása azt jelentené, hogy a kampány egyik fele az
            // egyik közönségnek ment, a másik fele a másiknak — és a riport egyikről sem
            // mondana igazat.
            if (! $campaign->isDirty('status') && ! $campaign->statusEnum()->isEditable()) {
                $locked = array_intersect(
                    array_keys($campaign->getDirty()),
                    ['template_id', 'target_lists', 'target_segments', 'exclude_lists', 'exclude_segments', 'subject', 'from_email']
                );

                if ($locked !== []) {
                    throw new RuntimeException(sprintf(
                        'A(z) %s állapotú kampány már nem szerkeszthető (%s).',
                        $campaign->status,
                        implode(', ', $locked)
                    ));
                }
            }
        });
    }

    public function statusEnum(): CampaignStatus
    {
        return CampaignStatus::from((string) $this->status);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * Az ütemezett időpont a TENANT IDŐZÓNÁJÁBAN — a felület ezt mutatja.
     */
    public function scheduledLocal(): ?Carbon
    {
        return $this->scheduled_at?->copy()->setTimezone((string) $this->timezone);
    }

    /**
     * ESEDÉKES-e a küldés?
     *
     * A `<=` szándékos: az ütemező percenként fut, tehát a pontos másodperc sosem
     * találkozik. Aki `<`-t ír, egy kampányt egy percig „még nem esedékesnek" látna.
     */
    public function isDue(?Carbon $now = null): bool
    {
        return $this->statusEnum() === CampaignStatus::Scheduled
            && $this->scheduled_at !== null
            && $this->scheduled_at->lessThanOrEqualTo($now ?? now());
    }

    /** Van-e A/B teszt? */
    public function hasVariants(): bool
    {
        return $this->variants()->count() > 1;
    }
}
