<?php

declare(strict_types=1);

namespace App\Modules\Domains\Services;

use App\Modules\Domains\Models\Domain;
use RuntimeException;

/**
 * A DNS-rekordok MECHANIZMUS-szintű egyeztetése.
 *
 * Miért nem sztring-azonosság: azzal a helyesen beállított ügyfél SEM tudna küldeni.
 * - SPF: az RFC 7208 tiltja a második SPF TXT-t, tehát aki már használ mást (Google, M365),
 *   nem felvenni fogja a mi rekordunkat, hanem BŐVÍTI a sajátját az `include:`-unkkal.
 * - DMARC: aki `p=reject`-et használ, SZIGORÚBB nálunk — azt elfogadni kell, nem elutasítani.
 * - DKIM: a 2048 bites kulcs TXT-értéke ~390 karakter, amit a DNS 255-ös darabokra bont;
 *   a darabokat össze kell fűzni, mielőtt bármit hasonlítunk.
 */
final class DnsRecordMatcher
{
    /** A DMARC-házirendek szigorúsági sorrendje. */
    private const DMARC_STRICTNESS = ['none' => 0, 'quarantine' => 1, 'reject' => 2];

    /** @param list<string> $found */
    public function spfMatches(array $found): bool
    {
        $spfRecords = array_values(array_filter(
            array_map(fn (string $value) => strtolower($this->normalize($value)), $found),
            static fn (string $value) => str_starts_with($value, 'v=spf1')
        ));

        // Az RFC 7208 szerint EGY SPF rekord lehet: kettő permerror, azaz a domain
        // valójában hitelesítetlen — ilyenkor nem mondhatjuk, hogy rendben van.
        if (count($spfRecords) !== 1) {
            return false;
        }

        return str_contains($spfRecords[0], strtolower($this->normalize('include:'.config('domains.spf_include'))));
    }

    /** @param list<string> $found */
    public function dmarcMatches(array $found): bool
    {
        $required = self::DMARC_STRICTNESS[(string) config('domains.dmarc_policy')] ?? 0;

        $dmarcRecords = array_values(array_filter(
            array_map(fn (string $value) => strtolower($this->normalize($value)), $found),
            static fn (string $value) => str_starts_with($value, 'v=dmarc1')
        ));

        // Két DMARC rekord esetén a fogadó oldalak egyiket sem alkalmazzák.
        if (count($dmarcRecords) !== 1) {
            return false;
        }

        preg_match('/p=([a-z]+)/', $dmarcRecords[0], $matches);
        $policy = self::DMARC_STRICTNESS[$matches[1] ?? ''] ?? null;

        // A SZIGORÚBB házirend is megfelel: a `p=reject` többet véd, mint a `p=none`.
        // Ellenkező esetben a wizard arra kényszerítené az ügyfelet, hogy GYENGÍTSE
        // a DMARC-ját — ökoszisztéma-szintű visszalépés.
        return $policy !== null && $policy >= $required;
    }

    /**
     * A DKIM-kulcs egyezése. Rotáció alatt az ELŐZŐ selector is elfogadott — enélkül a
     * rotáció kiesést okozna, nem átfedést.
     *
     * @param  list<string>  $found
     */
    public function dkimMatches(array $found, string $expectedPublicKey): bool
    {
        if ($found === [] || trim($expectedPublicKey) === '') {
            return false;
        }

        // A DNS 255-ös darabokban szállít: előbb összefűzünk, aztán vizsgálunk.
        $joined = $this->normalize(implode('', $found));

        // A TAG-NEVEK kisbetűsen hasonlíthatók...
        if (! str_contains(strtolower($joined), 'v=dkim1')) {
            return false;
        }

        preg_match('/p=([A-Za-z0-9+\/=]+)/', $joined, $matches);

        if (! isset($matches[1])) {
            return false;
        }

        // ...de a `p=` ÉRTÉKE base64, tehát case-SENSITIVE: a kisbetűsítés két eltérő
        // bájtsorozatot tenne egyenlővé.
        return hash_equals($this->normalize($expectedPublicKey), $matches[1]);
    }

    /** @param list<string> $found */
    public function cnameMatches(array $found, string $expected): bool
    {
        $target = rtrim($this->normalize($expected), '.');

        foreach ($found as $value) {
            if (rtrim($this->normalize($value), '.') === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fogadó oldalak szóközre és idézőjelre érzéketlenül olvassák a TXT-t.
     *
     * A BETŰMÉRET MEGMARAD: a base64 kulcsanyag case-sensitive. És ha a `preg_replace`
     * hibára fut (PCRE-limit, érvénytelen bájtsorozat), NEM üres sztringet adunk vissza —
     * az `'' === ''` fail-open egyezés lenne, azaz hitelesítés-megkerülés.
     */
    private function normalize(string $value): string
    {
        $normalized = preg_replace('/\s+/', '', str_replace(['"', "'"], '', $value));

        if ($normalized === null) {
            throw new RuntimeException('A DNS-rekord értéke nem normalizálható.');
        }

        return $normalized;
    }

    public function expectedDkimKeys(Domain $domain): array
    {
        $keys = [$domain->dkim_public_key];

        if ($domain->previousDkimIsValid() && $domain->dkim_previous_public_key !== null) {
            $keys[] = $domain->dkim_previous_public_key;
        }

        return array_values(array_filter($keys));
    }
}
