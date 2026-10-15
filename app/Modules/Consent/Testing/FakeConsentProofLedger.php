<?php

declare(strict_types=1);

namespace App\Modules\Consent\Testing;

use App\Modules\Consent\Contracts\ConsentProofLedger;
use App\Modules\Consent\Data\ConsentProof;
use App\Modules\Consent\Data\ConsentReceipt;
use Illuminate\Support\Facades\Log;

/**
 * Az alapértelmezett meghajtó (CONSENT_LEDGER_DRIVER=fake), amíg a ventCookie e-mail-consent API
 * nincs bekötve: rögzíti és naplózza a SZÁNDÉKOT (cím és személyes adat nélkül), nyugtát nem ad.
 */
final class FakeConsentProofLedger implements ConsentProofLedger
{
    /** @var list<array<string, mixed>> */
    public array $recorded = [];

    public function record(ConsentProof $proof): ?ConsentReceipt
    {
        $this->recorded[] = $proof->body;

        // TODO ventCookie: CONSENT_LEDGER_DRIVER=ventcookie, ha a POST /api/internal/v1/email-consent él.
        Log::info('consent.ledger.intent', [
            'action' => $proof->body['action'] ?? null,
            'scope_ref' => $proof->body['scope_ref'] ?? null,
        ]);

        return new ConsentReceipt('fake-'.substr($proof->idempotencyKey, -12), durable: false);
    }
}
