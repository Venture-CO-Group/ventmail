<?php

declare(strict_types=1);

namespace App\Modules\Sending\Contracts;

/**
 * Marketing-consent ellenőrzés — a PreSendGate 3. kapuja.
 *
 * A double opt-in FOLYAMAT gazdája a B1 (Consent modul); a gate csak a kontraktust ismeri.
 * Amíg a B1 nincs kész, a `FakeConsentChecker` a teszttel fedett kontraktus.
 */
interface ConsentChecker
{
    public function hasConfirmedConsent(string $email, string $channel): bool;
}
