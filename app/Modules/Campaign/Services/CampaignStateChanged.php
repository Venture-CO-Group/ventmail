<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use RuntimeException;

/**
 * A kampány állapota a hívó betöltése és a sorzár között MEGVÁLTOZOTT (review S2 → R4-S2).
 *
 * Dedikált kivétel, hogy a hívó (felület, ütemező, köteg-job) megkülönböztethesse a
 * versenyhelyzetet a valódi hibáktól. Az üzenet a felhasználó NYELVÉN szól; a belső
 * állapotnevek (`scheduled → sending`) csak a `from` / `to` mezőkben, naplózáshoz.
 */
final class CampaignStateChanged extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct((string) __('studio.checklist.changed'));
    }
}
