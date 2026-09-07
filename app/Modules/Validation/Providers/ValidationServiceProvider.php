<?php

declare(strict_types=1);

namespace App\Modules\Validation\Providers;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Validation\Console\PurgeValidationCommand;
use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Services\RateLimitedProbe;
use App\Modules\Validation\Services\SmtpMailboxProbe;
use App\Modules\Validation\Services\ValidationRiskScorer;
use App\Modules\Validation\Testing\FakeMailboxProbe;

/**
 * Validation modul — a nyolc ellenőrzés, a szonda és a bulk futás.
 */
final class ValidationServiceProvider extends ModuleServiceProvider
{
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    public function register(): void
    {
        // A SZONDA mindig a fék MÖGÖTT áll. Aki közvetlenül a `SmtpMailboxProbe`-ot kötné
        // be, az cache és per-domain keret nélkül szondázna — és a fogadó ezt
        // címfelderítésnek venné, a mi IP-nkről.
        $this->app->bind(MailboxProbe::class, function ($app) {
            $inner = $app->environment('testing')
                ? $app->make(FakeMailboxProbe::class)
                : $app->make(SmtpMailboxProbe::class);

            return new RateLimitedProbe(
                $inner,
                $app->make(DnsResolver::class),
                $app->make(WorkspaceContext::class),
            );
        });

        // A tesztek EGY fake-példányt lássanak: enélkül a szonda-számláló minden feloldásnál
        // nullázódna, és a rate-limit meg a cache bizonyíthatatlan lenne.
        $this->app->singleton(FakeMailboxProbe::class);

        // A PreSendGate 6. kapuja MOSTANTÓL a valódi láncból dönt (42. kritérium).
        $this->app->bind(AddressRiskScorer::class, ValidationRiskScorer::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeValidationCommand::class]);
        }
    }
}
