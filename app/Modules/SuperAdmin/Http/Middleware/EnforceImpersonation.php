<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Middleware;

use App\Models\User;
use App\Modules\SuperAdmin\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AZ ÍRÁS-TILALOM A HTTP-RÉTEGBEN (82. kritérium · a C2 nevesített előfeltétele).
 *
 * ## Miért kell, ha a modell-őr már megvan
 *
 * Mert az `ImpersonationReadOnlyGuard` az Eloquent-eseményekre épül, és azokat a
 * query-builder megkerüli (`Model::query()->update()`, nyers beszúrás) — a C2
 * security-review ezt meg is mérte. Amíg a konzolnak nem volt route-ja, ez elméleti
 * maradt; egy FELÜLETTEL viszont minden POST/PUT/PATCH/DELETE valódi belépési pont.
 *
 * A két réteg különböző kérdésre válaszol, és ez szándékos:
 *  - a middleware azt mondja meg, hogy egy KÉRÉS módosíthat-e (olcsó, teljes, korai);
 *  - a modell-őr azt, hogy egy ADAT módosítható-e (a jobokra és a konzol-parancsokra is áll).
 *
 * ## Miért a metódus dönt, és nem a route neve
 *
 * Mert a route-lista nő, és egy új írási végpontot senki nem fog felvenni egy tiltólistára.
 * A biztonságos alapértelmezés az, hogy CSAK a biztonságos metódusok mennek át — egy új
 * `POST` automatikusan tiltott lesz, nem automatikusan engedett.
 */
final class EnforceImpersonation
{
    /** RFC 9110 biztonságos metódusok — ezek definíció szerint nem módosítanak. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $session = $this->impersonation->active($user);

        if ($session === null) {
            return $next($request);
        }

        /*
         | A NÉZET LEZÁRÁSA KIVÉTEL, és muszáj annak lennie: az a művelet szünteti meg az
         | állapotot, amiben vagyunk. Enélkül a támogatói nézetből nem lehetne kilépni —
         | csak megvárni a 30 perces lejáratot.
         */
        if ($request->routeIs('platform.impersonation.end')) {
            return $next($request);
        }

        abort(403, 'Aktív támogatói nézet (impersonation) alatt az ÍRÁS TILOS: a támogatás LÁT, nem módosít.');
    }
}
