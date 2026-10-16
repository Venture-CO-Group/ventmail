<?php

declare(strict_types=1);

namespace App\Modules\Consent\Jobs;

use App\Modules\Consent\Contracts\ConsentProofLedger;
use App\Modules\Consent\Data\ConsentProof;
use App\Modules\Consent\Models\Consent;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Egy consent-bizonyíték letétele a ventCookie főkönyvbe (retry + backoff). A job NEM hordoz
 * nyers címet: a törzsben csak a HMAC-elt alany-hivatkozás van. A főkönyv-hiba a helyi
 * folyamatot sosem érinti — ez a job a helyi tranzakció UTÁN fut.
 */
final class SendConsentProof implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $consentId,
        public readonly array $payload,
        public readonly string $idempotencyKey,
        public readonly string $occurredAt,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(ConsentProofLedger $ledger): void
    {
        $receipt = $ledger->record(new ConsentProof($this->payload, $this->idempotencyKey));

        if ($receipt === null || ! $receipt->durable) {
            return;
        }

        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        // A consent a SAJÁT workspace kontextusában töltődik (global scope): idegen
        // workspace consent-jére a job nem tud nyugtát írni. A korábbi kontextust visszaállítjuk
        // (szinkron queue-nál a hívó folyamata ugyanebben a processzben fut).
        $context = app(WorkspaceContext::class);
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            // ZÁROLVA: két párhuzamos job nyugtája ne írja felül egymást a régebbi javára.
            DB::transaction(function () use ($receipt): void {
                $consent = Consent::query()->whereKey($this->consentId)->lockForUpdate()->first();

                if ($consent === null) {
                    return;
                }

                $at = Carbon::parse($this->occurredAt);

                // Egy késve érkező, RÉGEBBI nyugta nem írja felül az újabbat.
                if ($consent->ledger_receipt_at !== null && $consent->ledger_receipt_at->greaterThan($at)) {
                    return;
                }

                $consent->forceFill(['ledger_receipt_id' => $receipt->receiptId, 'ledger_receipt_at' => $at])->save();
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
