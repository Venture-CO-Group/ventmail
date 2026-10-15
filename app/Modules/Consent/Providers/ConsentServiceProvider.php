<?php

declare(strict_types=1);

namespace App\Modules\Consent\Providers;

use App\Modules\Consent\Contracts\ConsentProofLedger;
use App\Modules\Consent\Services\DatabaseConsentChecker;
use App\Modules\Consent\Services\HttpConsentProofLedger;
use App\Modules\Consent\Testing\FakeConsentProofLedger;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Support\ModuleServiceProvider;
use InvalidArgumentException;
use RuntimeException;

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

        // FO3: a bizonyíték-főkönyv meghajtója (CONSENT_LEDGER_DRIVER = fake | ventcookie).
        $this->app->bind(ConsentProofLedger::class, fn ($app) => match (self::ledgerDriver()) {
            'ventcookie' => $app->make(HttpConsentProofLedger::class),
            default => $app->make(FakeConsentProofLedger::class),
        });
    }

    protected function bootModule(): void
    {
        self::ledgerDriver();
    }

    /**
     * A főkönyv-meghajtó kapcsolója — FAIL-CLOSED (mint a többi éles guard): ismeretlen érték hiba,
     * élesben/stagingen a hiányos ventcookie-konfiguráció boot-hiba (különben a bizonyítékok némán
     * elmaradnának). A `fake` az alap, amíg a ventCookie API nem él.
     */
    public static function ledgerDriver(): string
    {
        $driver = (string) config('consent.ledger.driver');

        if (! in_array($driver, ['fake', 'ventcookie'], true)) {
            throw new InvalidArgumentException("Ismeretlen CONSENT_LEDGER_DRIVER: „{$driver}\" (fake | ventcookie).");
        }

        if ($driver === 'ventcookie' && app()->environment('production', 'staging')) {
            $missing = array_keys(array_filter([
                'CONSENT_LEDGER_URL' => config('consent.ledger.url'),
                'CONSENT_LEDGER_TOKEN' => config('consent.ledger.token'),
                'CONSENT_LEDGER_SITE_ID' => config('consent.ledger.site_id'),
                'CONSENT_LEDGER_SUBJECT_KEY' => config('consent.ledger.subject_key'),
            ], fn ($value) => (string) $value === ''));

            if ($missing !== []) {
                throw new RuntimeException('CONSENT_LEDGER_DRIVER=ventcookie, de hiányzik: '.implode(', ', $missing).'.');
            }

            // A HMAC-kulcs rövidsége az alany-hivatkozás szótár-támadhatóságát jelentené.
            if (strlen((string) config('consent.ledger.subject_key')) < 32) {
                throw new RuntimeException('CONSENT_LEDGER_DRIVER=ventcookie: a CONSENT_LEDGER_SUBJECT_KEY legalább 32 bájt legyen.');
            }
        }

        return $driver;
    }
}
