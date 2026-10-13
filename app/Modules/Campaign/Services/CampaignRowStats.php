<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Analytics\Services\Rates;
use App\Modules\Campaign\Jobs\RefreshCampaignStats;
use App\Modules\Campaign\Models\Campaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * KAMPÁNY-SOR statisztika (v1.1 · 5. modul, D284-ben kiemelve) — a kampánylista ÉS az áttekintés
 * „Legutóbbi kampányok" táblája ugyanezt használja: a két képernyő nem mondhat mást ugyanarról
 * a kampányról.
 *
 * - A FELÜLETI állapot a hat gépi állapot + a SZÁRMAZTATOTT `failed` (lásd `displayStatus()`).
 * - Az arányok a `RefreshCampaignStats` gyorsítótárából jönnek; a kérésben SOSEM számolunk
 *   (review RS6): a hiányzó érték háttérben töltődik, a sor addig „—"-t mutat.
 */
final class CampaignRowStats
{
    /**
     * A `failed` nem állapotgép-állapot (expand-only): az a lezárt kampány, aminek volt
     * címzettje, de EGYETLEN levele sem ment ki — jellemzően mert a PreSendGate mindet
     * elutasította (seed-mód, consent, domain). A „Kiküldve" jelvény erre hazugság lenne.
     */
    public function displayStatus(Campaign $campaign): string
    {
        return $campaign->status === 'sent' && (int) $campaign->recipient_count > 0 && (int) $campaign->sent_count === 0
            ? 'failed'
            : (string) $campaign->status;
    }

    /**
     * Egyedi kézbesített / nyitó / kattintó címzettek kampányonként — a gyorsítótárból.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<string, array{delivered: int, opened: int, clicked: int}>
     */
    public function engagement(Collection $campaigns): array
    {
        $stats = [];

        foreach ($campaigns as $campaign) {
            // A KÜLDÉS NÉLKÜLI kampánynak nincs mit számolni.
            if (! in_array((string) $campaign->status, ['sending', 'paused', 'sent', 'canceled'], true)) {
                continue;
            }

            $cached = Cache::get(RefreshCampaignStats::cacheKey((string) $campaign->uuid));

            if (is_array($cached)) {
                $stats[(string) $campaign->uuid] = $cached;
            } else {
                RefreshCampaignStats::dispatch((string) $campaign->uuid, (int) $campaign->workspace_id);
            }
        }

        return $stats;
    }

    /**
     * A RÁTÁK nevezője a KÉZBESÍTETT (Rates docblock): a nem kézbesített levél nem nyitható meg.
     *
     * @param  array{delivered: int, opened: int, clicked: int}|null  $engagement
     * @return array{openRate: float|null, clickRate: float|null}
     */
    public function rates(?array $engagement): array
    {
        $delivered = (int) ($engagement['delivered'] ?? 0);

        return [
            'openRate' => $delivered > 0 ? Rates::percent((int) $engagement['opened'], $delivered) : null,
            'clickRate' => $delivered > 0 ? Rates::percent((int) $engagement['clicked'], $delivered) : null,
        ];
    }
}
