<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\Identity\Models\ApiKey;
use Illuminate\Console\Command;

/** API-kulcs visszavonása név alapján (soft delete — az audit-nyom megmarad). */
final class RevokeApiKeyCommand extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'ventmail:api-key:revoke {workspace} {name}';

    protected $description = 'API-kulcs visszavonása név alapján.';

    public function handle(): int
    {
        $this->enterWorkspace((string) $this->argument('workspace'));

        $deleted = 0;
        foreach (ApiKey::query()->where('name', (string) $this->argument('name'))->get() as $key) {
            $key->delete();
            $deleted++;
        }

        $this->info("visszavonva: {$deleted} kulcs");

        return $deleted > 0 ? self::SUCCESS : self::FAILURE;
    }
}
