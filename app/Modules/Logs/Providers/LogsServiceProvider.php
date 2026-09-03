<?php

declare(strict_types=1);

namespace App\Modules\Logs\Providers;

use App\Modules\Logs\Console\PurgeLogsCommand;
use App\Modules\Logs\Console\RedactSubjectCommand;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Logs modul — kereshető napló, üzenet-trace, SMTP-párbeszéd.
 */
final class LogsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeLogsCommand::class, RedactSubjectCommand::class]);
        }
    }
}
