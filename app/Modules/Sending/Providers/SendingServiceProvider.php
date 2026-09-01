<?php

declare(strict_types=1);

namespace App\Modules\Sending\Providers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Billing\Testing\FakeVentPayClient;
use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Drivers\LogDriver;
use App\Modules\Sending\Drivers\PostalDriver;
use App\Modules\Sending\Gate\Gates;
use App\Modules\Sending\Gate\PreSendGate;
use App\Modules\Sending\Testing\FakeAddressRiskScorer;
use App\Modules\Sending\Testing\FakeConsentChecker;
use App\Modules\Support\ModuleServiceProvider;
use InvalidArgumentException;

/**
 * Sending modul — a küldés szíve: SendingDriver + PreSendGate.
 */
final class SendingServiceProvider extends ModuleServiceProvider
{
    /** A hat kapu SORRENDJE — ez a sorrend maga is szabály (spec 6.2). */
    private const GATES = [
        Gates\DomainVerifiedGate::class,
        Gates\SuppressionGate::class,
        Gates\ConsentGate::class,
        Gates\EntitlementGate::class,
        Gates\RateLimitGate::class,
        Gates\ValidationRiskGate::class,
    ];

    public function register(): void
    {
        $this->app->bind(SendingDriver::class, fn () => match ((string) config('sending.driver')) {
            'fake' => new FakeDriver,
            'log' => new LogDriver,
            'postal' => new PostalDriver,
            default => throw new InvalidArgumentException(
                'Ismeretlen SENDING_DRIVER: '.config('sending.driver').' (fake|log|postal)'
            ),
        });

        // Külső gazdák interfész mögött; élesedéskor CSAK ez a kötés vált.
        $this->app->bind(ConsentChecker::class, FakeConsentChecker::class);
        $this->app->bind(AddressRiskScorer::class, FakeAddressRiskScorer::class);
        $this->app->bind(VentPayClient::class, FakeVentPayClient::class);

        $this->app->bind(PreSendGate::class, fn ($app) => new PreSendGate(
            array_map(fn (string $gate) => $app->make($gate), self::GATES),
            $app->make(AuditLogger::class),
        ));
    }
}
