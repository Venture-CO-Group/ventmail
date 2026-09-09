<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Tenancy\Models\Workspace;

/**
 * FUNKCIÓ-JOGOSULTSÁG (76. kritérium) — egy helyen, a ventPay kontraktusán.
 *
 * ## Miért kell ez a réteg a `VentPayClient` FÖLÉ
 *
 * Mert a jogosultság-ellenőrzés eddig SZÉTSZÓRVA élt: a `JourneyTrigger`-ben, a
 * `JourneyEngine`-ben, és sehol máshol. A B3 review megállapította, hogy a spec által
 * nevesített `EnsureEntitlement` middleware nem is létezik — vagyis minden új belépési
 * pontnak újra meg kellett volna írnia az ellenőrzést, és valamelyik kihagyta volna.
 *
 * ## A SUB-ACCOUNT a PARENT csomagját használja
 *
 * Egy sub-account nem külön ügyfél: a parent fizet, tehát a parent csomagja határozza meg,
 * mit tud a child. Ha a child SAJÁT jogosultságát kérdeznénk, egy frissen létrehozott
 * alszámla semmit nem tudna — pedig épp azért hozták létre, hogy a parent képességeit
 * használja (márka-szeparáció, ügynökségi felállás).
 */
final class EntitlementChecker
{
    public function __construct(private readonly VentPayClient $ventPay) {}

    public function allows(Workspace $workspace, Feature $feature): bool
    {
        return $this->ventPay->hasEntitlement($this->billableId($workspace), $feature->value);
    }

    /**
     * A SZÁMLÁZOTT workspace: a parent, ha ez egy sub-account.
     *
     * Az `first()` elég: a séma egy childhoz egy parentet engedélyez (a pivot egyedi
     * indexe), és több parentnél a „melyik csomag érvényes" kérdés amúgy sem lenne
     * eldönthető.
     */
    private function billableId(Workspace $workspace): int
    {
        $parent = $workspace->parents()->first();

        return (int) ($parent?->getKey() ?? $workspace->getKey());
    }
}
