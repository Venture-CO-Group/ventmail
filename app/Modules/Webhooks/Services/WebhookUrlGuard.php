<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Support\ApprovedTarget;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A webhook-URL ELLENŐRZÉSE — SSRF ellen.
 *
 * A cél-URL-t az ÜGYFÉL adja meg, a kérést viszont MI indítjuk, a saját hálózatunkból.
 * Védelem nélkül ez egy kényelmes belső szkenner: `https://169.254.169.254/…` (felhő
 * metadata, benne a példány hitelesítője), `https://127.0.0.1:5432` (a saját Postgresünk),
 * `https://10.0.0.0/8` (a belső háló) — és a válasz egy részlete még vissza is jut hozzá a
 * kézbesítési naplóban.
 *
 * Öt zár:
 *  1. CSAK `https` — a `file://`, `gopher://`, `redis://` sémák eleve kizárva, és a
 *     titkos aláírás sem utazhat titkosítatlan csatornán;
 *  2. a HOSZTNÉV feloldva, és MINDEN kapott cím ellenőrizve (a DNS több A-rekordot adhat,
 *     amiből csak az egyik publikus);
 *  3. a cím KANONIZÁLVA, mielőtt tartományhoz mérnénk (lásd `candidates()`);
 *  4. privát, loopback, link-local és fenntartott tartomány TILTVA;
 *  5. a kérés az ELLENŐRZÖTT IP-re megy (`ApprovedTarget`), és nem követ átirányítást.
 */
final class WebhookUrlGuard
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // „ez a hálózat"
        '10.0.0.0/8',         // privát
        '100.64.0.0/10',      // CGNAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local — felhő-metadata!
        '172.16.0.0/12',      // privát
        '192.0.0.0/24',       // IETF protokoll-hozzárendelések
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // privát
        '198.18.0.0/15',      // benchmark
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // fenntartott (benne a 255.255.255.255 broadcast)
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified — a stack a loopbackra köti
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped — a beágyazott címet külön is megnézzük
        '64:ff9b::/96',       // NAT64
        '64:ff9b:1::/48',     // helyi NAT64
        '100::/64',           // discard
        '2001::/32',          // Teredo
        '2002::/16',          // 6to4
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Jóváhagyás — és a jóváhagyott CÍM visszaadása, hogy a kérés arra mehessen.
     *
     * @throws InvalidArgumentException ha az URL nem használható
     * @throws RuntimeException ha a hosztnév most nem oldható fel (átmeneti hiba)
     */
    public function approve(string $url): ApprovedTarget
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidArgumentException('A webhook-URL csak https lehet.');
        }

        $host = (string) ($parts['host'] ?? '');

        if ($host === '') {
            throw new InvalidArgumentException('A webhook-URL hosztnév nélkül nem használható.');
        }

        $addresses = $this->resolve($host);

        foreach ($addresses as $ip) {
            if ($this->isBlocked($ip)) {
                // A hibaüzenet SZÁNDÉKOSAN nem árulja el a feloldott címet: az önmagában
                // belső hálózati felderítés lenne.
                throw new InvalidArgumentException('A webhook-URL nem publikus címre mutat.');
            }
        }

        return new ApprovedTarget(
            url: $url,
            host: trim($host, '[]'),
            port: (int) ($parts['port'] ?? 443),
            // Az ELSŐ cím, de csak azért, mert MIND átment: ha bármelyik blokkolt lenne,
            // már nem tartanánk itt.
            ip: $addresses[0],
        );
    }

    /** @throws InvalidArgumentException */
    public function assertAllowed(string $url): void
    {
        $this->approve($url);
    }

    public function allows(string $url): bool
    {
        try {
            $this->approve($url);

            return true;
        } catch (InvalidArgumentException|RuntimeException) {
            return false;
        }
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $literal = $this->asLiteral($host);

        if ($literal !== null) {
            return [$literal];
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            // Ez NEM SSRF-elutasítás, hanem átmeneti feloldási hiba: a hívó másképp
            // kezeli (újrapróbálás, nem dead-letter).
            throw new RuntimeException('A webhook-URL hosztneve nem oldható fel.');
        }

        return array_values($addresses);
    }

    /**
     * A hoszt címliterálként — a „ravasz" alakokat is beleértve.
     *
     * A `http://2130706433/` és a `http://0x7f.0.0.1/` ugyanúgy a loopbackra megy, mint a
     * `127.0.0.1`; a legtöbb feloldó elfogadja őket. Ha csak a `filter_var`-ra bíznánk
     * magunkat, ezek „hosztnévként" a DNS-ig jutnának, és a védelmünk a feloldó
     * jóindulatán múlna.
     */
    private function asLiteral(string $host): ?string
    {
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return $bare;
        }

        return $this->numericV4($bare);
    }

    /** Decimális, hexa és oktális IPv4-alakok kanonizálása pontozott alakra. */
    private function numericV4(string $host): ?string
    {
        $parts = explode('.', $host);

        if ($parts === [] || count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if ($part === '') {
                return null;
            }

            if (preg_match('/^0[xX][0-9a-fA-F]+$/', $part) === 1) {
                $values[] = (int) hexdec(substr($part, 2));
            } elseif (preg_match('/^0[0-7]+$/', $part) === 1) {
                $values[] = (int) octdec(substr($part, 1));
            } elseif (ctype_digit($part)) {
                $values[] = (int) $part;
            } else {
                return null;
            }
        }

        // Az utolsó szám „belelóg" a maradék oktettekbe: `127.1` = 127.0.0.1.
        $last = array_pop($values);
        $missing = 4 - count($values);

        if ($last < 0 || $last >= 2 ** (8 * $missing)) {
            return null;
        }

        foreach ($values as $value) {
            if ($value > 255) {
                return null;
            }
        }

        for ($i = $missing - 1; $i >= 0; $i--) {
            $values[] = ($last >> (8 * $i)) & 0xFF;
        }

        return implode('.', $values);
    }

    private function isBlocked(string $ip): bool
    {
        foreach ($this->candidates($ip) as $candidate) {
            $ranges = str_contains($candidate, ':') ? self::BLOCKED_V6 : self::BLOCKED_V4;

            foreach ($ranges as $range) {
                if (IpUtils::checkIp($candidate, $range)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A cím MINDEN alakja, amit meg kell nézni.
     *
     * Az `IpUtils::checkIp()` a `:`-ot tartalmazó címet IPv6-ként méri, és NEM vetíti vissza
     * IPv4-re — mérve: `checkIp('::ffff:169.254.169.254', '169.254.0.0/16') === false`.
     * Vagyis a `https://[::ffff:169.254.169.254]/` a puszta tartománylistán átcsúszna, és a
     * felhő-metadata (benne a példány hitelesítője) kimenne az ügyfél végpontjára.
     *
     * Ezért minden beágyazott IPv4-et kibontunk, és a v4 tartományokhoz IS mérjük:
     * IPv4-mapped (`::ffff:a.b.c.d`), IPv4-compatible (`::a.b.c.d`), NAT64 (`64:ff9b::/96`)
     * és 6to4 (`2002:aabb:ccdd::`).
     *
     * @return list<string>
     */
    private function candidates(string $ip): array
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return [$ip];
        }

        $bytes = array_values(unpack('C16', $packed));
        $embedded = null;

        $prefixZero = array_slice($bytes, 0, 10) === array_fill(0, 10, 0);

        if ($prefixZero && $bytes[10] === 0xFF && $bytes[11] === 0xFF) {
            $embedded = array_slice($bytes, 12, 4);          // ::ffff:a.b.c.d
        } elseif ($prefixZero && $bytes[10] === 0 && $bytes[11] === 0) {
            $embedded = array_slice($bytes, 12, 4);          // ::a.b.c.d (és `::` → 0.0.0.0)
        } elseif ($bytes[0] === 0x00 && $bytes[1] === 0x64 && $bytes[2] === 0xFF && $bytes[3] === 0x9B) {
            $embedded = array_slice($bytes, 12, 4);          // NAT64
        } elseif ($bytes[0] === 0x20 && $bytes[1] === 0x02) {
            $embedded = array_slice($bytes, 2, 4);           // 6to4
        }

        return $embedded === null ? [$ip] : [$ip, implode('.', $embedded)];
    }
}
