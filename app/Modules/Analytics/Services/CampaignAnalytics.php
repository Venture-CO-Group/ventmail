<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;

/**
 * KAMPÁNY-RIPORT (72., 73. kritérium).
 *
 * Minden szám a `message_events`-ből jön, a kampány TAGJÉN keresztül
 * (`campaign:<uuid>`) — abból a táblából, amit a platform-napló is olvas. A kampány saját
 * számlálói (`sent_count`, `failed_count`) SZÁNDÉKOSAN nem forrás: azok a küldési folyamat
 * állapotát írják le, nem a fogadó visszajelzését.
 *
 * A tag-alapú kötés másik haszna: a riport akkor is működik, ha a `messages` sorok a
 * napló-retencióval már törlődtek. Az aggregált esemény-szám megmarad (golden rule 10), és
 * a kampány-riportnak nem szabad visszamenőleg kiürülnie.
 */
final class CampaignAnalytics
{
    public function __construct(private readonly EventCounters $counters) {}

    /** A kampány TAGJE — a küldés ezt írja minden üzenetre (`SendCampaignChunk`). */
    public static function tag(Campaign $campaign): string
    {
        return 'campaign:'.$campaign->uuid;
    }

    /**
     * @return array<string, int|float|string>
     */
    public function summary(Campaign $campaign): array
    {
        $counts = $this->counters->forTags([self::tag($campaign)]);

        return array_merge(
            [
                'campaign' => (string) $campaign->name,
                'campaign_uuid' => (string) $campaign->uuid,
                'status' => (string) $campaign->status,
                // A MATERIALIZÁLT címzett-szám a kampányból jön, mert az NEM esemény: azt a
                // célzás adja meg, és a riportnak meg kell tudnia mondani, hányan voltak a
                // halmazban — akkor is, ha egyetlen levél sem ment ki.
                'recipients' => (int) $campaign->recipient_count,
            ],
            $counts,
            $this->counters->rates($counts),
        );
    }

    /**
     * A/B VARIÁNS-BONTÁS — ugyanabból a forrásból.
     *
     * A `variant:<label>` tag a kampány-tag MELLETT szerepel az eseményen, tehát a két tag
     * ÉS-kapcsolatban szűr. Így a variánsok összege a kampány-összesítőt adja: ha külön
     * számlálókból jönnének, a két nézet mást mondana ugyanarról a küldésről.
     *
     * @return list<array<string, int|float|string>>
     */
    public function byVariant(Campaign $campaign): array
    {
        $rows = [];

        foreach ($campaign->variants()->orderBy('id')->get() as $variant) {
            $counts = $this->counters->forTags([
                self::tag($campaign),
                'variant:'.$variant->label,
            ]);

            $rows[] = array_merge(
                [
                    'label' => (string) $variant->label,
                    'subject' => $variant->subject,
                    'weight' => (int) $variant->weight,
                    'is_winner' => (bool) $variant->is_winner,
                ],
                $counts,
                $this->counters->rates($counts),
            );
        }

        return $rows;
    }

    /**
     * NAPI IDŐSOR a riport grafikonjához.
     *
     * @return list<array{day: string, delivered: int, unique_opened: int, unique_clicked: int}>
     */
    public function daily(Campaign $campaign): array
    {
        return $this->counters->dailyForTags([self::tag($campaign)]);
    }

    /**
     * A GYŐZTES variáns — ha ki van hirdetve.
     *
     * A kihirdetés a `VariantWinner` dolga (B2), nem a riporté: egy riport-megnyitás nem
     * dönthet el egy A/B tesztet.
     */
    public function winner(Campaign $campaign): ?CampaignVariant
    {
        return $campaign->variants()->where('is_winner', true)->first();
    }
}
