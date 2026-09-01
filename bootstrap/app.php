<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

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

        // A Laravel `api` csoportja alapból CSAK SubstituteBindings — throttle nincs benne.
        // A PreSendGate 5. kapuja (rate-limit) mellett az API-felület is limitált kell legyen.
        $middleware->throttleApi();

        // Caddy mögött enélkül a kliens IP a proxyé lenne (rossz rate-limit kulcs és audit-forrás),
        // és a generált URL-ek http:// sémával jönnének — az OIDC redirect_uri-t a ventSSO
        // jogosan utasítaná el. Élesben az `at:` a konkrét proxy-hálóra szűkítendő.
        $middleware->trustProxies(
            at: explode(',', (string) env('TRUSTED_PROXIES', '*')),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Titok soha nem kerülhet naplóba vagy exception-kontextusba.
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'api_key', 'client_secret', 'smtp_password', 'dkim_private_key', 'token',
        ]);
    })->create();
