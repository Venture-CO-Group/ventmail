<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-kulcs hitelesítés a `/v1` felületen.
 *
 * A workspace-kontextus MAGÁBÓL a kulcsból származik — ezért itt (és csak itt) oldjuk fel a
 * kulcsot a tenant-scope kikapcsolásával, majd AZONNAL beállítjuk a kontextust. Enélkül az
 * API-hívásnak nem lenne tenantja, és a fejlesztő a global scope általános kikapcsolásáért
 * nyúlna (golden rule 6).
 */
final class AuthenticateApiKey
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->extractKey($request);

        if ($plain === null) {
            return $this->unauthorized();
        }

        $key = ApiKey::findByPlainKey($plain);

        // EGYETLEN üzenet minden hitelesítési hibára: a különböző szövegek orákulumot
        // adnának arra, hogy a kulcs valódi-e (csak a workspace-e törölt).
        if ($key === null) {
            return $this->unauthorized();
        }

        $workspace = Workspace::query()->whereKey($key->workspace_id)->first();

        if ($workspace === null) {
            Log::warning('API-kulcs elérhetetlen workspace-hez', ['api_key_id' => $key->getKey()]);

            return $this->unauthorized();
        }

        $this->context->set($workspace);
        $request->attributes->set('api_key', $key);

        // A használat rögzítése LEGFELJEBB PERCENKÉNT: minden híváson írva ugyanarra a forró
        // sorra folyamatos row-lock és WAL-forgalom lenne, épp a küldési csúcson.
        Cache::remember('api-key-used:'.$key->getKey(), 60, function () use ($key): bool {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();

            return true;
        });

        return $next($request);
    }

    private function extractKey(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        // Mailgun-paritás: a legacy integrációk HTTP Basic authot használnak `api:<kulcs>`
        // formában. A felhasználónevet KÖVETELJÜK, hogy a kulcs ne kerülhessen véletlenül
        // URL-userinfóba (ahonnan proxy- és access-logba szivárogna).
        if ($request->getUser() !== 'api') {
            return null;
        }

        $password = $request->getPassword();

        return is_string($password) && $password !== '' ? $password : null;
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'Hiányzó vagy érvénytelen API-kulcs.',
            ],
        ], 401);
    }
}
