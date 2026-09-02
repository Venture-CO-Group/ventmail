<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Identity\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scope-ellenőrzés (`messages:send`, `templates:write`, …).
 *
 * A `developer` szerep kaphat kulcsot, de a kulcs SCOPE-ja dönti el, mit tehet — így a
 * kiszivárgott CI-kulcs sem tud kampányt publikálni.
 */
final class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->attributes->get('api_key');

        if (! $key instanceof ApiKey || ! $key->hasScope($scope)) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_SCOPE',
                    'message' => "Az API-kulcshoz hiányzik a(z) {$scope} scope.",
                ],
            ], 403);
        }

        return $next($request);
    }
}
