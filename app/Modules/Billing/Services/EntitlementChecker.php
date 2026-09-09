<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Billing\Enums\Feature;
use App\Modules\SuperAdmin\Services\PlanOverrides;
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
    public function __construct(
        private readonly VentPayClient $ventPay,
        private readonly PlanOverrides $overrides,
    ) {}

    /**
     * ELŐBB AZ OVERRIDE, utána a ventPay (C2 · 83. kritérium).
     *
     * Az override a támogatás KIVÉTELE: ha az engedi, nem kérdezzük a ventPay-t. A
     * fordított sorrend azt jelentené, hogy a ventPay „nem" válasza felülírja a
     * platform-admin szándékos döntését — pedig épp azért adta, mert a csomag nem
     * tartalmazza.
     *
     * Az override HOZZÁAD, nem helyettesít: amiről nem mond semmit, arról a ventPay dönt.
     */
    public function allows(Workspace $workspace, Feature $feature): bool
    {
        if ($this->overrides->allows($workspace, $feature)) {
            return true;
        }

        return $this->ventPay->hasEntitlement($this->billableId($workspace), $feature->value);
    }

    /**
     * A KVÓTA — az override-dal, ha van.
     *
     * A felülírt kvóta a ventPay válaszát VÁLTJA KI (nem összeadódik vele): a támogatás
     * egy konkrét számot ad meg („ehhez a kampányhoz 50 000 kell"), nem egy növekményt.
     */
    public function remainingQuota(Workspace $workspace): int
    {
        $override = $this->overrides->sendQuota($workspace);

        if ($override !== null) {
            return $override;
        }

        return $this->ventPay->remainingSendQuota($this->billableId($workspace));
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
