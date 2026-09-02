<?php

declare(strict_types=1);

namespace App\Modules\Domains\Console;

use App\Modules\Domains\Actions\PurgeExpiredDkimKeys;
use Illuminate\Console\Command;

/** A lejárt előző DKIM privát kulcsok törlése (retenció). */
final class PurgeDkimKeysCommand extends Command
{
    protected $signature = 'domains:purge-dkim';

    protected $description = 'A rotációs átfedés lejárta után törli az előző DKIM kulcsanyagot.';

    public function handle(): int
    {
        $this->info('Törölt előző kulcsok: '.PurgeExpiredDkimKeys::run());

        return self::SUCCESS;
    }
}
