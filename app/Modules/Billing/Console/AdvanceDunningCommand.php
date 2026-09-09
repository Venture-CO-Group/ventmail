<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\Dunning;
use Illuminate\Console\Command;

/**
 * A DUNNING-ÁLLAPOTGÉP léptetése (79. kritérium).
 *
 * Tenant-független, és ez szándékos: a léptetés nem tenant-döntés, hanem HATÁRIDŐ-kérdés.
 * Tenantonként futtatva ugyanaz a `WHERE due_at <= …` feltétel ezerszer futna le ugyanarra
 * a táblára.
 *
 * NAPI futás: a türelmi idők napokban vannak (7 és 30 nap a határidő után), tehát a
 * percenkénti léptetés semmit nem adna — az állapot naponta legfeljebb egyszer változhat.
 */
final class AdvanceDunningCommand extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'A számlázási állapotgép léptetése: open → due → overdue → suspended.';

    public function handle(Dunning $dunning): int
    {
        $moved = $dunning->advance();

        $this->info(sprintf(
            'Léptetve — esedékes: %d, késett: %d, felfüggesztve: %d',
            $moved['due'],
            $moved['overdue'],
            $moved['suspended'],
        ));

        return self::SUCCESS;
    }
}
