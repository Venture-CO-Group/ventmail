<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A kérő VALÓDI címe — rate-limit kulcshoz és consent-bizonyítékhoz.
 *
 * A TCP-partner (`REMOTE_ADDR`) az alap: azt a kérő nem tudja hamisítani. Élesben viszont az
 * edge-proxy mögött a `REMOTE_ADDR` MINDEN kérésre az edge belső IP-je — arra kulcsolt fék
 * egyetlen globális vödör lenne (egy zajos kliens mindenkit kizárna). Ezért ha a partner a
 * SZŰKÍTETT `proxy.trusted_proxies` listán van, a továbbított kliens-IP számít; ezt a
 * fejlécet csak az edge írhatja, mert a listán kívüli partner fejlécét a Symfony eldobja.
 */
final class ClientAddress
{
    public static function of(Request $request): string
    {
        $remote = (string) ($request->server('REMOTE_ADDR') ?? 'unknown');

        // A `*` (fejlesztői) bizalom mellett a fejléc bárkié — ott marad a TCP-partner.
        if ((array) config('proxy.trusted_proxies', []) === ['*'] || ! $request->isFromTrustedProxy()) {
            return $remote;
        }

        return (string) ($request->getClientIp() ?? $remote);
    }
}
