<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Str;

/**
 * 1. kapu — „Deliverability nem alku": verifikálatlan domainről NINCS küldés
 * (golden rule 8). Ez a legelső kapu: ha a feladó domain nem a miénk, minden további
 * ellenőrzés tárgytalan.
 */
final class DomainVerifiedGate implements Gate
{
    public function name(): string
    {
        return 'domain';
    }

    public function check(Message $message): GateResult
    {
        $host = Str::after($message->from_email, '@');

        $domain = Domain::query()->where('name', $host)->first();

        if ($domain === null) {
            return GateResult::reject(GateReason::DomainUnverified, "Ismeretlen feladó domain: {$host}");
        }

        if (! $domain->isVerified()) {
            return GateResult::reject(
                GateReason::DomainUnverified,
                "A(z) {$host} domain állapota: {$domain->status}"
            );
        }

        return GateResult::pass("verified: {$host}");
    }
}
