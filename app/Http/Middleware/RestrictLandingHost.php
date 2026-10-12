<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Az apex (landing) host CSAK a termékoldalt és a robots.txt-t szolgálja ki.
 *
 * A Caddy az apexet ugyanarra az upstreamre küldi, mint az app.-ot; kapu nélkül a
 * `ventmail.app/dashboard` is az alkalmazást adná, külön süti-hatókörrel és külön
 * OIDC-visszahívási címmel — felesleges és ellenőrizetlen felület. Ezért itt 404.
 */
final class RestrictLandingHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (strtolower($request->getHost()) === strtolower((string) config('app.landing_host'))
            && ! $request->is('/', 'robots.txt', 'up')) {
            abort(404);
        }

        return $next($request);
    }
}
