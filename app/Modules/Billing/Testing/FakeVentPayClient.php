<?php

declare(strict_types=1);

namespace App\Modules\Billing\Testing;

use App\Modules\Billing\Contracts\VentPayClient;

/**
 * A ventPay kontraktusa, amíg a szolgáltatás nem él (C1 kapcsolja élesre — CSAK config).
 *
 * A kvótát configból adja, hogy a 4. kapu végigmérhető legyen; kártyaadatot itt sem lát
 * senki, mert a kontraktusban sincs ilyen.
 */
final class FakeVentPayClient implements VentPayClient
{
    public function remainingSendQuota(int $workspaceId): int
    {
        return (int) config('sending.fake_entitlement.remaining');
    }

    /**
     * A JOGOSULTSÁG workspace-enként is felülírható.
     *
     * Miért: a `EntitlementChecker` egyetlen létjogosultsága az, hogy a SUB-ACCOUNT a
     * PARENT csomagját használja — a korábbi fake viszont eldobta a `$workspaceId`-t,
     * tehát a szabály semmivel nem volt bizonyítva: a teszt akkor is zöld lett volna, ha a
     * `billableId()` bármilyen számot ad vissza. A `per_workspace` kulcs a teszt kezébe
     * adja a különbséget.
     */
    public function hasEntitlement(int $workspaceId, string $feature): bool
    {
        $perWorkspace = (array) config('sending.fake_entitlement.per_workspace', []);

        if (array_key_exists($workspaceId, $perWorkspace)) {
            return in_array($feature, (array) $perWorkspace[$workspaceId], true);
        }

        return in_array($feature, (array) config('sending.fake_entitlement.features'), true);
    }

    /**
     * MINDEN periódus RENDEZETT, amíg a ventPay nem él.
     *
     * Ez a fail-safe alapérték, és nem lustaság: a felfüggesztés a legdurvább eszköz (a
     * tranzakciós levelet is megállítja, tehát a tenant ÜGYFELEIT sújtja). Amíg nincs
     * fizetési információnk, senkit nem függesztünk fel egy olyan tartozás miatt, amiről
     * nem tudunk — a review megmérte, hogy az ellenkező alapérték ~45 nappal az első zárás
     * után a teljes platform forgalmát leállította volna.
     *
     * A teszt a `fake_entitlement.unsettled` kulccsal állítja be, mely periódusok
     * rendezetlenek, és így az állapotgép végigmérhető.
     */
    public function isPeriodSettled(int $workspaceId, string $period): bool
    {
        $unsettled = (array) config('sending.fake_entitlement.unsettled', []);

        return ! in_array($period, $unsettled, true)
            && ! in_array($workspaceId.':'.$period, $unsettled, true);
    }
}
