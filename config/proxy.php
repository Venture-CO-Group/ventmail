<?php

declare(strict_types=1);

/*
 | Az edge-proxy bizalmi határa (deploy-ovh 3.).
 |
 | `trusted_proxies`: CSAK ezektől fogadjuk el az `X-Forwarded-*` fejlécet. ALAPBÓL ÜRES —
 | senkinek nem hiszünk. Élesben az edge Caddy fix belső IP-je (`/32`), fejlesztésben a
 | `.env` adhat `*`-ot. A `*` élesben azt jelentené, hogy bárki tetszőleges kliens-IP-t
 | állíthat magáról: a rate-limitek kulcsa és a consent-bizonyíték is hamisítható lenne.
 |
 | `trusted_hosts`: élesben csak ezekre a hostokra szolgálunk ki (Host-fejléc injekció
 | ellen). Alapból az `APP_URL` hostja; az api-host és később az ügyfél-tracking hostok
 | ide kerülnek.
 */

// Vesszővel VAGY szóközzel elválasztva: a web-réteg Caddy-ja (`trusted_proxies static …`)
// szóközzel várja, ugyanabból az env-ből.
$list = static fn (string $value): array => array_values(array_filter(preg_split('/[\s,]+/', trim($value)) ?: []));

$hosts = $list((string) env('TRUSTED_HOSTS', ''));

return [
    'trusted_proxies' => $list((string) env('TRUSTED_PROXIES', '')),
    'trusted_hosts' => $hosts !== [] ? $hosts : array_values(array_filter([parse_url((string) env('APP_URL', ''), PHP_URL_HOST)])),
];
