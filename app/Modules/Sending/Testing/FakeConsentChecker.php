<?php

declare(strict_types=1);

namespace App\Modules\Sending\Testing;

use App\Modules\Consent\Models\Consent;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Services\DomainNormalizer;

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

        // A `revoked_at` NÉZÉSE nem opcionális: a preference center a leiratkozáskor ezt
        // állítja, a `status` pedig 'confirmed' maradhat — enélkül a leiratkozott címzett
        // tovább kapná a marketinget. Az egyetlen igazság-definíció a `usable()` scope.
        return $this->withoutConsent([$email], $channel) === [];
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    public function withoutConsent(array $emails, string $channel): array
    {
        if ($this->confirmed !== null) {
            return $this->confirmed ? [] : array_values($emails);
        }

        $normalized = array_map(fn (string $email) => DomainNormalizer::listKey(trim($email)), $emails);

        $consented = Consent::query()->usable()
            ->whereIn('email', $normalized)
            ->where('channel', $channel)
            ->pluck('email')
            ->all();

        return array_values(array_diff($normalized, $consented));
    }
}
