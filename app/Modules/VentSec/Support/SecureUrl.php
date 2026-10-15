<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Support;

/**
 * A ventSec-/ventSSO-végpontok https-kényszere. Titok (client secret, Bearer token, HMAC)
 * utazik rajtuk: plain HTTP csak lokális fejlesztésben oldható fel, ugyanazzal a kapcsolóval,
 * mint az OIDC-discovery (`OIDC_ALLOW_INSECURE`).
 */
final class SecureUrl
{
    public static function ok(string $url): bool
    {
        if (str_starts_with($url, 'https://')) {
            return true;
        }

        return str_starts_with($url, 'http://')
            && (bool) config('oidc.allow_insecure_transport')
            && app()->environment('local', 'testing');
    }
}
