<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
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
            return $this->unauthorized('Hiányzó API-kulcs. Használj `Authorization: Bearer <kulcs>` fejlécet.');
        }

        $key = ApiKey::findByPlainKey($plain);

        if ($key === null) {
            return $this->unauthorized('Érvénytelen vagy lejárt API-kulcs.');
        }

        $workspace = Workspace::query()->whereKey($key->workspace_id)->first();

        if ($workspace === null) {
            return $this->unauthorized('A kulcshoz tartozó workspace nem érhető el.');
        }

        $this->context->set($workspace);
        $request->attributes->set('api_key', $key);

        // A használat rögzítése: a super-admin (C2) ebből látja az árva kulcsokat.
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    private function extractKey(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        // Mailgun-paritás: a legacy integrációk HTTP Basic authot használnak `api:<kulcs>`
        // formában. Ez teszi lehetővé a kódmódosítás nélküli átállást.
        $password = $request->getPassword();

        return is_string($password) && $password !== '' ? $password : null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => ['code' => 'UNAUTHENTICATED', 'message' => $message],
        ], 401);
    }
}
