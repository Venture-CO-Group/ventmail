<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\Sending\Gate\GateReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * EGYSZERI adatjavítás (FO14): a B2 előtt elutasított, ÁTMENETI okú üzenetek (SENDING_RESTRICTED,
 * RATE_LIMITED, QUOTA_EXCEEDED, DOMAIN_UNVERIFIED, CHANNEL_DISABLED, GATE_ERROR) Idempotency-Key-je
 * még lekötött, tehát az újrapróbáló kliens örökre a régi 422-t kapná. A parancs felszabadítja
 * őket (a sor a naplóban marad). Idempotens, tenantokon átnyúló karbantartás (nem tenant-lekérdezés),
 * adatot nem olvas vissza. Deploy után egyszer futtatandó.
 */
final class ReleaseTransientIdempotencyKeysCommand extends Command
{
    protected $signature = 'sending:release-transient-keys';

    protected $description = 'Az átmenetileg elutasított üzenetek lekötött Idempotency-Key-jének felszabadítása (egyszeri, idempotens).';

    public function handle(): int
    {
        $reasons = array_map(
            fn (GateReason $reason) => $reason->value,
            array_filter(GateReason::cases(), fn (GateReason $reason) => $reason->isTransient()),
        );

        $released = DB::table('messages')
            ->where('status', 'rejected')
            ->whereIn('reason', $reasons)
            ->whereNotNull('idempotency_key')
            ->update(['idempotency_key' => null]);

        $this->info("Felszabadított kulcs: {$released}");

        return self::SUCCESS;
    }
}
