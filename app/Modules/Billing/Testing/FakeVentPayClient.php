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

    public function hasEntitlement(int $workspaceId, string $feature): bool
    {
        return in_array($feature, (array) config('sending.fake_entitlement.features'), true);
    }
}
