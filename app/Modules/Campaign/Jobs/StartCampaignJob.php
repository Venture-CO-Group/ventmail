<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Jobs;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Actions\StartCampaign;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Sending\Data\SendQueue;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A KAMPÁNY MATERIALIZÁLÁSA és a kötegek indítása — háttérben (v1.1 · 5. modul, review RB1/S4).
 *
 * A felület (és az ütemező) a kérésben csak LEFOGLALJA a kampányt (`draft|scheduled →
 * sending`, feltételes UPDATE), a címzett-halmaz felírása és a Horizon-batch indítása ez a job
 * a `campaign` soron. Egy 300 000-es célzás így nem egy webkérés idejében (és nem az FPM
 * időkorlátján belül) dől el — korábban egy timeout félbehagyott, beragadt `sending`
 * kampányt hagyott, címzettek nélkül.
 *
 * Ismételhető (a beszúrás `insertOrIgnore`), és egyszerre egy fut kampányonként
 * (`ShouldBeUnique`). Ha a próbálkozások elfogynak, a kampány SZÜNETELTETETT lesz (nem
 * „küldés alatt" örökre): a folytatás (`resume`) újra materializál.
 */
final class StartCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 900;

    public function __construct(
        public readonly string $campaignUuid,
        public readonly int $workspaceId,
    ) {
        $this->onQueue(SendQueue::Campaign->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->campaignUuid;
    }

    public function handle(WorkspaceContext $context, StartCampaign $start): void
    {
        $this->inWorkspace($context, function () use ($start): void {
            $campaign = Campaign::query()->where('uuid', $this->campaignUuid)->first();

            // Csak a LEFOGLALT kampány: egy közben szüneteltetett vagy visszavont kampányt a
            // késve futó job nem indíthat el.
            if ($campaign === null || $campaign->statusEnum() !== CampaignStatus::Sending) {
                return;
            }

            $start->materialize($campaign);
        });
    }

    public function failed(Throwable $e): void
    {
        $this->inWorkspace(app(WorkspaceContext::class), function () use ($e): void {
            $campaign = Campaign::query()->where('uuid', $this->campaignUuid)->first();

            if ($campaign === null || $campaign->statusEnum() !== CampaignStatus::Sending) {
                return;
            }

            app(CampaignLifecycle::class)->pause($campaign);

            app(AuditLogger::class)->log('campaign.start_failed', $campaign, context: [
                'error' => class_basename($e),
            ]);
        });
    }

    private function inWorkspace(WorkspaceContext $context, callable $work): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $context->set($workspace);

        try {
            $work();
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
