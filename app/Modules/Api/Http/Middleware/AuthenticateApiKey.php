<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\ClientAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    /**
     * Hány SIKERTELEN hitelesítés fér bele percenként egy forrás-IP-ről.
     *
     * A kérés-alapú throttle ezt nem fogja meg: a kulcs-bucket a MÉG NEM ELLENŐRZÖTT
     * tokenből származik, tehát aki kérésenként más Bearert küld, kérésenként friss keretet
     * kap. A HIBÁK számlálása viszont pont azt méri, amit fékezni akarunk — és egy
     * jóhiszemű integrációt nem érint.
     */
    private const MAX_FAILURES_PER_MINUTE = 20;

    /**
     * A FORRÁS-IP plafonja.
     *
     * Magasabb, mint a hitelesítő-szintű: egy megosztott kimenő IP (NAT, CI-runner, LB)
     * mögött több ügyfél is lehet, és egyetlen elrontott integráció nem zárhatja ki az
     * összes többit. Ugyanakkor a kulcsot forgató támadót ez fogja meg.
     */
    private const MAX_FAILURES_PER_IP = 100;

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->extractKey($request);

        // KÉT vödör: a bemutatott hitelesítőé és a forrás-IP-é. Csak az IP-é önmagában
        // kizárná a szomszédokat egy közös NAT mögött; csak a hitelesítőé pedig
        // hatástalan, mert a támadó kulcsot forgat.
        // A VALÓDI TCP-partner, nem a `request->ip()`. A `TRUSTED_PROXIES` alapértéke `*`,
        // tehát a `request->ip()` a KÉRŐ által beírt `X-Forwarded-For`-ból jön (mérve) —
        // rotáló fejléccel az IP-vödör kérésenként friss keretet adott, a `credentialKey`
        // pedig kulcsforgatásnál nem fékez. Vagyis az API-kulcs brute-force plafonja
        // gyakorlatilag megszűnt.
        $ipKey = 'api-auth-fail:ip:'.$this->sourceBucket(ClientAddress::of($request));
        $credentialKey = 'api-auth-fail:key:'.hash('sha256', (string) $plain);

        // A fék a DRÁGA munka (kulcsolt hash + indexelt lekérdezés) ELŐTT csattan.
        if (RateLimiter::tooManyAttempts($credentialKey, self::MAX_FAILURES_PER_MINUTE)) {
            return $this->tooManyFailures($credentialKey);
        }

        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_FAILURES_PER_IP)) {
            return $this->tooManyFailures($ipKey);
        }

        $failureKey = [$credentialKey, $ipKey];

        if ($plain === null) {
            return $this->unauthorized($failureKey);
        }

        $key = ApiKey::findByPlainKey($plain);

        // EGYETLEN üzenet minden hitelesítési hibára: a különböző szövegek orákulumot
        // adnának arra, hogy a kulcs valódi-e (csak a workspace-e törölt).
        if ($key === null) {
            return $this->unauthorized($failureKey);
        }

        $workspace = Workspace::query()->whereKey($key->workspace_id)->first();

        if ($workspace === null) {
            Log::warning('API-kulcs elérhetetlen workspace-hez', ['api_key_id' => $key->getKey()]);

            return $this->unauthorized($failureKey);
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

    /**
     * A forrás azonosítója a vödörhöz.
     *
     * IPv6-nál a /64 prefix, nem a teljes cím: egy /64 rendszerint EGY előfizetőé, és a
     * címek azon belül szabadon váltogathatók — cím-szintű vödörrel a fék ingyen
     * megkerülhető lenne.
     */
    private function sourceBucket(string $ip): string
    {
        if (! str_contains($ip, ':')) {
            return $ip;
        }

        $binary = @inet_pton($ip);

        if ($binary === false || strlen($binary) !== 16) {
            return $ip;
        }

        // IPv4-mapped (`::ffff:1.2.3.4`), loopback (`::1`) és `::`: ezek első 8 bájtja
        // csupa nulla, tehát a /64-es vödörben MIND EGYBE esne — 100 hibás hitelesítés
        // bárkitől kizárná az összes ilyen alakban látszó klienst. A beágyazott IPv4 a
        // valódi forrás; a többi speciális címet a teljes alakjával azonosítjuk.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($binary, 12));
        }

        if (str_starts_with($binary, str_repeat("\0", 8))) {
            return $ip;
        }

        return bin2hex(substr($binary, 0, 8)).'::/64';
    }

    /** @param list<string> $failureKeys */
    private function unauthorized(array $failureKeys): Response
    {
        foreach ($failureKeys as $key) {
            RateLimiter::hit($key, 60);
        }

        return response()->json([
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'Hiányzó vagy érvénytelen API-kulcs.',
            ],
        ], 401);
    }

    private function tooManyFailures(string $failureKey): Response
    {
        return response()->json([
            'error' => [
                'code' => 'TOO_MANY_REQUESTS',
                'message' => 'Túl sok sikertelen hitelesítés erről a címről; próbáld később.',
            ],
        ], 429)->header('Retry-After', (string) RateLimiter::availableIn($failureKey));
    }
}
