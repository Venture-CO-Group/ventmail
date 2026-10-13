<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Jobs;

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A KAMPÁNYLISTA sor-statisztikája — háttérben, gyorsítótárba (v1.1 · 5. modul, review RS6).
 *
 * Az egyedi kézbesített / nyitó / kattintó címzettek száma a `campaign_engagement` ténytábla
 * kampányonkénti összevonása — egy 300k-s kampánynál 300k sor. A lista ezt SOHA nem számolja
 * a kérésben: a gyorsítótárat olvassa, és ha hiányzik, ezt a jobot indítja (kampányonként
 * egyszerre egyet). Futó kampánynál 10 perc, lezártnál (a szám már alig mozog) 1 óra.
 */
final class RefreshCampaignStats implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $campaignUuid,
        public readonly int $workspaceId,
    ) {
        $this->afterCommit();
    }

    public static function cacheKey(string $campaignUuid): string
    {
        return 'campaign-stats:'.$campaignUuid;
    }

    public function uniqueId(): string
    {
        return $this->campaignUuid;
    }

    public function handle(WorkspaceContext $context): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $context->set($workspace);

        try {
            $campaign = Campaign::query()->where('uuid', $this->campaignUuid)->first();

            if ($campaign === null) {
                return;
            }

            $row = CampaignEngagement::query()
                ->where('scope', 'campaign:'.$campaign->uuid)
                ->selectRaw('count(*) filter (where delivered > 0) as delivered, count(*) filter (where opens > 0 or clicks > 0) as opened, count(*) filter (where clicks > 0) as clicked')
                ->first();

            Cache::put(self::cacheKey((string) $campaign->uuid), [
                'delivered' => (int) ($row->delivered ?? 0),
                'opened' => (int) ($row->opened ?? 0),
                'clicked' => (int) ($row->clicked ?? 0),
            ], $campaign->status === 'sending' ? 600 : 3600);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
