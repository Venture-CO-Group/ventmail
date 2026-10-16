<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Consent\Contracts\ConsentProofLedger;
use App\Modules\Consent\Data\ConsentProof;
use App\Modules\Consent\Data\ConsentReceipt;
use App\Modules\VentSec\Support\SecureUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A ventCookie `POST /api/internal/v1/email-consent` kliense (service token + Idempotency-Key).
 *
 *  - 201/200 → nyugta;
 *  - 409 (ugyanaz a kulcs, eltérő törzs — pl. a Gmail újrapróbált leiratkozása más időbélyeggel):
 *    a bizonyíték már a főkönyvben van → lezárt, nyugta nélkül;
 *  - egyéb 4xx (404 ismeretlen site, 422 séma) → végleges, naplózva (a kód, tartalom nélkül);
 *  - hálózati hiba, 5xx, 429, 401/403 (konfig-hiba, javítás után újrapróbálható) → kivétel → retry.
 *
 * A kivétel-üzenet nem hordoz tokent, törzset, címet.
 */
final class HttpConsentProofLedger implements ConsentProofLedger
{
    public function record(ConsentProof $proof): ?ConsentReceipt
    {
        $base = rtrim((string) config('consent.ledger.url'), '/');
        $token = (string) config('consent.ledger.token');

        if ($base === '' || $token === '' || ! SecureUrl::ok($base)) {
            throw new RuntimeException('ventCookie főkönyv: a végpont vagy a token nincs (biztonságosan) beállítva.');
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Idempotency-Key' => $proof->idempotencyKey])
                ->acceptJson()->asJson()
                ->timeout((int) config('consent.ledger.timeout'))->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->post($base.'/api/internal/v1/email-consent', $proof->body);
        } catch (Throwable $e) {
            throw new RuntimeException('ventCookie főkönyv: hálózati hiba ('.$e::class.').');
        }

        $status = $response->status();

        if ($status === 200 || $status === 201) {
            $receipt = $response->json('receipt_id');

            if (! is_string($receipt) || $receipt === '' || strlen($receipt) > 128) {
                Log::warning('consent.ledger.unusable_receipt', ['status' => $status]);

                return null;
            }

            return new ConsentReceipt($receipt);
        }

        if ($status === 409) {
            // Azonos kulcs + eltérő törzs: a bizonyíték már a főkönyvben van (lezárt) — de a 409
            // szokatlan, ezért WARNING és külön esemény (hash-lánc auditáláshoz), PII nélkül.
            Log::warning('consent.ledger.idempotency_conflict');

            return null;
        }

        if ($status === 401 || $status === 403 || $status === 408 || $status === 429 || $status >= 500) {
            throw new RuntimeException("ventCookie főkönyv: HTTP {$status}.");
        }

        Log::warning('consent.ledger.rejected', ['status' => $status]);

        return null;
    }
}
