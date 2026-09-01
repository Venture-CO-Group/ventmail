<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Szigorú modell-mód: a némán eldobott attribútum és a lazy loading rejtett hibaforrás,
        // egy multi-tenant ESP-ben pedig izolációs kockázat is. Éles környezetben nem dobunk.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    /**
     * Az `api` limiter kulcsa a WORKSPACE, nem az IP: egy ESP-nél az IP-alapú limit
     * használhatatlan (proxy, mobilhálózat). A per-tenant kvóta a PreSendGate 4. és 5.
     * kapujának alapja (M0-3).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(300)->by((string) $key);
        });
    }
}
