<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Console\PurgeClickMapCommand;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Analytics modul — marketing analitika (72–74. kritérium).
 *
 * UGYANARRA az esemény-forrásra épül, mint a napló: a `message_events`-ből, a kampány és a
 * journey TAGJÉN keresztül. Saját számlálót SZÁNDÉKOSAN nem tart — két számláló
 * elkerülhetetlenül elcsúszik, és akkor a tenant két „delivered" számot lát ugyanarra a
 * kampányra a napló-oldalon és a marketing-riportban.
 *
 * A KLIKK-TÉRKÉP a kivétel, és jó okkal: a kattintott cím szándékosan NEM kerül az
 * eseménybe (a webhook harmadik félhez viszi ki), ezért a térkép saját táblát kap, ami
 * soha nem kerül webhook-payloadba.
 */
final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeClickMapCommand::class]);
        }
    }
}
