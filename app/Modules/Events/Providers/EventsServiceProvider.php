<?php

declare(strict_types=1);

namespace App\Modules\Events\Providers;

use App\Modules\Support\ModuleServiceProvider;

/**
 * Events modul — esemény-modell, Timescale hypertable, `GET /v1/events`.
 *
 * Az A sáv a gazda, a B (Studio) csak OLVAS: a marketing analitika ugyanebből az
 * esemény-forrásból dolgozik, különben a két felület más `delivered` számot mutatna
 * ugyanarra a kampányra (M2 globális DoD).
 */
final class EventsServiceProvider extends ModuleServiceProvider
{
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';
}
