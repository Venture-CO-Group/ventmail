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
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Sending\Testing\FakeAddressRiskScorer;
use App\Modules\Sending\Testing\FakeConsentChecker;
use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Tenancy\Services\WorkspaceContext;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sending modul — a küldés szíve: SendingDriver + PreSendGate.
 */
final class SendingServiceProvider extends ModuleServiceProvider
{
    /**
     * A kapuk SORRENDJE — ez maga is szabály (spec 6.2).
     *
     * A hat nevesített kapu elé két OLCSÓ, nem kapcsolható előkapu került:
     * - bemenet-épség (üres címzettlista, ismeretlen csatorna, rossz szintaxis),
     * - fejléc-biztonság (Bcc/From felülírás, CRLF-injekció).
     * Enélkül a ciklusos kapuk üres listán „passed"-del tértek vissza.
     *
     * A rate-limit és a kvóta előre került: az elutasított kérés is auditot ír, az
     * audit-tábla pedig SOHA nem törölhető — korlátlan elutasítás tárhely-DoS lenne egy
     * visszaút nélküli táblán.
     *
     * A TARTALOM-KAPU a rate-limit MÖGÉ került: ő a legdrágább (a törzset és minden
     * behelyettesítendő értéket végigolvassa), tehát előtte kell álljon az a két kapu,
     * amelyik a kérések SZÁMÁT fogja. Előtte egy hívás korlátlanul vitte a CPU-t.
     */
    private const GATES = [
        Gates\RecipientSanityGate::class,
        Gates\HeaderSafetyGate::class,
        Gates\RateLimitGate::class,
        Gates\EntitlementGate::class,
        Gates\ContentSafetyGate::class,
        Gates\DomainVerifiedGate::class,
        Gates\SuppressionGate::class,
        Gates\ConsentGate::class,
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
        //
        // ÉLESBEN a fake-ek némán kinyitnák a kaput: a FakeVentPayClient configból ad
        // kvótát (4. kapu no-op), a FakeAddressRiskScorer konstans alacsony kockázatot
        // (6. kapu majdnem no-op). Ezért produkciós környezetben a bekötés HANGOSAN dob,
        // amíg a valódi kliens nincs bekötve (C1, A5).
        if ($this->app->isProduction() && ! config('sending.allow_fake_dependencies')) {
            $this->app->bind(ConsentChecker::class, fn () => throw new RuntimeException(
                'Éles környezetben nincs valódi ConsentChecker bekötve (B1). '
                .'A fake a consent-kaput kinyitná.'
            ));
            $this->app->bind(AddressRiskScorer::class, fn () => throw new RuntimeException(
                'Éles környezetben nincs valódi AddressRiskScorer bekötve (A5).'
            ));
            $this->app->bind(VentPayClient::class, fn () => throw new RuntimeException(
                'Éles környezetben nincs valódi ventPay kliens bekötve (C1). '
                .'A fake a kvóta-kaput kinyitná.'
            ));

            return;
        }

        $this->app->bind(ConsentChecker::class, FakeConsentChecker::class);
        $this->app->bind(AddressRiskScorer::class, FakeAddressRiskScorer::class);
        $this->app->bind(VentPayClient::class, FakeVentPayClient::class);

        $this->app->bind(PreSendGate::class, fn ($app) => new PreSendGate(
            array_map(fn (string $gate) => $app->make($gate), self::GATES),
            $app->make(AuditLogger::class),
            $app->make(WorkspaceContext::class),
        ));

        // A drivert KIZÁRÓLAG a MessageDispatcher kapja meg: így a container maga is
        // kikényszeríti, hogy más osztály ne jusson hozzá (golden rule 2).
        $this->app->when(MessageDispatcher::class)
            ->needs(SendingDriver::class)
            ->give(fn ($app) => $app->make(SendingDriver::class));
    }
}
