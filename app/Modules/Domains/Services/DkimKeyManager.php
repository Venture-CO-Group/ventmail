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

        openssl_pkey_export($key, $privateKey);

        $details = openssl_pkey_get_details($key);

        return [
            // A selector dátumos, hogy a rotációk sorrendje a DNS-ben is látszódjon.
            'selector' => 'vm'.now()->format('ymd').substr(bin2hex(random_bytes(3)), 0, 4),
            'private' => (string) $privateKey,
            'public' => $this->stripPem($details['key']),
        ];
    }

    /** A DNS-be a PEM-fejlécek nélküli, egysoros base64 megy. */
    private function stripPem(string $pem): string
    {
        return str_replace(
            ["-----BEGIN PUBLIC KEY-----\n", "-----END PUBLIC KEY-----\n", "\n"],
            '',
            $pem
        );
    }
}
