<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Suppression\Services\UnsubscribeTokens;
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
     * A fejlesztői route-ok, amelyeknek élesben létezniük SEM szabad.
     *
     * Névre szóló lista, mert a védelem a route NEVÉT nézi: aki új dev-route-ot vesz fel,
     * annak ide is fel kell vennie. A `DevRouteGuardTest` őrzi, hogy a lista teljes legyen.
     *
     * @var list<string>
     */
    private const DEVELOPMENT_ROUTES = ['auth.dev-login', 'dev.seed-logs'];

    /**
     * Deploy-idejű őr: élesben a fejlesztői belépés route-ja nem is LÉTEZHET.
     *
     * Miért kell a config-kapcsoló mellé: egy `local` környezetben készült és az image-be
     * sütött `route:cache` átemelné a route-ot élesbe. A tesztek élesben nem futnak — ez fut.
     */
    private function assertNoDevelopmentBackdoors(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        foreach (self::DEVELOPMENT_ROUTES as $name) {
            // BÁRMELYIK jelenléte elég a leálláshoz — nem az összesé.
            if (Route::has($name)) {
                throw new RuntimeException(sprintf(
                    'A fejlesztői route (%s) jelen van éles környezetben (valószínűleg beégetett '
                    .'route-cache). Töröld a cache-t és állítsd IDENTITY_DEV_LOGIN=false.',
                    $name,
                ));
            }
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
        // NYOMKÖVETÉS: publikus endpoint sosem marad limit nélkül — enélkül az eseménytábla
        // hizlalására lenne jó.
        //
        // A kulcs a TOKEN, nem az IP. A Gmail MINDEN képet a saját proxyján keresztül tölt
        // be, néhány Google-IP-ről, az egész platform forgalmára összegezve: IP-kulccsal a
        // limit pont a legnagyobb ügyfeleknél ütne be, és `opened` események vesznének el.
        // Tokenenként viszont a limit pontosan azt fogja meg, amit akarunk — ugyanannak a
        // pixelnek az ismételt hívását.
        // A kulcs a KANONIKUS IGÉNY, nem a token bájtjai: azokból több ezer érvényes variáns
        // gyártható ugyanarra az igényre, tehát token-kulccsal a fék megkerülhető lenne.
        // Értelmezhetetlen tokennél az IP a kulcs — ott nincs mihez kötni.
        RateLimiter::for('tracking', function (Request $request) {
            $tokens = app(TrackingTokens::class);
            $claim = $tokens->parse((string) $request->route('token'));

            return Limit::perMinute(60)->by(
                $claim === null ? 'peer:'.self::peer($request) : 'claim:'.$tokens->fingerprint($claim),
            );
        });

        /*
         | LEIRATKOZÁS: publikus, HITELESÍTÉS NÉLKÜLI, ÍRÓ végpont. Két bucket, két különböző
         | dolgot védve — és egyik sem a `request->ip()`.
         |
         | Miért nem az: a `TRUSTED_PROXIES` alapértéke `*`, tehát a `request->ip()` a KÉRŐ
         | által beírt `X-Forwarded-For`-ból jön. Mérve: 150 kérés fix XFF-fel 30 db 429-et
         | kapott, ROTÁLÓ XFF-fel egyet sem. Vagyis az egyetlen fék ezen a végponton egy
         | fejléccel kikapcsolható volt — egy visszavonhatatlan suppression-listára író
         | végponton, ami egy tenant teljes közönségét le tudta volna iratkoztatni.
         |
         |  - `claim:` — a KONKRÉT címzettre szóló kísérletek keretét zárja. Ez az áldozat
         |    védelme, és rotáló XFF-fel sem nyílik ki, mert a kulcs a tokenből származik.
         |  - `peer:` — a valódi TCP-partner (`REMOTE_ADDR`), amit a kérő nem tud átírni. Ez a
         |    platform védelme az értelmezhetetlen tokenek özöne ellen.
         |
         | A keret bőséges: egy kampány után sokan iratkoznak le egyszerre, és a Gmail
         | ÚJRAPRÓBÁLJA a one-click POST-ot. A `claim:` bucket ennél szűkebb — ugyanarra a
         | címzettre percenként tíz kísérlet már nem legitim minta.
         */
        RateLimiter::for('unsubscribe', function (Request $request) {
            $fingerprint = app(UnsubscribeTokens::class)->fingerprint((string) $request->route('token'));

            return [
                // A PLATFORM-plafon bőkezű, és ez nem lazaság. A `REMOTE_ADDR` egyetlen proxy
                // mögött MINDEN kérésre ugyanaz, tehát ez a vödör a teljes platform
                // leiratkozási forgalmát fogja — és a one-click POST-ok a Gmail/Yahoo néhány
                // szolgáltatói IP-jéről aggregálódnak. Mérve a korábbi 120/perc-cel: 200
                // legitim címzett közül a 121. már 429-et kapott. Egy 429 a one-click POST-ra
                // Gmail-szemmel MEGFELELÉSI hiba (golden rule 8) — a szűk plafon itt nem
                // védelem, hanem önkár.
                Limit::perMinute(3000)->by('peer:'.self::peer($request)),
                // A FINOM fék az IGÉNYRE kulcsol: ugyanarra a címzettre percenként tíz
                // kísérlet már nem legitim minta, és ez rotáló `X-Forwarded-For`-ral sem
                // nyílik ki, mert a kulcs a tokenből származik.
                //
                // Az ÉRTELMEZHETETLEN token a partnerre esik vissza, NEM egy közös literálra.
                // Egy `claim:invalid` vödör minden hibás tokent egyetlen 10/perces keretbe
                // tett — mérve: 14 kérés 14 KÜLÖNBÖZŐ IP-ről 4 db 429-et adott, és utána egy
                // MÁS felhasználó elrontott linkje is 429 lett. A szemetet küldő támadó a
                // saját keretével fogyasztotta el másokét; pontosan az, amit a vektorfájl
                // docblockja tilt.
                $fingerprint === null
                    ? Limit::perMinute(60)->by('invalid:'.self::peer($request))
                    : Limit::perMinute(10)->by('claim:'.$fingerprint),
            ];
        });

        /*
         | MTA-STS: idegen MTA-k kérik le, hosztonként. Bőkezű keret — a policy statikus, és a
         | fogadók cache-elik.
         |
         | A HOSZT önmagában nem elég kulcs: a 200 vs. 404 különbség orákulum arra, hogy egy
         | domain verifikált ventMail-ügyfél-e, és hosztonkénti kulccsal a felsorolás
         | gyakorlatilag fékezetlen (minden próba új bucketet nyit). Ezért mellé a valódi
         | TCP-partner is kap egy plafont.
         */
        RateLimiter::for('mta-sts', fn (Request $request) => [
            // A HOSZT a NYERS `Host` fejlécből, ugyanúgy, ahogy a `MtaStsController` olvassa.
            // A `getHost()` az `X-Forwarded-Host`-ot részesíti előnyben, amit bárki beírhat —
            // mérve: rotáló `X-Forwarded-Host`-tal a 300/perces vödör kinyílt. A controller
            // javítása után egy ilyen kulcs dekoratív lett volna: védeni látszik, de nem véd.
            Limit::perMinute(300)->by('host:'.strtolower((string) $request->headers->get('host'))),
            // A partner-plafon a FELSOROLÁS ellen van (a 200 vs. 404 orákulum arra, hogy egy
            // domain verifikált ügyfelünk-e), de nem lehet szűk: a policy-t IDEGEN MTA-k
            // kérik le, és a lekérés meghiúsulása `enforce` módban TLS-lefokozás felé nyit.
            Limit::perMinute(600)->by('peer:'.self::peer($request)),
        ]);

        RateLimiter::for('oidc', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }

    /**
     * A VALÓDI TCP-partner — nem a `request->ip()`.
     *
     * A `request->ip()` a `TRUSTED_PROXIES` (alapértéke `*`) miatt a KÉRŐ által beírt
     * `X-Forwarded-For` fejlécből jön. Ez a naplózáshoz és a földrajzi jelzéshez elfogadható
     * (ott a proxy adja a helyes értéket), egy RATE-LIMIT KULCSÁHOZ viszont nem: aki a
     * fejlécet írja, magának ad friss keretet.
     *
     * A `REMOTE_ADDR` a proxy címe. Egyetlen proxy mögött ez minden kérésre ugyanaz, tehát
     * DURVA kulcs — épp ezért csak PLATFORM-szintű plafonként használjuk, a finom fék pedig
     * mindig az igényre kulcsol (`claim:`).
     */
    private static function peer(Request $request): string
    {
        return (string) ($request->server('REMOTE_ADDR') ?? 'unknown');
    }
}
