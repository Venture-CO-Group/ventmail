<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Gate\GateDecision;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Testing\FakeAddressRiskScorer;
use App\Modules\Sending\Testing\FakeConsentChecker;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/*
 | M0-3 elfogadási kritérium #17–#21 — a PreSendGate hat kapuja KÜLÖN-KÜLÖN.
 | A gate BLOCKING és megkerülhetetlen: a REST-en és az SMTP relayen beadott üzenet is
 | ezen megy át (golden rule 2).
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->domain = Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
    ]);

    RateLimiter::clear('sending:'.$this->workspace->id);
});

function send(array $overrides = []): GateDecision
{
    return app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
    ], $overrides))->decision;
}

it('1. kapu — verifikálatlan domainről nincs küldés', function () {
    $this->domain->forceFill(['status' => 'pending', 'verified_at' => null])->save();

    $decision = send();

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::DomainUnverified->value);
});

it('2. kapu — suppression-listás címzett blokkolva', function () {
    Suppression::create(['email' => 'cimzett@example.test', 'reason' => 'bounce']);

    $decision = send();

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::Suppressed->value);
});

it('2. kapu — a GLOBÁLIS suppression is blokkol', function () {
    Suppression::onPlatform(fn () => Suppression::create([
        'email' => 'cimzett@example.test',
        'reason' => 'complaint',
    ]));

    expect(send()->reason)->toBe(GateReason::Suppressed->value);
});

it('3. kapu — marketing consent nélkül blokkolva', function () {
    app()->instance(ConsentChecker::class, new FakeConsentChecker(confirmed: false));

    $decision = send(['channel' => 'marketing']);

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::NoConsent->value);
});

it('4. kapu — kvótán túl blokkolva', function () {
    config(['sending.fake_entitlement.remaining' => 0]);

    $decision = send();

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::QuotaExceeded->value);
});

it('5. kapu — rate-limit felett blokkolva', function () {
    config(['sending.rate_limit.per_minute' => 2]);

    send();
    send();
    $decision = send();

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::RateLimited->value);
});

it('6. kapu — kockázati küszöb felett blokkolva', function () {
    app()->instance(AddressRiskScorer::class, new FakeAddressRiskScorer(score: 0.95));
    config(['sending.validation.risk_threshold' => 0.8]);

    $decision = send();

    expect($decision->allowed)->toBeFalse();
    expect($decision->reason)->toBe(GateReason::ValidationRisk->value);
});

it('6. kapu — a küszöb alatt átenged', function () {
    app()->instance(AddressRiskScorer::class, new FakeAddressRiskScorer(score: 0.2));
    config(['sending.validation.risk_threshold' => 0.8]);

    expect(send()->allowed)->toBeTrue();
});

it('a transactional üzenetet marketing-leiratkozás NEM blokkolja', function () {
    // Golden rule 4: a `transactional` ≠ `marketing`. Egy hírlevél-leiratkozás nem
    // némíthatja el a jelszó-visszaállító levelet.
    Suppression::create([
        'email' => 'cimzett@example.test',
        'reason' => 'unsubscribe',
        'applies_to' => 'marketing',
    ]);

    expect(send(['channel' => 'transactional'])->allowed)->toBeTrue();
    expect(send(['channel' => 'marketing'])->reason)->toBe(GateReason::Suppressed->value);
});

it('a transactional csatornán nincs consent-követelmény', function () {
    app()->instance(ConsentChecker::class, new FakeConsentChecker(confirmed: false));

    expect(send(['channel' => 'transactional'])->allowed)->toBeTrue();
});

it('minden gate-döntés auditált, a hat kapu eredményével', function () {
    send();

    $log = AuditLog::query()->where('action', 'sending.gate.allowed')->latest('id')->first();

    expect($log)->not->toBeNull('az elfogadás nem volt auditált');
    // Hat nevesített kapu + három nem kapcsolható előkapu: bemenet-épség, fejléc-biztonság
    // és tartalom-biztonság (link-séma a VÉGLEGES, behelyettesített törzsön).
    expect($log->after['gates'])->toHaveCount(9);

    Suppression::create(['email' => 'tiltott@example.test', 'reason' => 'bounce']);
    send(['to' => 'tiltott@example.test']);

    $rejected = AuditLog::query()->where('action', 'sending.gate.rejected')->latest('id')->firstOrFail();

    expect($rejected->after['reason'])->toBe(GateReason::Suppressed->value);
    // A trace megmutatja, meddig jutott a lánc (A3 üzenet-trace alapja).
    expect($rejected->after['gates'])->toHaveKey('domain');
});

it('a kapuk sorrendben futnak, az első elutasítás megállítja a láncot', function () {
    $this->domain->forceFill(['status' => 'pending'])->save();
    Suppression::create(['email' => 'cimzett@example.test', 'reason' => 'bounce']);

    // A domain-kapu van elöl: az ő oka jön vissza, nem a suppressioné.
    expect(send()->reason)->toBe(GateReason::DomainUnverified->value);
});
