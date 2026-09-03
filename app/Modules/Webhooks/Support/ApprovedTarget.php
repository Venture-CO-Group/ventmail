<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Support;

/**
 * Egy JÓVÁHAGYOTT webhook-cél: a hosztnév ÉS az a konkrét IP, amit a guard ellenőrzött.
 *
 * A kettő együtt kell. Ha csak a hosztnevet adnánk tovább a HTTP-kliensnek, az ÚJRA
 * feloldaná — és a DNS-rebinding pontosan erre a második feloldásra épül: az első válasz
 * publikus cím (a guard átengedi), a második `127.0.0.1` (a kérés befelé megy). A cURL
 * `CURLOPT_RESOLVE`-ja épp ezt a második feloldást iktatja ki: a kapcsolat az ellenőrzött
 * címre épül, a `Host` fejléc és a TLS-SNI viszont marad a hosztnév — tehát a tanúsítvány
 * ellenőrzése is a valódi néven történik.
 */
final readonly class ApprovedTarget
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /** A cURL `host:port:ip` alakja. */
    public function resolveEntry(): string
    {
        return $this->host.':'.$this->port.':'.$this->ip;
    }

    /** @return array<string, mixed> */
    public function curlOptions(): array
    {
        return [
            CURLOPT_RESOLVE => [$this->resolveEntry()],
            // PROXY KIKAPCSOLVA. A `http_proxy`/`https_proxy` környezeti változó jelenlétében
            // a cURL a proxyhoz csatlakozna, a `CURLOPT_RESOLVE` pedig figyelmen kívül
            // maradna — a nevet a PROXY oldaná fel, és a rebinding-ablak visszanyílna. Ez ma
            // nem élő rés (nincs proxy-env), de egy ops-változás egyetlen sor.
            CURLOPT_PROXY => '',
        ];
    }
}
