<?php

declare(strict_types=1);

namespace App\Modules\Domains\Services;

use RuntimeException;

/**
 * DKIM kulcspár generálás és rotáció.
 *
 * A privát kulcs titkosítva tárolódik (`Domain::$casts`), sosem kerül válaszba, és az
 * auditba sem (`AuditLogger` redact-listája). A ROTÁCIÓ átfedéses: az előző selector és
 * kulcs megmarad, amíg a régi DNS-rekord ki nem fut — enélkül a rotáció pillanatában
 * minden útban lévő levél aláírása érvénytelenné válna.
 */
final class DkimKeyManager
{
    /** @return array{selector: string, private: string, public: string} */
    public function generate(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new RuntimeException('A DKIM kulcspár generálása nem sikerült.');
        }

        // Az export visszatérési értékét ELLENŐRIZNI kell: hibás openssl.cnf mellett
        // (tipikus alpine-hiba) különben ÜRES privát kulcs kerülne az adatbázisba, és a
        // hiba csak hónapokkal később, az első aláírásnál derülne ki.
        if (openssl_pkey_export($key, $privateKey) === false) {
            throw new RuntimeException('A DKIM privát kulcs exportja nem sikerült.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('A DKIM publikus kulcs kiolvasása nem sikerült.');
        }

        return [
            'selector' => $this->newSelector(),
            'private' => (string) $privateKey,
            'public' => $this->publicKeyForDns($details['key']),
        ];
    }

    /**
     * A selector dátumos, hogy a rotációk sorrendje a DNS-ben is látszódjon.
     *
     * A véletlen rész TELJES 8 bájt: a korábbi csonkolás 16 bitre vitte le, és ütközés
     * esetén az új rekord ugyanarra a DNS-névre került volna, mint a régi — az ügyfél
     * felülírta volna, és az átfedés némán elszakad.
     */
    private function newSelector(): string
    {
        return 'vm'.now()->format('ymd').bin2hex(random_bytes(8));
    }

    /**
     * A DNS-be a PEM-fejlécek nélküli, egysoros base64 megy.
     *
     * Regex-alapú, mert a korábbi `str_replace` csak az LF sorvégű SPKI-formára illeszkedett:
     * CRLF-es kimenetnél vagy PKCS#1 fejlécnél a fejléc BENT MARADT volna a DNS-értékben —
     * és mivel az összehasonlítás a whitespace-t kiszedi, „konzisztensen" hibás maradt volna.
     */
    public function publicKeyForDns(string $pem): string
    {
        $stripped = preg_replace('/-----(BEGIN|END)[A-Z ]*-----/', '', $pem);

        if ($stripped === null) {
            throw new RuntimeException('A PEM feldolgozása nem sikerült.');
        }

        $stripped = preg_replace('/\s+/', '', $stripped) ?? '';

        if ($stripped === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $stripped) !== 1) {
            throw new RuntimeException('A PEM nem tiszta base64 kulcsanyagot tartalmaz.');
        }

        if (base64_decode($stripped, strict: true) === false) {
            throw new RuntimeException('A PEM base64 tartalma érvénytelen.');
        }

        return $stripped;
    }
}
