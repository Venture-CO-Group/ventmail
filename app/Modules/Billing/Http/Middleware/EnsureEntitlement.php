<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Middleware;

use App\Modules\Api\Support\ApiError;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ROUTE-SZINTŰ funkció-jogosultság (76. kritérium): `entitlement:<funkció>`.
 *
 * ## Miért kell, amikor az ellenőrzés a szolgáltatásokban is ott van
 *
 * Mert a spec ezt a middleware-t NEVESÍTI, és a B3 review megállapította, hogy nem
 * létezik: az ellenőrzés inline volt a `JourneyTrigger`-ben és a `JourneyEngine`-ben.
 * Funkcionálisan fogott — de minden ÚJ belépési pontnak (API-végpont, felület, import)
 * újra meg kellett volna írnia, és valamelyik kihagyta volna. Egy route-szintű kapu az,
 * ami nem felejthető el: ha nincs rajta a route-on, az a diffben LÁTSZIK.
 *
 * ## A 402, és miért nem 403
 *
 * A `403 Forbidden` azt jelenti, hogy „nincs jogod" — a `402 Payment Required` azt, hogy
 * „a csomagod nem tartalmazza". A hívó oldalán ez KÉT KÜLÖNBÖZŐ művelet: a 403-ra
 * jogosultságot kérünk, a 402-re csomagot váltunk. Egy integráció automatikusan is
 * kezelheti, ha megmondjuk, melyikről van szó.
 *
 * ## Az ISMERETLEN FUNKCIÓ-NÉV kivétel, nem csendes átengedés
 *
 * Egy elgépelt `entitlement:journey` (egyes számban) csendben MINDENKIT átengedne — a
 * kapu ott lenne a route-on, és semmit nem védene. A `Feature::from()` ezért dob: a hibát
 * a fejlesztő látja a saját gépén, nem az ügyfél a számláján.
 */
final class EnsureEntitlement
{
    public function __construct(
        private readonly EntitlementChecker $entitlements,
        private readonly WorkspaceContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $required = Feature::tryFrom($feature);

        if ($required === null) {
            throw new InvalidArgumentException(sprintf(
                'Ismeretlen csomag-funkció a route-on: `%s`. Engedélyezett: %s.',
                $feature,
                implode(', ', Feature::values())
            ));
        }

        if (! $this->context->hasWorkspace()) {
            // FAIL-CLOSED: kontextus nélkül nem tudjuk, kinek a csomagját kérdezzük — és a
            // „nem tudjuk" itt nem lehet „engedjük".
            return ApiError::response('ENTITLEMENT_REQUIRED', 'A jogosultság nem ellenőrizhető.', 402);
        }

        if (! $this->entitlements->allows($this->context->workspace(), $required)) {
            return ApiError::response(
                'ENTITLEMENT_REQUIRED',
                sprintf('A csomag nem tartalmazza ezt a funkciót: %s.', $required->value),
                402,
            );
        }

        return $next($request);
    }
}
