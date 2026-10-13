<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Jobs;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Actions\StartCampaign;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
 *
 * ## Saját sor és kapcsolat (review BN1)
 *
 * A `campaign-start` sor a `redis_campaign_start` kapcsolaton fut (`retry_after` 960 mp > a 900 mp-es
 * timeout), külön Horizon-supervisorral. A közös `redis` kapcsolat 90 mp-es `retry_after`-e alatt a
 * futó materializálást a sor újra kiadta volna: két példány írta volna ugyanazt a kampányt, két
 * köteget indított volna, és a negyedik kiadásnál a `MaxAttemptsExceeded` a SIKERES kampányt
 * szüneteltette volna. A `ShouldBeUnique` ez ellen nem véd (az a sorba-tételt szűri, nem a
 * futást) — ezért a `WithoutOverlapping` is: a kampányonkénti zár alatt a második példány
 * csendben elmarad (a munka már folyik).
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
        $this->onConnection(config('queue.campaign_start_connection'));
        $this->onQueue('campaign-start');
        $this->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // A zár a job TIMEOUTJA + 30 mp-ig él (review NIT), a `retry_after` (960) ELŐTT lejár. Ha a
        // zár a `retry_after`-ig élne, a meghalt worker után újrakiadott job a `dontRelease` miatt
        // csendben eldobódna, és a kampány `sending` + `materialized_at = NULL` állapotban ragadna.
        return [
            (new WithoutOverlapping($this->campaignUuid))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
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
