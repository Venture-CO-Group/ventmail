<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

/**
 * ventPay — entitlement és kvóta (a PreSendGate 4. kapuja).
 *
 * Kártyaadatot a ventMail SOHA nem lát. Amíg a ventPay nem él, a `FakeVentPayClient` a
 * teszttel fedett kontraktus; élesedéskor CSAK a config vált (golden rule: egy képesség —
 * egy gazda).
 */
interface VentPayClient
{
    /** Hány üzenet küldhető még a jelenlegi elszámolási időszakban. */
    public function remainingSendQuota(int $workspaceId): int;

    public function hasEntitlement(int $workspaceId, string $feature): bool;
}
