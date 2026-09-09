<?php

declare(strict_types=1);

namespace App\Modules\Journey\Providers;

use App\Modules\Journey\Console\DetectSegmentEntriesCommand;
use App\Modules\Journey\Console\PurgeJourneyRunsCommand;
use App\Modules\Journey\Console\TickJourneysCommand;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Journey modul — Customer Journey motor: trigger → condition → action → wait → branch, idempotens lépésekkel.
 */
final class JourneyServiceProvider extends ModuleServiceProvider
{
    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TickJourneysCommand::class,
                PurgeJourneyRunsCommand::class,
                DetectSegmentEntriesCommand::class,
            ]);
        }
    }
}
