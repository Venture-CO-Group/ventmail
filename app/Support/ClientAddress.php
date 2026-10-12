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

    /**
     * A rate-limit VÖDÖR: IPv4-en a cím, IPv6-on a /64. Egy IPv6-előfizető egy teljes
     * /64-et kap — /128-ra kulcsolva címenként friss keretet rotálhatna magának.
     * (A consent-bizonyítékhoz a teljes cím kell: arra az `of()`.)
     */
    public static function bucket(Request $request): string
    {
        $address = self::of($request);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }

        // IPv4-mapped (::ffff:a.b.c.d): a valódi IPv4 a vödör, különben mind `::/64` lenne.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
            return $mapped[1];
        }

        $packed = (string) inet_pton($address);

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
