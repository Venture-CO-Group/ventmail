<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Providers;

use App\Modules\Deliverability\Console\CheckBlocklistsCommand;
use App\Modules\Deliverability\Console\IngestDmarcReportsCommand;
use App\Modules\Deliverability\Console\PurgeDeliverabilityCommand;
use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Deliverability\Services\DnsBlocklistClient;
use App\Modules\Deliverability\Testing\FakeSeedListProvider;
use App\Modules\Support\ModuleServiceProvider;
use RuntimeException;

/**
 * Deliverability modul — blocklist, DMARC RUA, seed/inbox placement, spam-score, MTA-STS.
 */
final class DeliverabilityServiceProvider extends ModuleServiceProvider
{
    /**
     * A modul EGYETLEN web-route-ja a `/.well-known/mta-sts.txt`, amit egy IDEGEN MTA hív
     * meg, bejelentkezés nélkül — a hitelesség forrása a tanúsítvány, nem a session.
     */
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    public function register(): void
    {
        $this->app->bind(BlocklistClient::class, DnsBlocklistClient::class);

        // A SEED-lista külső gazda (a postafiókok kezelése és az olvasás szolgáltatás). Amíg
        // nem él, a fake áll a helyén — teszttel fedett kontraktusként, nem csendes
        // helyettesítőként.
        //
        // ÉLESBEN NEM: egy fake seed-futás három `*.example` címre 0% beérkező arányt
        // jelentene — kitalált számot, ami alapján az ügyfél átírná a leveleit. A hiányzó
        // funkció ennél mindig jobb. Ugyanaz a minta, mint a `DomainsServiceProvider`
        // fake-resolver őrénél.
        $this->app->bind(SeedListProvider::class, function () {
            if ($this->app->isProduction()) {
                throw new RuntimeException(
                    'Éles környezetben nincs seed-lista szolgáltató: a `FakeSeedListProvider` '
                    .'kitalált inbox-placement számokat adna. A seed-mérés a külső gazda '
                    .'bekötéséig nem elérhető.'
                );
            }

            return new FakeSeedListProvider;
        });
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckBlocklistsCommand::class,
                IngestDmarcReportsCommand::class,
                PurgeDeliverabilityCommand::class,
            ]);
        }
    }
}
