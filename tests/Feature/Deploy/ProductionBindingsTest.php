<?php

declare(strict_types=1);

use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Consent\Services\DatabaseConsentChecker;
use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Gate\PreSendGate;
use App\Modules\Sending\Providers\SendingServiceProvider;
use App\Modules\Validation\Services\ValidationRiskScorer;

/*
 | Az éles container (deploy-ovh): a kapuk külső függőségei közül CSAK a ventPay lehet fake,
 | amíg a ventPay nem él (CLAUDE.md). A consent- és a kockázati kapu VALÓDI implementációt
 | kap — a fake-jük némán kinyitná a 3. és a 6. kaput.
 */

it('élesben a consent- és kockázati kapu valódi implementációt kap', function () {
    expect(app(ConsentChecker::class))->toBeInstanceOf(DatabaseConsentChecker::class);
    expect(app(AddressRiskScorer::class))->toBeInstanceOf(ValidationRiskScorer::class);
});

it('fake-mentes élesben a ventPay hiánya HANGOSAN megállítja a kapu-láncot — nem nyit ki csendben', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['sending.allow_fake_dependencies' => false]);

    (new SendingServiceProvider(app()))->register();

    expect(fn () => app(VentPayClient::class))->toThrow(RuntimeException::class, 'ventPay');
    expect(fn () => app(PreSendGate::class))->toThrow(RuntimeException::class, 'ventPay');
});
