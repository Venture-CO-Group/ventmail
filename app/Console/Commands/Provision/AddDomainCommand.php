<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\Domains\Actions\CreateDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Console\Command;

/**
 * Küldő domain felvétele a domain-auth wizard útján (DKIM-kulccsal), és a felveendő
 * DNS-rekordok kiírása. Idempotens: létező domainnél NEM generál új kulcsot.
 * A privát kulcs sosem kerül a kimenetbe (a `dnsRecords()` csak a publikusat adja).
 */
final class AddDomainCommand extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'ventmail:domain:add {workspace} {domain}';

    protected $description = 'Küldő domain felvétele és a DNS-rekordok kiírása (idempotens).';

    public function handle(CreateDomain $create): int
    {
        $this->enterWorkspace((string) $this->argument('workspace'));

        $name = DomainNormalizer::canonical(trim((string) $this->argument('domain')));
        $domain = Domain::query()->where('name', $name)->first() ?? $create($name);

        $this->table(['kulcs', 'típus', 'név', 'érték'], collect($domain->dnsRecords())
            ->map(fn (array $record, string $key) => [$key, $record['type'], $record['name'], $record['value']])
            ->values()
            ->all());
        $this->line(sprintf('állapot: %s', $domain->status ?? 'pending'));

        return self::SUCCESS;
    }
}
