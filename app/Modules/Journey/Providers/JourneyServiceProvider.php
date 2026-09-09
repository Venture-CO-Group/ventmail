<?php

declare(strict_types=1);

namespace App\Modules\Journey\Providers;

use App\Modules\Journey\Console\DetectSegmentEntriesCommand;
use App\Modules\Journey\Console\PurgeJourneyRunsCommand;
use App\Modules\Journey\Console\TickJourneysCommand;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Journey modul — Customer Journey motor: trigger → condition → action → wait → branch, idempotens lépésekkel.
 */
final class JourneyServiceProvider extends ModuleServiceProvider
{
    /*
     | A `/v1` ROUTE-OK AZ API-HITELESÍTÉS ELŐTT regisztrálódnak.
     |
     | Enélkül a `ModuleServiceProvider::REQUIRED_API` beteszi a böngésző-session `auth` +
     | `workspace` middleware-t az `api.auth` ELÉ, és a végpont API-KULCCSAL IS 401-et ad.
     | Mérve, ugyanabban a kernel-hívásban:
     |
     |     GET /v1/templates                  + templates:read  → 200
     |     GET /v1/analytics/campaigns/{uuid} + analytics:read   → 401 Unauthenticated
     |
     | A `/v1` route-okat exponáló mind a tizenegy másik modul beállítja ezt; ez a kettő
     | kimaradt, és emiatt a B3 `POST /v1/journeys/events` és a B4 kampány-riport
     | egyaránt elérhetetlen volt — miközben az OpenAPI működőként dokumentálta (golden
     | rule 7: a szerződés és a valóság között nincs drift).
     */
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TickJourneysCommand::class,
                PurgeJourneyRunsCommand::class,
                DetectSegmentEntriesCommand::class,
            ]);
        }
    }
}
