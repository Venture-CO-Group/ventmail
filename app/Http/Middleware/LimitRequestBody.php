<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * KÉRÉS-TÖRZS PLAFON útvonalanként — a Caddy `request_body` korlátjának alkalmazás-szintű párja.
 *
 * A kontakt-import feltöltése nagy törzset kér (php.ini: 128M/132M, Caddy: 140MB), ezeket a PHP
 * viszont GLOBÁLISAN alkalmazza. Ezért a `Content-Length`-et minden MÁS útvonalon a kisebb
 * plafonhoz (alap 16 MB) mérjük, a törzs feldolgozása ELŐTT — egy nyilvános űrlap vagy a `/v1`
 * így nem fogadhat 132 MB-ot. A valódi határ a Caddy (útvonal-matcherrel); ez a védvonal akkor
 * is tart, ha valaki megkerüli (dev-szerver, közvetlen FPM).
 *
 * Miért nem `PHP_VALUE` az útvonalra: a php_fastcgi `env` kiegészítés többsoros ini-értéke a
 * Caddyfile-ban törékeny, és az eredmény a PHP-ban nem tesztelhető; ez a middleware igen.
 */
final class LimitRequestBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $length = (int) $request->server('CONTENT_LENGTH', 0);

        if ($length > (int) config('app.max_body_bytes') && ! $request->is('audience/import', 'audience/import/*')) {
            abort(413);
        }

        return $next($request);
    }
}
