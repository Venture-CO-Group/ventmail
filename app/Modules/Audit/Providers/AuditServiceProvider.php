<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Console\PruneAuditLogsCommand;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Audit modul — megváltoztathatatlan napló minden admin-műveletről (golden rule 11).
 */
final class AuditServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneAuditLogsCommand::class]);
        }
    }
}
