<?php

declare(strict_types=1);

namespace App\Modules\Api\Support;

use Illuminate\Http\JsonResponse;

/**
 * A `/v1` egységes hibaformátuma.
 *
 * Egyetlen alak, mert ami egyszer kimegy, az SZERZŐDÉS (golden rule 7): az integrációk a
 * `error.code`-ra fognak ágazni. A gate strukturált `reason` kódjai VÁLTOZATLANUL kerülnek
 * bele — így a hívó ugyanazt látja, mint az üzenet-trace (A3).
 */
final class ApiError
{
    /** @param array<string, mixed> $details */
    public static function response(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];

        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    /** Bemeneti hiba: a kérés soha nem lett volna teljesíthető (400). */
    public static function badRequest(string $code, string $message): JsonResponse
    {
        return self::response($code, $message, 400);
    }

    /** A kérés formailag rendben, de a szabályok elutasítják (422) — pl. a PreSendGate. */
    public static function rejected(string $code, string $message, array $details = []): JsonResponse
    {
        return self::response($code, $message, 422, $details);
    }
}
