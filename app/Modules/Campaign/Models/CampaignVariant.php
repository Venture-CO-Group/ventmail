<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy A/B variáns (64. kritérium).
 *
 * A RÁTÁK a variáns SAJÁT `sent_count`-jára vetítve számolnak, nem a kampány egészére: 60/40
 * forgalom-megosztásnál az össz-küldésre vetített arány a nagyobb variánst hozná ki
 * győztesnek akkor is, ha rosszabb.
 */
final class CampaignVariant extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = ['campaign_id', 'template_id', 'label', 'subject', 'weight', 'from_email', 'from_name'];

    protected $attributes = ['weight' => 50];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'sent_count' => 'integer',
            'opened_count' => 'integer',
            'clicked_count' => 'integer',
            'is_winner' => 'boolean',
            'declared_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** A NYITÁSI arány — a variáns saját küldésszámára vetítve. */
    public function openRate(): float
    {
        return $this->sent_count === 0 ? 0.0 : round($this->opened_count / $this->sent_count * 100, 2);
    }

    public function clickRate(): float
    {
        return $this->sent_count === 0 ? 0.0 : round($this->clicked_count / $this->sent_count * 100, 2);
    }
}
