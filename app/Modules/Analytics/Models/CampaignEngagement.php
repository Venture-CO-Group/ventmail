<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy (hatókör, variáns, CÍMZETT) hármas marketing-tényei — a riport alapja.
 *
 * Miért nem a nyers `message_events`: azon 90 napos retenció van, és a review megmérte,
 * hogy `drop_chunks` után a Studio-riport nullát mutatott, miközben a platform-napló
 * (folytonos aggregátumból) még 50 kézbesítést és 40%-os nyitást — két felület, két szám
 * ugyanarra a kampányra. Ez a tábla a retenciót túlélő, EXAKT tényréteg.
 *
 * A `recipient_hash` pszeudonim: az egyediség számolható vele, a törlés pedig ÖSSZEVONJA a
 * sorokat, tehát a számok nem romlanak el egy Art. 17 kéréstől. A nyers úton ez sem volt
 * igaz: a redakció `NULL`-ra állítja a `recipient`-et, és a `count(distinct)` a `NULL`-okat
 * NULLÁNAK számolja — mérve öt egyedi nyitóból kettő lett három törlési kérés után.
 */
final class CampaignEngagement extends Model
{
    use BelongsToWorkspace;

    protected $table = 'campaign_engagement';

    protected $fillable = [
        'scope', 'variant_label', 'recipient_hash',
        'rejected', 'accepted', 'delivered', 'deferred', 'bounced', 'complained', 'unsubscribed',
        'opens', 'clicks', 'first_opened_at', 'first_clicked_at', 'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'first_opened_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }
}
