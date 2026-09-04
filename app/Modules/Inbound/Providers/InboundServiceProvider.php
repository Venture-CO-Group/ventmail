<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Providers;

use App\Modules\Inbound\Console\PurgeInboundCommand;
use App\Modules\Inbound\Console\ServeMxCommand;
use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Contracts\InboundSink;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Services\ClamAvScanner;
use App\Modules\Inbound\Services\InboundIngest;
use App\Modules\Inbound\Services\S3RawMailStore;
use App\Modules\Inbound\Services\VerifiedInboundResolver;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Inbound modul — MX-fogadás, sandboxolt parse, route-motor.
 */
final class InboundServiceProvider extends ModuleServiceProvider
{
    // A `/v1` API-KULCCSAL hitelesít, nem böngésző-session-nel: a kontextus magából a
    // kulcsból származik, tehát az `auth` + `workspace` páros itt nem értelmezett. A saját
    // őrt (`api.auth`) viszont kötelező megadni — teszt kényszeríti ki.
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    public function register(): void
    {
        // Külső gazdák interfész mögött (fájltár, vírusirtó) — a fake a teszttel fedett
        // kontraktus, és a csere config, nem domain-kód.
        $this->app->bind(RawMailStore::class, S3RawMailStore::class);
        $this->app->bind(VirusScanner::class, ClamAvScanner::class);

        $this->app->bind(InboundRecipientResolver::class, VerifiedInboundResolver::class);
        $this->app->bind(InboundSink::class, InboundIngest::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeInboundCommand::class, ServeMxCommand::class]);
        }
    }
}
