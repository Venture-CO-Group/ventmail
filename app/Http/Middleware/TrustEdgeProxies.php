<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Csak az edge-proxy továbbított fejléceinek hiszünk (`config/proxy.php`).
 *
 * A `Prefix` és a `Port` SZÁNDÉKOSAN nincs a listán: az edge sosem állítja őket, tehát ha
 * mégis megjönnek, azt a kliens írta — és egy `X-Forwarded-Prefix: @evil.example` az
 * abszolút URL-eket (OIDC redirect, leiratkozó link) idegen hostra fordítaná.
 */
final class TrustEdgeProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies()
    {
        $proxies = (array) config('proxy.trusted_proxies', []);

        // `*` csak fejlesztésben értelmes; a Laravel sztringként várja.
        if ($proxies === ['*']) {
            return '*';
        }

        return $proxies !== [] ? $proxies : null;
    }
}
