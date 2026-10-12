<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Consent\Services\ConfirmationTokens;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Support\ClientAddress;
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
            // A „hitelesítetlen özön" plafonja a VALÓDI TCP-partnerre kulcsol, nem a
            // `request->ip()`-re. A `TRUSTED_PROXIES` alapértéke `*`, tehát a
            // `request->ip()` a KÉRŐ által beírt `X-Forwarded-For`-ból jön (mérve) — aki a
            // fejlécet írja, magának ad friss keretet, és épp a kulcs nélküli forgalom
            // plafonja szűnik meg. A `peer()` a `REMOTE_ADDR`, amit a kérő nem tud átírni.
            $bearer = $request->bearerToken() ?? $request->getPassword();
            $peer = self::peer($request);

            if (! is_string($bearer) || $bearer === '') {
                return Limit::perMinute(60)->by('peer:'.$peer);
            }

            return [
                Limit::perMinute(300)->by('key:'.KeyedHash::of('rate_limit', $bearer)),
                // A partner-plafon a KULCS-FORGATÓ támadó ellen van: kérésenként más Bearer
                // értékkel a `key:` vödör sosem telik meg. Bőkezű, mert egy CI-runner vagy
                // NAT mögött több tenant is lehet — de nem átírható fejlécből származik.
                Limit::perMinute(1200)->by('peer:'.$peer),
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
        // A Postal webhookja: a belső hálózatról jön, aláírással. A plafon a kampány-
        // volumenű, címzettenkénti `MessageSent` fölött van (különben 429 után a Postal
        // eldobná az eseményeket), de egy elszabadult hívót így is megfog.
        RateLimiter::for('postal-webhook', fn (Request $request) => Limit::perMinute(30000)->by('peer:'.self::peer($request)));

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

            /*
             | ÉRVÉNYES tokenre NINCS platform-szintű plafon — és ez nem lazaság.
             |
             | Volt: 120/perc, majd 3000/perc a `REMOTE_ADDR`-ra. Mindkettő rossz, és a
             | második rosszabb módon. A `REMOTE_ADDR` a Caddy mögött MINDEN kérésre a proxy
             | IP-je, tehát EGYETLEN vödör a teljes platform leiratkozási forgalmára. Mérve
             | 120-cal: 200 legitim címzettből a 121. már 429-et kapott. Mérve 3000-rel: egy
             | támadó percenként 3000 kéréssel kimeríti a vödröt, és onnantól MINDEN valódi
             | Gmail one-click POST 429-et kap — vagyis a „védelem" globális kill switch lett.
             |
             | Egy 429 a one-click POST-ra Gmail-szemmel MEGFELELÉSI hiba (golden rule 8): a
             | szűk plafon itt nem védelem, hanem önkár, a bőkezű pedig fegyver a támadó
             | kezében. Ezért az érvényes token fékje KIZÁRÓLAG az igényre kulcsol — az
             | pontosan azt korlátozza, amit korlátozni kell (ugyanarra a címzettre irányuló
             | ismétlést), és rotáló `X-Forwarded-For`-ral sem nyílik ki, mert a kulcs a
             | tokenből származik.
             |
             | A hatás így is korlátos: érvényes tokent csak az kaphat, akinek levelet
             | küldtünk, és minden token 10/perc keretet visz. Az ÉRTELMEZHETETLEN token —
             | amiből végtelen sok gyártható — a valódi partnerre esik vissza.
             */
            return [
                $fingerprint === null
                    // NEM egy közös literál: a `claim:invalid` vödör minden hibás tokent
                    // egyetlen 10/perces keretbe tett — mérve: 14 kérés 14 KÜLÖNBÖZŐ IP-ről
                    // 4 db 429-et adott, és utána egy MÁS felhasználó elrontott linkje is
                    // 429 lett. A szemetet küldő a saját keretével fogyasztotta el másokét.
                    ? Limit::perMinute(60)->by('invalid:'.self::peer($request))
                    // A GET és a POST KÜLÖN vödör: a megerősítő oldal betöltése (SafeLinks
                    // előtöltés, a felhasználó visszalépése) nem fogyaszthatja el azt a
                    // keretet, amiből a Gmail one-click POST-ja és annak újrapróbálása él.
                    // Egy vödörből öt GET után a valódi leiratkozás 429-be futott volna.
                    : Limit::perMinute(10)->by('claim:'.$request->method().':'.$fingerprint),
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
            // domain verifikált ügyfelünk-e), de a HOSZTTAL PÁROSÍTVA kulcsol.
            //
            // Önálló partner-vödörként ugyanaz a „globális kill switch" alak lett volna,
            // amit az `unsubscribe` limiterből épp eltávolítottunk: a `REMOTE_ADDR` a Caddy
            // mögött MINDEN kérésre a proxy IP-je, tehát egyetlen vödör az összes idegen
            // MTA-ra. Aki azt kimeríti, minden domain policy-lekérését 429-re állítja — és a
            // policy elérhetetlensége `enforce` módban TLS-lefokozás felé nyit, vagyis a
            // „védelem" pont a védendő dolgot rontja el.
            //
            // Párosítva a felsorolás továbbra is fékezett (domainenként külön vödör kell
            // hozzá), egyetlen domain lekérdezése viszont nem tudja a többit kizárni.
            Limit::perMinute(120)->by('peer-host:'.self::peer($request).'|'.strtolower((string) $request->headers->get('host'))),
        ]);

        // A bejelentkezési folyamat hitelesítetlen, és minden hívás session-rekordot ír meg
        // kimenő HTTP-t indít a ventSSO felé — ezért szűk a keret. A kulcs itt is a VALÓDI
        // partner: `request->ip()`-vel egy `X-Forwarded-For` fejléccel bárki friss keretet
        // adott volna magának, tehát a fék pont a hitelesítetlen úton nem fékezett.
        RateLimiter::for('oidc', fn (Request $request) => Limit::perMinute(20)->by('peer:'.self::peer($request)));

        /*
         | B1 · A PUBLIKUS, HITELESÍTÉS NÉLKÜLI VÉGPONTOK.
         |
         | Mindhárom a levélből vagy az ügyfél weboldaláról érkezik, tehát nincs se session,
         | se API-kulcs. A fékek ugyanazt a formát követik, amit az A6-ban négy review-kör
         | alatt megtanultunk:
         |
         |  - a kulcs SOHA nem a `request->ip()` (az a kérő szava, lásd `peer()`);
         |  - ahol van token, a finom fék az IGÉNY LENYOMATÁRA kulcsol — nem a token
         |    bájtjaira, mert a `Crypt`-boríték újraszerializálható és IV-vel változik;
         |  - az értelmezhetetlen token a valódi partnerre esik vissza, NEM egy közös
         |    literálra: különben egy szemetet küldő támadó a saját keretével fogyasztaná el
         |    a legitim felhasználókét.
         */
        RateLimiter::for('public-form', fn (Request $request) => [
            // Az ŰRLAP-BEKÜLDÉS az egyetlen, ahol nincs token — a beküldő névtelen. A
            // partner-plafon bőkezű (egy iskolai vagy céges NAT mögül sokan iratkozhatnak
            // fel egyszerre), de nem korlátlan: e nélkül az űrlap egy `pending` consent-gyár
            // lenne, és a megerősítő levelek a MI reputációnkat vinnék.
            Limit::perMinute(30)->by('peer:'.self::peer($request)),
            // Az ŰRLAPONKÉNTI plafon azt akadályozza meg, hogy egy elszabadult bot EGY
            // űrlapon keresztül tömje a listát — akkor is, ha sok IP-ről jön.
            Limit::perMinute(300)->by('form:'.$request->route('slug')),
        ]);

        /*
         | MEGHÍVÓ ÉS AL-FIÓK — a két művelet, ami a TENANTON KÍVÜLRE hat.
         |
         | A meghívó LEVELET küld egy tetszőleges idegen címre, a MI hitelesített
         | domainünkről; az al-fiók pedig új workspace-t hoz létre a parent számlájára.
         | Egyik sem nagy forgalmú művelet — a plafon a hibás ciklust és a
         | felület-visszaélést fogja meg, a valódi használatot nem érinti.
         |
         | A kulcs a WORKSPACE, nem a felhasználó: két admin együtt sem küldhet többet,
         | mert a reputációt és a számlát a workspace viseli.
         */
        RateLimiter::for('member-invites', fn (Request $request) => [
            Limit::perMinute(10)->by('ws:'.self::workspaceKey($request)),
            Limit::perDay(100)->by('ws:'.self::workspaceKey($request)),
        ]);

        RateLimiter::for('member-roles', fn (Request $request) => [
            Limit::perMinute(30)->by('ws:'.self::workspaceKey($request)),
        ]);

        // ÚJ WORKSPACE: a kulcs a FELHASZNÁLÓ (nincs még tenant, amit védeni lehetne).
        RateLimiter::for('workspace-create', fn (Request $request) => [
            Limit::perMinute(5)->by('user:'.($request->user()?->getAuthIdentifier() ?? self::peer($request))),
            Limit::perDay(30)->by('user:'.($request->user()?->getAuthIdentifier() ?? self::peer($request))),
        ]);

        /*
         | KONTAKT-IMPORT FELTÖLTÉS (v1.1): a legdrágább kérés (akár 100 MB, háromszori felmérés).
         |
         | A kulcs a WORKSPACE: az import a workspace címlistáját írja, és a hitelesített, de
         | rosszindulatú (vagy hibás ciklusban futó) tag ne tudja a közös lemezt és a CPU-t
         | megtölteni. Valódi használat (percenként néhány feltöltés) nem éri el a plafont.
         */
        RateLimiter::for('audience-import', fn (Request $request) => [
            Limit::perMinute(20)->by('ws:'.self::workspaceKey($request)),
            Limit::perHour(200)->by('ws:'.self::workspaceKey($request)),
        ]);

        RateLimiter::for('sub-accounts', fn (Request $request) => [
            Limit::perMinute(5)->by('ws:'.self::workspaceKey($request)),
            Limit::perDay(50)->by('ws:'.self::workspaceKey($request)),
        ]);

        RateLimiter::for('consent-confirm', function (Request $request) {
            $fingerprint = app(ConfirmationTokens::class)->fingerprint((string) $request->route('token'));

            return [
                $fingerprint === null
                    ? Limit::perMinute(60)->by('invalid:'.self::peer($request))
                    // Ugyanarra a megerősítésre percenként tíz kattintás már nem legitim
                    // minta (a kliens-előtöltés is belefér).
                    : Limit::perMinute(10)->by('optin:'.$fingerprint),
            ];
        });

        RateLimiter::for('preferences', function (Request $request) {
            $fingerprint = app(PreferenceTokens::class)->fingerprint((string) $request->route('token'));

            return [
                $fingerprint === null
                    ? Limit::perMinute(60)->by('invalid:'.self::peer($request))
                    // A GET és a POST KÜLÖN vödör: az oldal betöltése (előtöltés,
                    // visszalépés) ne fogyassza el a MENTÉS keretét.
                    : Limit::perMinute(20)->by('prefs:'.$request->method().':'.$fingerprint),
            ];
        });
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
    /**
     * A LIMIT KULCSA: a munkamenet workspace-e, ha van; különben a felhasználó.
     *
     * A `WorkspaceContext` a limiter futásakor még nem feltétlenül feloldott (a
     * route-middleware sorrendje kéréstől függ), ezért a session-ben tárolt uuid a
     * megbízható jel — ez az, amit a `ResolveWorkspace` is használ.
     */
    private static function workspaceKey(Request $request): string
    {
        $uuid = $request->hasSession() ? (string) $request->session()->get('workspace_uuid', '') : '';

        return $uuid !== '' ? $uuid : 'user:'.(string) ($request->user()?->getKey() ?? self::peer($request));
    }

    private static function peer(Request $request): string
    {
        // A TCP-partner (`server('REMOTE_ADDR')`) — vagy a SZŰKÍTETT edge-listáról érkező
        // kérésnél a továbbított kliens-IP. Enélkül az edge mögött minden fék egyetlen
        // globális vödör lenne. Lásd `ClientAddress`.
        return ClientAddress::bucket($request);
    }
}
