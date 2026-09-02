<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Services\KeyedHash;
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
            // KÉT bucket, mindkettő egyszerre érvényes.
            //
            // Csak a kulcs alapján tiltani NEM elég: a throttle az `api.auth` ELŐTT fut,
            // tehát a bucket a MÉG NEM ELLENŐRZÖTT tokenből származna. Aki kérésenként más
            // Bearer értéket küld, kérésenként friss keretet kapott volna — vagyis a `/v1`
            // hitelesítetlen forgalomra korlátlan lett. Csak IP alapján tiltani szintén
            // rossz: két tenant egy NAT vagy CI-runner mögött megenné egymás keretét.
            //
            // Ezért: a KULCS kerete a normál működésé, az IP kerete pedig a hitelesítetlen
            // özön plafonja. A nyers kulcs sosem kerül cache-kulcsba — kulcsolt hash megy,
            // domain-elválasztással (a peppertelen SHA-256 egy Redis-dumpból offline
            // verifikátort adna az API-kulcsokhoz).
            $bearer = $request->bearerToken() ?? $request->getPassword();
            $ip = (string) $request->ip();

            if (! is_string($bearer) || $bearer === '') {
                return Limit::perMinute(60)->by('ip:'.$ip);
            }

            return [
                Limit::perMinute(300)->by('key:'.KeyedHash::of('rate_limit', $bearer)),
                Limit::perMinute(1200)->by('ip:'.$ip),
            ];
        });

        // A bejelentkezési folyamat hitelesítetlen: minden hívás session-rekordot ír és
        // kimenő HTTP-t indít a ventSSO felé, ezért IP-alapon szűkebb a keret.
        RateLimiter::for('oidc', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }
}
