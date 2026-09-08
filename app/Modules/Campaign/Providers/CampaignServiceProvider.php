<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Providers;

use App\Modules\Campaign\Console\DispatchScheduledCampaignsCommand;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Campaign modul — Kampány-életciklus, célzás, A/B variánsok, időzóna-tudatos ütemezés.
 */
final class CampaignServiceProvider extends ModuleServiceProvider
{
    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DispatchScheduledCampaignsCommand::class]);
        }
    }
}
