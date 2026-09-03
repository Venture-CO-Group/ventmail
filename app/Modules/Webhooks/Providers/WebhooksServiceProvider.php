<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Providers;

use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Webhooks\Console\RetryWebhooksCommand;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Services\SystemHostResolver;
use App\Modules\Webhooks\Services\WebhookDispatcher;

/**
 * Webhooks modul — aláírt esemény-kiküldés, retry, dead-letter, replay.
 */
final class WebhooksServiceProvider extends ModuleServiceProvider
{
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    public function register(): void
    {
        // Külső gazda (DNS) interfész mögött — a tesztben determinisztikus fake áll a
        // helyén, így az SSRF-védelem valódi címekre mérve bizonyítható, hálózat nélkül.
        $this->app->bind(HostResolver::class, SystemHostResolver::class);

        // SCOPED, nem singleton: a végpontlista-memoizálás EGY kérés / EGY job élettartamára
        // szól. A hosszan élő worker több tenant jobját futtatja egymás után; egy örökké
        // élő példány a következő tenant eseményeit az előző végpontjaira küldené.
        $this->app->scoped(WebhookDispatcher::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RetryWebhooksCommand::class]);
        }
    }
}
