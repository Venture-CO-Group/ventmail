<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Transport;

/**
 * PROXY protocol fejléc (v1 szöveges és v2 bináris) — a VALÓDI forrás-IP.
 *
 * Miért kell (tulajdonosi döntés, ADR 0001 / 4. pont): TCP load balancer mögött minden
 * kapcsolat a balancer IP-jéről érkezik. A per-IP fékek (kapcsolat-limit, brute-force
 * számláló, tarpit) így EGYETLEN közös vödörré válnának: nem védenének senkit, viszont
 * egyetlen rossz szomszéd az összes ügyfelet kizárná.
 *
 * FAIL-CLOSED: ha a protokoll be van kapcsolva, a fejléc KÖTELEZŐ. Enélkül a kliens maga
 * dönthetné el, mikor küld ilyen fejlécet — és a hiánya (vagy a hamis tartalma) épp a
 * fékek kikapcsolását jelentené.
 */
final readonly class ProxyProtocolHeader
{
    private const V2_SIGNATURE = "\r\n\r\n\x00\r\nQUIT\n";

    private function __construct(
        public string $sourceIp,
        public int $consumedBytes,
        /**
         * `true`, ha a proxy SAJÁT kapcsolatáról van szó (health check).
         *
         * Ilyenkor nincs továbbított forrás — a socket peer-címe marad érvényes. Ezeket
         * eldobni azt jelentené, hogy a load balancer kiveszi a backendet a rotációból.
         */
        public bool $local = false,
    ) {}

    /**
     * Feldolgozza a puffer elejét.
     *
     * @return self|null `null`, ha még nem érkezett meg a teljes fejléc (várni kell)
     *
     * @throws InvalidProxyHeader ha a fejléc érvénytelen — ilyenkor bontani kell
     */
    public static function parse(string $buffer): ?self
    {
        // A TCP DARABOLHAT: a néhány bájtos kezdet még lehet érvényes fejléc eleje —
        // ilyenkor VÁRUNK, nem bontunk. (Korábban a `PRO` töredék legitim kapcsolatot
        // dobott el.)
        if (str_starts_with('PROXY ', substr($buffer, 0, 6))) {
            return strlen($buffer) >= 6 ? self::parseText($buffer) : null;
        }

        if (str_starts_with(self::V2_SIGNATURE, substr($buffer, 0, strlen(self::V2_SIGNATURE)))) {
            return self::parseBinary($buffer);
        }

        // Se nem v1, se nem v2 kezdet: ez nem PROXY protocol.
        throw new InvalidProxyHeader('Hiányzó PROXY protocol fejléc.');
    }

    /** v1: `PROXY TCP4 1.2.3.4 5.6.7.8 12345 587\r\n` */
    private static function parseText(string $buffer): ?self
    {
        $end = strpos($buffer, "\r\n");

        if ($end === false) {
            // Túl hosszú kezdet érvényes v1 fejléchez: nem várunk tovább.
            return strlen($buffer) > 107 ? throw new InvalidProxyHeader('Túl hosszú v1 fejléc.') : null;
        }

        $parts = explode(' ', substr($buffer, 0, $end));

        // `PROXY UNKNOWN` — a proxy nem tudja a forrást (health check is ilyen):
        // a socket peer-címe marad érvényes.
        if (($parts[1] ?? '') === 'UNKNOWN') {
            return new self('', $end + 2, local: true);
        }

        if (count($parts) !== 6 || ! in_array($parts[1], ['TCP4', 'TCP6'], true)) {
            throw new InvalidProxyHeader('Érvénytelen v1 PROXY fejléc.');
        }

        if (filter_var($parts[2], FILTER_VALIDATE_IP) === false) {
            throw new InvalidProxyHeader('Érvénytelen forrás-IP a PROXY fejlécben.');
        }

        return new self($parts[2], $end + 2);
    }

    /** v2: 12 bájt aláírás + 1 bájt verzió/parancs + 1 bájt család + 2 bájt hossz + cím. */
    private static function parseBinary(string $buffer): ?self
    {
        if (strlen($buffer) < 16) {
            return null;
        }

        if (! str_starts_with($buffer, self::V2_SIGNATURE)) {
            throw new InvalidProxyHeader('Érvénytelen v2 aláírás.');
        }

        $versionCommand = ord($buffer[12]);
        $family = ord($buffer[13]);
        $length = unpack('n', substr($buffer, 14, 2))[1] ?? 0;

        if (strlen($buffer) < 16 + $length) {
            return null;
        }

        // Felső 4 bit: verzió (2). Alsó 4 bit: parancs (0 = LOCAL, 1 = PROXY).
        if (($versionCommand >> 4) !== 2) {
            throw new InvalidProxyHeader('Nem támogatott PROXY protocol verzió.');
        }

        // LOCAL (health check): a kapcsolat a proxy sajátja, nincs továbbított forrás —
        // a socket peer-címe marad. Allowlist mögött ez biztonságos.
        if (($versionCommand & 0x0F) === 0) {
            return new self('', 16 + $length, local: true);
        }

        $address = substr($buffer, 16, $length);

        $ip = match ($family >> 4) {
            1 => inet_ntop(substr($address, 0, 4)),   // AF_INET
            2 => inet_ntop(substr($address, 0, 16)),  // AF_INET6
            default => false,
        };

        if ($ip === false) {
            throw new InvalidProxyHeader('Nem támogatott címcsalád a PROXY fejlécben.');
        }

        return new self($ip, 16 + $length);
    }
}
