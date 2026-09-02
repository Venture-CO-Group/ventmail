<?php

declare(strict_types=1);

namespace App\Modules\Sending\Contracts;

/**
 * Címellenőrzési kockázat — a PreSendGate 6. kapuja.
 *
 * A valódi ellenőrző lánc (szintaxis → MX → szerepcím → eldobható → catch-all → szonda)
 * az A5 packet gazdája. A küszöb per-domain kapcsolható; a fake determinisztikus.
 *
 * A visszaadott érték 0.0 (biztos) … 1.0 (biztosan rossz).
 */
interface AddressRiskScorer
{
    public function riskFor(string $email): float;
}
