<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Api\Http\Middleware\AuthenticateApiKey;
use App\Modules\Api\Http\Middleware\AuthorizeChannel;
use App\Modules\Api\Http\Middleware\RequireApiScope;
use App\Modules\Api\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Tenancy\Http\Middleware\ResolveWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // A tenant-kontextus feloldása és a tagság-ellenőrzés minden workspace-hez kötött
        // útvonalon kötelező (golden rule 6).
        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            // A `/v1` felület őrei: kulcs-hitelesítés, scope és idempotencia.
            'api.auth' => AuthenticateApiKey::class,
            'api.scope' => RequireApiScope::class,
            'api.idempotency' => RequireIdempotencyKey::class,
            'api.channel' => AuthorizeChannel::class,
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

        // Caddy mögött enélkül a kliens IP a proxyé lenne (rossz rate-limit kulcs és audit-forrás),
        // és a generált URL-ek http:// sémával jönnének — az OIDC redirect_uri-t a ventSSO
        // jogosan utasítaná el. Élesben az `at:` a konkrét proxy-hálóra szűkítendő.
        // A `*` SZTRINGKÉNT megy tovább: az `explode` egyelemű TÖMBÖT adott (`['*']`), amire
        // a Laravel wildcard-ága nem fut le, és az `IpUtils::checkIp` sem illeszt — vagyis
        // a beállítás némán no-op volt. Ez akkor válik veszélyessé, amikor bármi (LB,
        // Cloudflare) a Caddy elé kerül: onnantól MINDEN kérés forrás-IP-je a proxyé, tehát
        // egyetlen közös rate-limit vödörbe esne az összes tenant.
        $proxies = (string) env('TRUSTED_PROXIES', '*');

        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : explode(',', $proxies),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A Laravel a BINDINGEKET behelyettesíti a QueryException üzenetébe — egy hibás
        // INSERT így a titkosított DKIM privát kulcsot a naplóba írná. A log bizalmi
        // szintje alacsonyabb, mint az adatbázisé: ciphertext + APP_KEY szivárgás =
        // kulcskompromittálás.
        $exceptions->map(fn (QueryException $e) => new QueryException(
            $e->getConnectionName(),
            $e->getSql(),
            array_fill(0, count($e->getBindings()), '?'),
            $e->getPrevious() ?? $e
        ));

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

        // Titok soha nem kerülhet naplóba vagy exception-kontextusba.
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'api_key', 'client_secret', 'smtp_password', 'dkim_private_key', 'token',
            // OIDC-tranziensek: az authorization code query-paraméterben érkezik.
            'code', 'state', 'nonce', 'code_verifier', 'id_token', 'access_token', 'refresh_token',
        ]);
    })->create();
