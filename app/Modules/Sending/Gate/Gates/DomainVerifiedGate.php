<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DomainNormalizer;

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
        // A domain az UTOLSÓ @ után van (RFC), és KANONIKUS (punycode, kisbetűs) alakban
        // keresendő: a nagybetűs feladó különben hitelesített domainen is
        // DOMAIN_UNVERIFIED-et kapott, az `SMTPUTF8` óta pedig a `példa.hu` és a
        // `xn--plda-bpa.hu` két külön domainnek látszana — az egyikkel a kapu megkerülhető.
        $host = DomainNormalizer::ofEmail((string) $message->from_email);

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

        // A döntés PERZISZTÁLÓDIK az üzenetre: enélkül nincs bizonyíték arra, melyik domain
        // engedélyezte a küldést, és az IP-pool-választás (A2) sem tud mire épülni.
        $message->domain_id = $domain->getKey();

        return GateResult::pass("verified: {$host}");
    }
}
