<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/**
 * 4. kapu — entitlement és kvóta (ventPay).
 *
 * A kvótát a ventPay tartja (egy képesség — egy gazda); a ventMail nem számol saját
 * egyenleget. Amíg a ventPay nem él, a fake adja a kontraktust.
 */
final class EntitlementGate implements Gate
{
    public function __construct(private readonly VentPayClient $ventPay) {}

    public function name(): string
    {
        return 'entitlement';
    }

    public function check(Message $message): GateResult
    {
        $needed = max(1, count($message->recipients));
        $remaining = $this->ventPay->remainingSendQuota((int) $message->workspace_id);

        if ($remaining < $needed) {
            return GateResult::reject(
                GateReason::QuotaExceeded,
                "Hátralévő kvóta: {$remaining}, szükséges: {$needed}"
            );
        }

        return GateResult::pass("kvóta: {$remaining}");
    }
}
