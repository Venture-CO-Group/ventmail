<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden rule 5: **Idempotency-Key kötelező minden küldő POST-on.**
 *
 * Miért middleware és nem validáció: így egyetlen küldő endpoint sem tudja „elfelejteni".
 * A hálózat megismételheti a kérést; kulcs nélkül a duplikált kézbesítés elkerülhetetlen.
 */
final class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                    'message' => 'Az `Idempotency-Key` fejléc kötelező: nélküle egy megismételt '
                        .'hálózati kérés duplán kézbesítene.',
                ],
            ], 400);
        }

        if (strlen($key) > 255) {
            return response()->json([
                'error' => ['code' => 'IDEMPOTENCY_KEY_TOO_LONG', 'message' => 'Az Idempotency-Key max 255 karakter.'],
            ], 400);
        }

        $request->attributes->set('idempotency_key', $key);

        return $next($request);
    }
}
