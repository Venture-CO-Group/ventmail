<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        $this->assertNoDevelopmentBackdoors();
    }

    /**
     * Az `api` limiter kulcsa a WORKSPACE, nem az IP: egy ESP-nél az IP-alapú limit
     * használhatatlan (proxy, mobilhálózat). A per-tenant kvóta a PreSendGate 4. és 5.
     * kapujának alapja (M0-3).
     */
    /**
     * Deploy-idejű őr: élesben a fejlesztői belépés route-ja nem is LÉTEZHET.
     *
     * Miért kell a config-kapcsoló mellé: egy `local` környezetben készült és az image-be
     * sütött `route:cache` átemelné a route-ot élesbe. A tesztek élesben nem futnak — ez fut.
     */
    private function assertNoDevelopmentBackdoors(): void
    {
        if ($this->app->isProduction() && Route::has('auth.dev-login')) {
            throw new RuntimeException(
                'A fejlesztői belépés route-ja jelen van éles környezetben (valószínűleg beégetett '
                .'route-cache). Töröld a cache-t és állítsd IDENTITY_DEV_LOGIN=false.'
            );
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // A `/v1`-en nincs session-user: a kulcs a bucket alapja. IP-alapon két tenant
            // egy NAT vagy CI-runner mögött megeszi egymás keretét (cross-tenant DoS),
            // egy kulcs pedig sok IP-ről gyakorlatilag korlátlan lenne.
            //
            // A throttle az `api.auth` ELŐTT fut, ezért a bearer tokent hasheljük — nyers
            // kulcs sosem kerül cache-kulcsba.
            $bearer = $request->bearerToken() ?? $request->getPassword();

            $bucket = is_string($bearer) && $bearer !== ''
                ? 'key:'.hash('sha256', $bearer)
                : 'ip:'.$request->ip();

            return Limit::perMinute(300)->by($bucket);
        });

        // A bejelentkezési folyamat hitelesítetlen: minden hívás session-rekordot ír és
        // kimenő HTTP-t indít a ventSSO felé, ezért IP-alapon szűkebb a keret.
        RateLimiter::for('oidc', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }
}
