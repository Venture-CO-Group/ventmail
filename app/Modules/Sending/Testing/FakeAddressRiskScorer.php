<?php

declare(strict_types=1);

namespace App\Modules\Sending\Testing;

use App\Modules\Sending\Contracts\AddressRiskScorer;

/**
 * Determinisztikus kockázat-becslés, amíg az A5 (Validation modul) nem él.
 *
 * A heurisztika szándékosan minimális — nem az a dolga, hogy jól tippeljen, hanem hogy a
 * kaput végig lehessen mérni, és az A5 cseréje CSAK a kontraktust érintse.
 */
final class FakeAddressRiskScorer implements AddressRiskScorer
{
    public function __construct(private readonly ?float $score = null) {}

    public function riskFor(string $email): float
    {
        if ($this->score !== null) {
            return $this->score;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 1.0;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, (array) config('sending.validation.disposable_domains'), true) ? 0.9 : 0.1;
    }
}
