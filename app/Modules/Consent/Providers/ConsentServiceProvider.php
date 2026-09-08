<?php

declare(strict_types=1);

namespace App\Modules\Consent\Providers;

use App\Modules\Consent\Services\DatabaseConsentChecker;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Support\ModuleServiceProvider;

/**
 * Consent modul — double opt-in, bizonyíték-történet, preference center (B1).
 *
 * ITT VÁLT A KAPU VALÓDIRA. Az M0-3 óta a PreSendGate 3. kapuja a `FakeConsentChecker`-t
 * kérdezte, ami egy in-memory tömb — élesben tehát MINDEN marketing levél átment volna. A
 * csere CSAK egy kötés, ahogy a golden rule-ok előírják: a kapu kódja nem változik.
 *
 * A kötés a `SendingServiceProvider` UTÁN fut (a modul-sorrend adja), tehát ez az utolsó szó
 * — és a `ProductionBindingGuardTest` őrzi, hogy éles környezetben ne a fake maradjon.
 */
final class ConsentServiceProvider extends ModuleServiceProvider
{
    /**
     * A modul web-route-jai a LEVÉLBŐL nyílnak meg (megerősítés, preference center) —
     * a hozzáférést a titkosított, lejáró token adja, nem a session.
     */
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    public function register(): void
    {
        // A 3. KAPU VALÓDI ADATBÓL (51. kritérium).
        $this->app->bind(ConsentChecker::class, DatabaseConsentChecker::class);
    }
}
