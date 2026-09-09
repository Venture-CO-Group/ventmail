<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Models;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy MATERIALIZÁLT kampány-címzett (63., 64. kritérium).
 *
 * A halmaz azért tábla és nem lekérdezés, mert (a) egy megszakadt küldés folytatható és nem
 * küld kétszer, (b) a felületen mutatott becsült szám és a valóság egyezik, (c) az A/B
 * variánst a CÍMZETTHEZ kell kötni — egy újrapróbálás különben más variánst adna ugyanannak
 * az embernek, és a mérés értelmét veszti.
 */
final class CampaignRecipient extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'campaign_id', 'contact_id', 'campaign_variant_id', 'email',
        'status', 'skip_reason', 'message_uuid', 'sent_at',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function setEmailAttribute(?string $value): void
    {
        $normalized = DomainNormalizer::listKey(trim((string) $value));

        $this->attributes['email'] = $normalized;
        $this->attributes['email_hash'] = KeyedHash::of('email', $normalized);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(CampaignVariant::class, 'campaign_variant_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
