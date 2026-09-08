<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Console;

use App\Modules\Deliverability\Services\BlocklistMonitor;
use Illuminate\Console\Command;

/** `php artisan deliverability:blocklists` — a küldő IP-k feketelista-ellenőrzése. */
final class CheckBlocklistsCommand extends Command
{
    protected $signature = 'deliverability:blocklists';

    protected $description = 'A küldő IP-k ellenőrzése a feketelistákon.';

    public function handle(BlocklistMonitor $monitor): int
    {
        if (! config('deliverability.blocklists.enabled')) {
            $this->warn('A blocklist-figyelés ki van kapcsolva.');

            return self::SUCCESS;
        }

        $summary = $monitor->run();

        $this->info(sprintf(
            'Ellenőrizve: %d, listán: %d, eldönthetetlen: %d, riasztás: %d',
            $summary['checked'],
            $summary['listed'],
            $summary['inconclusive'],
            count($summary['alerts']),
        ));

        foreach ($summary['alerts'] as $alert) {
            $this->warn($alert);
        }

        return self::SUCCESS;
    }
}
