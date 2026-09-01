<?php

declare(strict_types=1);

namespace App\Modules\Sending\Testing;

use App\Modules\Consent\Models\Consent;
use App\Modules\Sending\Contracts\ConsentChecker;

/**
 * A consent-kontraktus teszttel fedett megvalósítása, amíg a B1 (Consent modul) nem él.
 *
 * Alapból a `consents` táblát olvassa — így a gate NEM stub, hanem valódi adatot néz;
 * a konstruktor-paraméter csak a teszteknek ad determinisztikus felülbírálást.
 */
final class FakeConsentChecker implements ConsentChecker
{
    public function __construct(private readonly ?bool $confirmed = null) {}

    public function hasConfirmedConsent(string $email, string $channel): bool
    {
        if ($this->confirmed !== null) {
            return $this->confirmed;
        }

        return Consent::query()
            ->where('email', strtolower(trim($email)))
            ->where('channel', $channel)
            ->where('status', 'confirmed')
            ->exists();
    }
}
