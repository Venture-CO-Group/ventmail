<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HOSZTOLT, NÉVTELEN OLDALAK fejlécei (v1.1 · 5. modul): űrlap, köszönő oldal, megerősítés,
 * preference center.
 *
 * - **Szigorú CSP**: szkript egyáltalán nincs (`script-src 'none'`), stílus és betű csak a saját
 *   originről (a Vite-épített, token-alapú `hosted.css`), űrlap csak magunkhoz küldhet
 *   (`form-action 'self'`), `base-uri 'none'`, külső kép nincs.
 * - **Keretezés**: alapból tilos (`frame-ancestors 'none'`, clickjacking ellen); egyedül a
 *   hosztolt ÜRLAP keretezhető (`framable`), mert az iframe-es beágyazás a rendeltetése — a
 *   beküldés hatása ott is legfeljebb egy `pending` consent.
 * - **Süti nincs**: ezek az útvonalak állapotmentesek (a session- és CSRF-middleware le van
 *   véve róluk), és ha bármi mégis sütit tenne a válaszra, itt lekerül. A token (vagy az
 *   űrlap) adja a kontextust, nem egy session.
 * - `noindex`, `no-referrer` (a token az URL-ben van), `nosniff`, `no-store`.
 */
final class HostedPageHeaders
{
    public function handle(Request $request, Closure $next, string $mode = 'strict'): Response
    {
        $response = $next($request);

        $frames = $mode === 'framable' ? '*' : "'none'";

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'none'",
            "script-src 'none'",
            "style-src 'self'",
            "font-src 'self'",
            "img-src 'self' data:",
            "form-action 'self'",
            "base-uri 'none'",
            "frame-ancestors {$frames}",
        ]));

        if ($mode !== 'framable') {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }
}
