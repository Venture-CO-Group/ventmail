<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Providers;

use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Webhooks\Console\RetryWebhooksCommand;

/**
 * Webhooks modul — aláírt esemény-kiküldés, retry, dead-letter, replay.
 */
final class WebhooksServiceProvider extends ModuleServiceProvider
{
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RetryWebhooksCommand::class]);
        }
    }
}
