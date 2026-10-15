<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Services;

/**
 * `X-VentSec-Signature: sha256=<kisbetűs hex HMAC-SHA256(nyers törzs, forrás-titok)>`.
 * A titkot SZÖVEGKÉNT használjuk, nem hex-dekódolva (tests/Fixtures/ventsec/ingest-signature.json).
 */
final class IngestSignature
{
    public static function header(string $body, #[\SensitiveParameter] string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }
}
