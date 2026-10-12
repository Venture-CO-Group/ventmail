<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\Identity\Models\ApiKey;
use Illuminate\Console\Command;

/**
 * API-kulcs kiadása. A PLAINTEXT kulcs KIZÁRÓLAG a stdout-ra megy, egyetlen sorban —
 * hogy csővel közvetlenül a célgép `.env`-jébe kerülhessen (`| ssh trust set-env.sh`),
 * és se terminálban, se naplóban ne jelenjen meg. Minden más az stderr-re megy.
 */
final class IssueApiKeyCommand extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'ventmail:api-key:issue {workspace} {name} {--scope=*} {--channel=transactional}';

    protected $description = 'API-kulcs kiadása; a plaintext csak a stdout-ra kerül (csőhöz).';

    public function handle(): int
    {
        $this->enterWorkspace((string) $this->argument('workspace'));
        $name = (string) $this->argument('name');

        if (ApiKey::query()->where('name', $name)->exists()) {
            fwrite(STDERR, "Már van élő kulcs ezzel a névvel ({$name}); előbb: ventmail:api-key:revoke\n");

            return self::FAILURE;
        }

        $scopes = array_values(array_filter((array) $this->option('scope')));
        $channels = array_values(array_filter(explode(',', (string) $this->option('channel'))));

        $issued = ApiKey::issue($name, $scopes, channels: $channels);

        $this->output->writeln($issued['plain']);

        return self::SUCCESS;
    }
}
