<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * FÜGGETLEN DKIM-verifikáció a tesztekhez.
 *
 * A saját aláírásunkat a PUBLIKUS kulccsal, openssl-lel ellenőrzi, az RFC 6376
 * relaxed/relaxed kanonikalizálása szerint. Ez NEM az aláíró újraimplementálása (az
 * ugyanúgy hibázna, mint a tesztelt kód), hanem a FOGADÓ OLDAL szimulációja: ha a
 * kanonikalizálás rossz, a Gmail bukná — itt bukik előbb.
 *
 * Osztályban, nem globális függvényként: párhuzamos futásnál a globális teszt-helper csak
 * abban a processzben létezik, ahol a deklaráló fájl betöltődött — a másikban „undefined
 * function" lenne (pontosan ez történt).
 */
final class DkimVerifier
{
    public static function verify(string $mime, string $publicKeyPem): bool
    {
        [$headerBlock, $body] = preg_split('/\r?\n\r?\n/', $mime, 2) + ['', ''];

        // NÉVENKÉNT, előfordulási sorrendben: az `h=` listában ISMÉTELT név a fejléc egyre
        // korábbi példányára hivatkozik (RFC 6376 §5.4.2) — ez az over-signing alapja.
        $headers = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $headerBlock) as $line) {
            if ($current !== null && preg_match('/^[ \t]/', $line) === 1) {
                $headers[$current][count($headers[$current]) - 1] .= ' '.trim($line);

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current][] = trim($value);
        }

        $signature = $headers['dkim-signature'][count($headers['dkim-signature'] ?? []) - 1] ?? null;

        if ($signature === null) {
            return false;
        }

        $tags = [];

        foreach (explode(';', $signature) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', trim($pair), 2);
                $tags[trim($key)] = trim($value);
            }
        }

        // relaxed body: sorvégi whitespace le, több szóköz egybe, záró üres sorok le, CRLF vég.
        $canonicalBody = preg_replace('/[ \t]+/', ' ', str_replace("\r\n", "\n", $body));
        $canonicalBody = preg_replace('/[ \t]+\n/', "\n", (string) $canonicalBody);
        $canonicalBody = rtrim((string) $canonicalBody, "\n");
        $canonicalBody = $canonicalBody === '' ? '' : str_replace("\n", "\r\n", $canonicalBody)."\r\n";

        if (! hash_equals((string) ($tags['bh'] ?? ''), base64_encode(hash('sha256', $canonicalBody, true)))) {
            return false;
        }

        // relaxed header: kisbetűs név, whitespace normalizálva, az utolsó a `DKIM-Signature`
        // a `b=` értéke nélkül.
        $signedNames = array_map('strtolower', array_map('trim', explode(':', (string) ($tags['h'] ?? ''))));
        $canonicalHeaders = '';
        $used = [];

        foreach ($signedNames as $name) {
            $occurrences = $headers[$name] ?? [];
            $index = count($occurrences) - 1 - ($used[$name] ?? 0);
            $used[$name] = ($used[$name] ?? 0) + 1;

            $value = preg_replace('/[ \t]+/', ' ', trim((string) ($occurrences[$index] ?? '')));
            $canonicalHeaders .= $name.':'.$value."\r\n";
        }

        $withoutB = preg_replace('/\bb=[^;]*/', 'b=', $signature);
        $canonicalHeaders .= 'dkim-signature:'.preg_replace('/[ \t]+/', ' ', trim((string) $withoutB));

        return openssl_verify(
            $canonicalHeaders,
            (string) base64_decode(str_replace(' ', '', (string) ($tags['b'] ?? '')), true),
            $publicKeyPem,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }
}
