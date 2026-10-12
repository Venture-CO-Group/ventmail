<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A két platform IP-pool (deploy-ovh, kemény szabály 5): `transactional` és `marketing`,
 * mindkettő PONTOSAN egy IP-vel. Idempotens. A marketing napi kerete 0, amíg a warm-up nem
 * indul (docs/WARMUP-PLAN.md) — a küldés-tiltás így a pool szintjén is érvényes.
 */
final class SyncIpPoolsCommand extends Command
{
    protected $signature = 'ventmail:ip-pools:sync
        {--transactional= : a tranzakciós pool IP-je}
        {--transactional-hostname= : a HELO/PTR név}
        {--transactional-daily-limit=500}
        {--marketing= : a marketing pool IP-je}
        {--marketing-hostname= : a HELO/PTR név}
        {--marketing-daily-limit=0}';

    protected $description = 'A tranzakciós és a marketing platform-pool szinkronizálása (idempotens).';

    public function handle(): int
    {
        $tx = (string) $this->option('transactional');
        $mk = (string) $this->option('marketing');

        foreach ([$tx, $mk] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $this->error("Érvénytelen IPv4: {$ip} (SMTP csak IPv4-en megy)");

                return self::INVALID;
            }
        }

        if ($tx === $mk) {
            $this->error('A két pool nem kaphatja ugyanazt az IP-t — a reputáció keveredne.');

            return self::INVALID;
        }

        DB::transaction(function () use ($tx, $mk): void {
            IpPool::onPlatform(function () use ($tx, $mk): void {
                $this->pool('transactional', $tx, (string) $this->option('transactional-hostname'), (int) $this->option('transactional-daily-limit'));
                $this->pool('marketing', $mk, (string) $this->option('marketing-hostname'), (int) $this->option('marketing-daily-limit'));
            });
        });

        $this->info("pool-ok: transactional={$tx}, marketing={$mk}");

        return self::SUCCESS;
    }

    private function pool(string $channel, string $ip, string $hostname, int $limit): void
    {
        $pool = IpPool::query()->whereNull('workspace_id')->where('channel', $channel)->first()
            ?? IpPool::create(['name' => $channel, 'type' => 'shared', 'channel' => $channel]);

        // A poolban CSAK ez az egy cím maradhat.
        IpAddress::query()->where('ip_pool_id', $pool->id)->where('address', '!=', $ip)->get()->each->delete();

        $address = IpAddress::query()->where('address', $ip)->first();

        if ($address !== null && $address->ip_pool_id !== $pool->id) {
            throw new \RuntimeException("{$ip} már egy másik poolban van.");
        }

        IpAddress::query()->updateOrCreate(
            ['ip_pool_id' => $pool->id, 'address' => $ip],
            ['hostname' => $hostname, 'daily_limit' => max(0, $limit), 'status' => 'active', 'warmup_state' => 'warming']
        );
    }
}
