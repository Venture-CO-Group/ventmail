<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

/**
 * SZINTAXIS-ellenőrzés (39. kritérium, 1. ellenőrzés).
 *
 * A `filter_var(FILTER_VALIDATE_EMAIL)` NEM elég önmagában: elfogadja a `a@b`-t (pont nélküli
 * domaint), és nem tud az RFC 5321 hosszkorlátairól. A hossz nem formaság — egy 320
 * karakternél hosszabb címet a fogadók egy része némán elvágja, és akkor MÁS címre kézbesít,
 * mint amit mi látunk.
 *
 * A NEMZETKÖZI címet (RFC 6531) elfogadjuk: a domaint punycode-ra hozzuk, a helyi részt
 * érintetlenül hagyjuk — azt kizárólag a fogadó értelmezheti (RFC 5321 §2.4).
 */
final class EmailSyntax
{
    /** RFC 5321 §4.5.3.1: a teljes path legfeljebb 254 oktett a borítékban. */
    public const MAX_LENGTH = 254;

    public const MAX_LOCAL = 64;

    public const MAX_DOMAIN = 253;

    public function valid(string $email): bool
    {
        return $this->reason($email) === null;
    }

    /** @return string|null a hiba oka, vagy `null` ha rendben */
    public function reason(string $email): ?string
    {
        $email = trim($email);

        if ($email === '') {
            return 'üres cím';
        }

        if (strlen($email) > self::MAX_LENGTH) {
            return 'a cím hosszabb, mint '.self::MAX_LENGTH.' oktett';
        }

        // Vezérlőkarakter sehol: a fogadók egy része elvágja, és MÁS címre kézbesít.
        if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1) {
            return 'vezérlőkarakter a címben';
        }

        $at = strrpos($email, '@');

        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return 'hiányzó vagy rossz helyen álló @';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if (strlen($local) > self::MAX_LOCAL) {
            return 'a helyi rész hosszabb, mint '.self::MAX_LOCAL.' oktett';
        }

        if (strlen($domain) > self::MAX_DOMAIN) {
            return 'a domain hosszabb, mint '.self::MAX_DOMAIN.' oktett';
        }

        // Az idézett helyi rész (`"a b"@x.hu`) érvényes, de a gyakorlatban a fogadók fele
        // elrontja. Elfogadjuk, de a szóköz idézőjel nélkül hiba.
        //
        // A `@` IS tiltott az idézetlen helyi részben. Enélkül az `a@@b.hu` átment: a
        // `strrpos` az UTOLSÓ `@`-nál vág, tehát a helyi rész `a@` lett — egy olyan cím,
        // amit egyetlen fogadó sem kézbesít, mi mégis „érvényesnek" mondtuk.
        if (! str_starts_with($local, '"') && preg_match('/[\s,:;<>@\\\\]/', $local) === 1) {
            return 'tiltott karakter a helyi részben';
        }

        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return 'pont a helyi rész elején, végén vagy duplán';
        }

        return $this->domainReason($domain);
    }

    private function domainReason(string $domain): ?string
    {
        // A ZÁRÓJELES cím-literál (`[192.0.2.1]`) érvényes, de marketinglistán mindig hiba.
        if (str_starts_with($domain, '[')) {
            return 'cím-literál domain helyett';
        }

        if (! str_contains($domain, '.')) {
            // A `filter_var` ezt ELFOGADJA. Egy pont nélküli domain viszont csak belső
            // hálózaton létezik — nyilvános levélben sosem kézbesíthető.
            return 'a domainben nincs pont';
        }

        if (str_starts_with($domain, '.') || str_ends_with($domain, '.') || str_contains($domain, '..')) {
            return 'pont a domain elején, végén vagy duplán';
        }

        foreach (explode('.', $domain) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return 'érvénytelen hosszúságú címke a domainben';
            }

            // KARAKTEROSZTÁLY: betű, szám, kötőjel — és semmi más (LDH-szabály, RFC 1035).
            //
            // Enélkül a `filter_var`-nál is gyengébbek voltunk: mérve átment az `a@pel da.hu`,
            // az `a@pelda.hu>`, az `a@pelda.hu:25` és az `a@pelda.hu;drop`. Az ilyen cím
            // `deliverable` minősítést kapott, felkerült az ügyfél listájára, és a szonda
            // `RCPT TO:<a@pel da.hu>` alakban küldte ki egy IDEGEN szerverre — szintaktikailag
            // hibás SMTP-parancs a mi IP-nkről, ami a fogadó abúzus-heurisztikájában számít.
            //
            // A nemzetközi domain már punycode-ban van (a hívó normalizál), tehát ASCII.
            if (preg_match('/^[A-Za-z0-9-]+$/', $label) !== 1) {
                return 'tiltott karakter a domainben';
            }

            if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return 'kötőjel a címke elején vagy végén';
            }
        }

        // A legfelső szintű címke nem lehet csupa szám (az `1.2.3.4` nem domain).
        $tld = (string) array_slice(explode('.', $domain), -1)[0];

        if (ctype_digit($tld)) {
            return 'numerikus legfelső szintű címke';
        }

        return null;
    }
}
