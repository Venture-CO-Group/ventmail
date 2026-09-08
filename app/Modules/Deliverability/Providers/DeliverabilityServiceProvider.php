<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Providers;

use App\Modules\Deliverability\Console\CheckBlocklistsCommand;
use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Deliverability\Services\DnsBlocklistClient;
use App\Modules\Deliverability\Testing\FakeSeedListProvider;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Deliverability modul — blocklist, DMARC RUA, seed/inbox placement, spam-score, MTA-STS.
 */
final class DeliverabilityServiceProvider extends ModuleServiceProvider
{
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    public function register(): void
    {
        $this->app->bind(BlocklistClient::class, DnsBlocklistClient::class);

        // A SEED-lista külső gazda (a postafiókok kezelése és az olvasás szolgáltatás). Amíg
        // nem él, a fake áll a helyén — teszttel fedett kontraktusként, nem csendes
        // helyettesítőként: a `deliverability.seed.enabled` alapból KI van kapcsolva, tehát
        // a felület nem mutat kitalált számokat.
        $this->app->bind(SeedListProvider::class, FakeSeedListProvider::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckBlocklistsCommand::class]);
        }
    }
}
