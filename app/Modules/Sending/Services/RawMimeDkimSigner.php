<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Domains\Models\Domain;
use RuntimeException;

/**
 * DKIM-aláírás NYERS MIME fölött (RFC 6376, rsa-sha256, relaxed/relaxed).
 *
 * Miért kell külön: az SMTP-n (és a `/v1/messages.mime`-on) beadott levelet a FELADÓ
 * állította össze — azt nem építjük újra, mert bármilyen újraszerkesztés megváltoztatná a
 * tartalmát. A Symfony aláírója viszont csak `Email` objektumot tud aláírni. Enélkül az
 * SMTP-úton beadott levél ALÁÍRÁS NÉLKÜL ment volna ki: a fogadó nem tudná megkülönböztetni
 * a hamisítványtól, és a DMARC-politika elutasítaná (golden rule 8, 21. kritérium).
 *
 * A KANONIKALIZÁLÁS a kockázatos rész — pontosan ott hibázna, ahol nem látjuk, a fogadó
 * oldalán. Ezért három egymástól független bizonyíték őrzi:
 *  1. RFC 6376 §3.4.5 KAT-vektor a fejléc- és törzs-kanonikalizálásra;
 *  2. KERESZT-VERIFIKÁCIÓ: a saját `verify()`-unk elfogadja a Symfony (harmadik felű,
 *     bejáratott) aláíró levelét — ha bárhol eltérnénk a szabványtól, ez elbukna;
 *  3. független openssl-verifikáció + tamper-teszt.
 */
final class RawMimeDkimSigner
{
    /**
     * Ezek a fejlécek utaznak aláírva — a `From` a feladó identitása.
     *
     * A `from` KÉTSZER szerepel („over-signing"): így ha bárki útközben MÁSODIK `From`
     * fejlécet szúr be, az aláírás érvénytelenné válik. Enélkül a verifikáció az alsó
     * (aláírt) `From`-ra épülne, több levélkliens viszont a felső, hamisítottat mutatja —
     * ez a klasszikus DKIM/DMARC From-hasítás.
     */
    private const SIGNED_HEADERS = ['from', 'from', 'to', 'cc', 'subject', 'date', 'message-id', 'mime-version'];

    public function sign(string $rawMime, Domain $domain, ?int $timestamp = null): string
    {
        $selector = $domain->signingSelector();
        $privateKey = $selector === $domain->dkim_previous_selector
            ? $domain->dkim_previous_private_key
            : $domain->dkim_private_key;

        if ($selector === null || $privateKey === null || $privateKey === '') {
            throw new RuntimeException(sprintf(
                'A(z) %s domainhez nincs DKIM-kulcs — aláíratlan levelet nem küldünk ki (golden rule 8).',
                $domain->name
            ));
        }

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new RuntimeException('A DKIM privát kulcs nem olvasható.');
        }

        [$headerBlock, $body] = $this->split($rawMime);
        $headers = $this->parseHeaders($headerBlock);

        // RFC 6376 §5.4: a `From` aláírása KÖTELEZŐ. Enélkül az aláírás ÁTVIHETŐ lenne:
        // a támadó a mi érvényes aláírásunk mellé beszúrna egy saját `From`-ot, és a levél
        // idegen feladóval, a mi domainünk hitelességével jelenne meg.
        if (! isset($headers['from'])) {
            throw new RuntimeException('A levélből hiányzik a `From` fejléc — aláíratlanul nem küldünk ki.');
        }

        // A tag-értékek nem tartalmazhatnak elválasztót: enélkül egy „domain" nevű érték
        // további tageket csempészhetne az aláírás-fejlécbe.
        foreach (['domain' => (string) $domain->name, 'selector' => $selector] as $label => $value) {
            if (preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
                throw new RuntimeException(sprintf('Érvénytelen DKIM-%s: %s', $label, $value));
            }
        }

        $bodyHash = base64_encode(hash('sha256', $this->canonicalizeBody($body), true));

        // Csak azokat a fejléceket soroljuk fel, amelyek TÉNYLEG jelen vannak: egy nem
        // létező fejléc aláírása („oversigning") itt csak zajt adna.
        $signed = [];

        foreach (self::SIGNED_HEADERS as $name) {
            // Az over-signelt (kétszer felsorolt) fejléc MÁSODIK példánya szándékosan
            // hiányzó fejlécre hivatkozik: az RFC 6376 §5.4.2 szerint ilyenkor üres
            // sztringet kell kanonikalizálni — pont ettől bukik meg a beszúrás.
            if (isset($headers[$name]) || in_array($name, $signed, true)) {
                $signed[] = $name;
            }
        }

        $signature = sprintf(
            'v=1; a=rsa-sha256; c=relaxed/relaxed; d=%s; s=%s; t=%d; h=%s; bh=%s; b=',
            $domain->name,
            $selector,
            $timestamp ?? now()->getTimestamp(),
            implode(': ', $signed),
            $bodyHash
        );

        $toSign = '';

        $used = [];

        foreach ($signed as $name) {
            $index = $used[$name] ?? 0;
            $used[$name] = $index + 1;

            $value = $this->occurrence($headers, $name, $index);

            // RFC 6376 §3.5: a NEM LÉTEZŐ fejléc „null input" — vagyis SEMMIT nem ad a
            // hash-bemenethez, még a nevet és a kettőspontot sem. Egy `from:\r\n`
            // fantomsor mellett minden aláírásunk `dkim=fail` lenne a fogadóknál (az
            // over-signing miatt MINDIG van hiányzó második `from`) — a levél a saját
            // domainünkről érkezne hamisítványként.
            if ($value === null) {
                continue;
            }

            $toSign .= $this->canonicalizeHeader($name, $value);
        }

        // Az aláírás-fejléc MAGA is aláírva van, `b=` nélkül és záró CRLF nélkül —
        // enélkül a `d=`/`s=`/`h=` mezők átírhatók lennének.
        $toSign .= $this->canonicalizeHeader('dkim-signature', $signature, terminate: false);

        if (! openssl_sign($toSign, $binary, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('A DKIM-aláírás nem készült el.');
        }

        return 'DKIM-Signature: '.$signature.base64_encode($binary)."\r\n".$rawMime;
    }

    /**
     * Egy aláírt levél ellenőrzése a PUBLIKUS kulccsal.
     *
     * Ugyanazt a kanonikalizálást használja, mint az aláírás — ezért alkalmas
     * KERESZT-VERIFIKÁCIÓRA: ha egy harmadik felű (Symfony) aláíró levelét is elfogadja,
     * akkor a kanonikalizálásunk egyezik a bejáratott implementációéval. Az A4 bejövő
     * oldalán ez lesz a DKIM-ellenőrzés alapja is.
     */
    public function verify(string $signedMime, string $publicKeyPem): bool
    {
        [$headerBlock, $body] = $this->split($signedMime);
        $headers = $this->parseHeaders($headerBlock);

        $signature = $this->occurrence($headers, 'dkim-signature', 0);

        if ($signature === null) {
            return false;
        }

        $tags = [];

        foreach (explode(';', $signature) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', trim($pair), 2);
                $tags[trim($key)] = str_replace(' ', '', trim($value));
            }
        }

        // A `from` nélküli `h=` lista ELUTASÍTVA: különben egy aláírás átvihető lenne egy
        // másik feladóra (RFC 6376 §5.4). Az A4 bejövő ellenőrzésénél ez már közvetlen
        // sebezhetőség lenne.
        $signedNames = array_map('trim', array_map('strtolower', explode(':', $tags['h'] ?? '')));

        if (! in_array('from', $signedNames, true)) {
            return false;
        }

        // Csak azt fogadjuk el, amit aláírunk: RSA + SHA-256, 1-es verzió.
        if (($tags['v'] ?? '1') !== '1' || ($tags['a'] ?? '') !== 'rsa-sha256') {
            return false;
        }

        // Lejárt aláírás (`x=`) nem érvényes.
        if (isset($tags['x']) && ctype_digit($tags['x']) && (int) $tags['x'] < now()->getTimestamp()) {
            return false;
        }

        $expectedBodyHash = base64_encode(hash('sha256', $this->canonicalizeBody($body), true));

        if (! hash_equals($tags['bh'] ?? '', $expectedBodyHash)) {
            return false;
        }

        $toVerify = '';

        $used = [];

        foreach ($signedNames as $name) {

            if ($name === '') {
                continue;
            }

            $index = $used[$name] ?? 0;
            $used[$name] = $index + 1;

            $value = $this->occurrence($headers, $name, $index);

            // Ugyanaz a §3.5 szabály: a hiányzó előfordulás kimarad a bemenetből.
            if ($value === null) {
                continue;
            }

            $toVerify .= $this->canonicalizeHeader($name, $value);
        }

        $toVerify .= $this->canonicalizeHeader(
            'dkim-signature',
            (string) preg_replace('/\bb=[^;]*/', 'b=', $signature),
            terminate: false
        );

        return openssl_verify(
            $toVerify,
            (string) base64_decode($tags['b'] ?? '', true),
            $publicKeyPem,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $raw): array
    {
        if (preg_match('/\r?\n\r?\n/', $raw, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $match[0][1]), substr($raw, $match[0][1] + strlen($match[0][0]))];
    }

    /**
     * A fejlécek NÉVENKÉNT, ELŐFORDULÁSI SORRENDBEN.
     *
     * A duplikáció itt nem hiba, hanem adat: az RFC 6376 §5.4.2 szerint az `h=` listában
     * ismételt név a fejléc EGYRE KORÁBBI példányára hivatkozik (alulról felfelé). Ha
     * csak az utolsót tartanánk meg, egy beszúrt második `From` észrevétlen maradna.
     *
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $block): array
    {
        $headers = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if ($current !== null && preg_match('/^[ \t]/', $line) === 1) {
                // A folytatósor a kanonikalizálásban EGYETLEN szóközzé válik.
                $headers[$current][count($headers[$current]) - 1] .= ' '.ltrim($line);

                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current][] = $value;
        }

        return $headers;
    }

    /**
     * A név n. előfordulása ALULRÓL (0 = az utolsó).
     *
     * @param  array<string, list<string>>  $headers
     */
    private function occurrence(array $headers, string $name, int $fromBottom): ?string
    {
        $values = $headers[$name] ?? [];
        $index = count($values) - 1 - $fromBottom;

        return $index >= 0 ? $values[$index] : null;
    }

    /** RFC 6376 §3.4.2 — relaxed fejléc-kanonikalizálás. */
    private function canonicalizeHeader(string $name, string $value, bool $terminate = true): string
    {
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return strtolower($name).':'.trim($value).($terminate ? "\r\n" : '');
    }

    /** RFC 6376 §3.4.4 — relaxed törzs-kanonikalizálás. */
    private function canonicalizeBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);

        // Sorvégi whitespace le, a soron belüli whitespace-futamok egy szóközzé.
        $lines = array_map(
            static fn (string $line) => rtrim((string) preg_replace('/[ \t]+/', ' ', $line), " \t"),
            explode("\n", $body)
        );

        $canonical = rtrim(implode("\n", $lines), "\n");

        // Üres törzs esetén a kanonikus alak is üres; egyébként PONTOSAN egy záró CRLF.
        return $canonical === '' ? '' : str_replace("\n", "\r\n", $canonical)."\r\n";
    }
}
