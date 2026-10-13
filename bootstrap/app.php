<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\RestrictLandingHost;
use App\Http\Middleware\TrustConfiguredHosts;
use App\Http\Middleware\TrustEdgeProxies;
use App\Modules\Api\Http\Middleware\AuthenticateApiKey;
use App\Modules\Api\Http\Middleware\AuthorizeChannel;
use App\Modules\Api\Http\Middleware\RequireApiScope;
use App\Modules\Api\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Billing\Http\Middleware\EnsureEntitlement;
use App\Modules\Identity\Exceptions\SsoAccessDenied;
use App\Modules\Identity\Http\Middleware\SetLocale;
use App\Modules\SuperAdmin\Http\Middleware\EnforceImpersonation;
use App\Modules\Tenancy\Http\Middleware\ResolveWorkspace;
use App\Support\RedactQueryException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // A KAMPÁNY-HALADÁS privát csatornán megy (B2 · 65. kritérium). A jogosultságot a
        // `channels.php` őrzi: a ventMail SAJÁT `workspace_members` táblájából, mert a
        // tenant-tagság gazdája a ventMail — egy OIDC-token önmagában nem mondja meg,
        // melyik workspace-hez tartozik a felhasználó.
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A törzs-plafon a LEGELSŐ: a törzs feldolgozása (és bármi más) előtt dönt.
        $middleware->prepend(LimitRequestBody::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // A KÉRÉS NYELVE (C2 · 89. kritérium): a bejelentkezett felhasználó
            // `locale`-ja, különben az `Accept-Language` — mindkettő a támogatott
            // nyelvekre szűrve. Enélkül a hat nyelvet SEMMI nem választotta ki.
            SetLocale::class,
            /*
             | AZ ÍRÁS-TILALOM aktív támogatói nézet alatt (82. kritérium).
             |
             | A `web` csoport EGÉSZÉRE szól, nem egy route-listára: egy jövőbeli írási
             | végpontot senki nem fog felvenni egy tiltólistára, a biztonságos
             | alapértelmezés viszont magától védi. A `/v1` (API-kulcsos gépi felület) nem
             | kap impersonation-t, ott nincs mit tiltani.
             */
            EnforceImpersonation::class,
        ]);

        // A `/v1` gépi felület is nyelvet vált: a hívó az `Accept-Language`-ben mondja meg,
        // milyen nyelven kéri a hibaüzenetet. A `code` marad a szerződés (golden rule 7).
        $middleware->api(append: [SetLocale::class]);

        // A tenant-kontextus feloldása és a tagság-ellenőrzés minden workspace-hez kötött
        // útvonalon kötelező (golden rule 6).
        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            // A `/v1` felület őrei: kulcs-hitelesítés, scope és idempotencia.
            'api.auth' => AuthenticateApiKey::class,
            'api.scope' => RequireApiScope::class,
            'api.idempotency' => RequireIdempotencyKey::class,
            'api.channel' => AuthorizeChannel::class,
            // C1 · CSOMAG-FUNKCIÓ a route-on: `entitlement:journeys`. A spec ezt
            // nevesíti, és a B3 review megállapította, hogy nem létezett — az
            // ellenőrzés szolgáltatásokba szórva élt, tehát minden új belépési pontnak
            // újra meg kellett volna írnia.
            'entitlement' => EnsureEntitlement::class,
        ]);

        // A Laravel alapból a `login` nevű route-ra irányítaná a vendéget — az nálunk nem
        // létezik (az identitás gazdája a ventSSO), és „Route [login] not defined" 500-at adna.
        // A böngésző-kérést a ventSSO-belépésre irányítjuk; a `/v1` és a JSON-kérés
        // viszont 401-et kap: a 302 kontraktus-változás lenne (golden rule 7), és minden
        // ilyen kérés `url.intended` írásával járna — olcsó session-flooding vektor.
        $middleware->redirectGuestsTo(fn ($request) => $request->expectsJson() || $request->is('v1/*')
            ? null
            : route('auth.redirect'));

        // A Laravel `api` csoportja alapból CSAK SubstituteBindings — throttle nincs benne.
        // A PreSendGate 5. kapuja (rate-limit) mellett az API-felület is limitált kell legyen.
        $middleware->throttleApi();

        // Caddy mögött enélkül a generált URL-ek http:// sémával jönnének (az OIDC
        // redirect_uri-t a ventSSO jogosan utasítaná el), a kliens-IP pedig a proxyé lenne.
        // A `*` NEM no-op: mellette az `X-Forwarded-For` és az `X-Forwarded-Host` a KÉRŐ
        // szava. A rate-limitek kulcsa ezért a `ClientAddress` (a TCP-partner, vagy a
        // szűkített edge-listáról érkező kérésnél a továbbított cím).
        // A bizalmi határ a `config/proxy.php`-ben (deploy-ovh): alapból SENKINEK nem
        // hiszünk, élesben csak az edge-proxy `/32`-jének. A Host-fejlécet élesben a
        // megbízható hostlista szűri.
        $middleware->replace(TrustProxies::class, TrustEdgeProxies::class);
        // Az apex (landing) host csak a termékoldalt szolgálja ki — minden mást 404-gyel zár. GLOBÁLIS,
        // közvetlenül a host-ellenőrzés után: a /v1, horizon, /internal sem kerülheti meg.
        // A TrustEdgeProxies UTÁN fut (append): a `getHost()` így a trusted proxy továbbított hostját adja.
        $middleware->append(RestrictLandingHost::class);
        $middleware->prepend(TrustConfiguredHosts::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A Laravel a BINDINGEKET behelyettesíti a QueryException üzenetébe — egy hibás
        // INSERT így a titkosított DKIM privát kulcsot a naplóba írná. A log bizalmi
        // szintje alacsonyabb, mint az adatbázisé: ciphertext + APP_KEY szivárgás =
        // kulcskompromittálás.
        $exceptions->map(fn (QueryException $e) => (new RedactQueryException)($e));

        // A `/v1` egységes hibaformátuma a VALIDÁCIÓRA is érvényes: a Laravel alap
        // `{message, errors}` alakja nem az `Error` séma, és egy generált kliens az
        // `error.code`-ra ágazik — az `undefined` lenne (golden rule 7).
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'A kérés nem felel meg a sémának.',
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        // A ventSSO-belépés megtagadása (nincs ventMail-szerep/tagság/meghívó): barátságos oldal,
        // de a 403 marad — a státusz a kontraktus, az oldal csak a felhasználónak szól.
        // (A Laravel a render-callbackek ELŐTT az AuthorizationException-t AccessDeniedHttpException-re
        // fordítja — ezért az az elfogott típus, az eredeti a `getPrevious()`-ben van.)
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof AuthorizationException || ! $request->routeIs('auth.callback')) {
                return null;
            }

            // JSON-ra is generikus válasz: a belső ok (state/nonce/aud/szerep) validációs orákulum lenne.
            if ($request->expectsJson()) {
                return response()->json(['message' => __('ventmail.auth.retry')], 403);
            }

            // Csak a szerep/tagság-megtagadás kap „nincs hozzáférésed" oldalt; a többi hiba
            // (state, token, impersonáció) újrabejelentkezés — a belső okot nem mutatjuk.
            if (! $e->getPrevious() instanceof SsoAccessDenied) {
                return redirect('/')->with('error', __('ventmail.auth.retry'));
            }

            return Inertia::render('Auth/Denied')->toResponse($request)->setStatusCode(403);
        });

        // Titok soha nem kerülhet naplóba vagy exception-kontextusba.
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'api_key', 'client_secret', 'smtp_password', 'dkim_private_key', 'token',
            // OIDC-tranziensek: az authorization code query-paraméterben érkezik.
            'code', 'state', 'nonce', 'code_verifier', 'id_token', 'access_token', 'refresh_token',
        ]);
    })->create();
