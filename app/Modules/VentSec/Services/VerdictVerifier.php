<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Services;

use App\Modules\VentSec\Support\Jcs;
use stdClass;
use Throwable;

/**
 * A ventSec ítélet-aláírásának ellenőrzése: Ed25519 a `ventsec.scan-verdict.v1\n` ‖ JCS(aláírt
 * mezők) üzeneten. Ami nem pontosan jó aláírás pontosan erre a tartalomra, az `false` — soha
 * kivétel, soha „valószínűleg jó". Az aláírt mezőkészlet a ventSec `scan-verdict.json`
 * vektorának `signed_fields`-je; a `cached`/`stale`/`declared_ext` szállítási jelzés, nincs
 * benne az aláírásban.
 */
final class VerdictVerifier
{
    private const PREFIX = "ventsec.scan-verdict.v1\n";

    /** A `ref` kivételével mind kötelező; a `ref` null/hiányzó értéke kimarad az aláírásból. */
    public const SIGNED_FIELDS = [
        'scan_id', 'key_id', 'ref', 'requester', 'sha256', 'size', 'detected_type', 'verdict', 'binary_verdict',
        'recommended_action', 'flagged', 'profile', 'steps', 'engine_versions', 'stale_signatures', 'scanned_at',
    ];

    /** @param stdClass $response a `json_decode(…, false)` eredménye */
    public function verify(stdClass $response, string $publicKey): bool
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        $signature = $response->signature ?? null;

        if (! is_string($signature) || preg_match('/^[A-Za-z0-9_-]{86}$/', $signature) !== 1) {
            return false;
        }

        $raw = base64_decode(strtr($signature, '-_', '+/').'==', true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            $message = self::PREFIX.Jcs::canonicalize($this->signedPayload($response));

            return sodium_crypto_sign_verify_detached($raw, $message, $publicKey);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException hiányzó kötelező mező
     */
    private function signedPayload(stdClass $response): array
    {
        $payload = [];

        foreach (self::SIGNED_FIELDS as $field) {
            if ($field === 'ref') {
                if (($response->ref ?? null) !== null) {
                    $payload['ref'] = $response->ref;
                }

                continue;
            }

            if (! property_exists($response, $field)) {
                throw new \InvalidArgumentException("Hiányzó aláírt mező: {$field}");
            }

            $payload[$field] = $response->{$field};
        }

        return $payload;
    }
}
