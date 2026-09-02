<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consent\Models\Consent;
use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Sending\Data\SendResult;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\PreSendGate;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | Támadási vektorok a PreSendGate ellen. Mindegyiket a security-review PoC-cal igazolta:
 | a hat kapu külön-külön helyes volt, együtt mégis megkerülhető, mert a BEMENET nem volt
 | normalizálva és validálva.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    Suppression::create([
        'email' => 'leiratkozott@example.test',
        'reason' => 'unsubscribe',
        'applies_to' => 'marketing',
    ]);
});

function attempt(array $overrides = []): SendResult
{
    return app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
    ], $overrides));
}

it('csatorna-hamisítással sem megy ki marketing leiratkozott címre', function () {
    // A `channel` szabad szöveg volt: 'promo' esetén a ConsentGate „nem marketing" címen
    // átengedett, a SuppressionGate pedig az applies_to='marketing' sort nem is nézte.
    $result = attempt(['channel' => 'promo', 'to' => 'leiratkozott@example.test']);

    expect($result->decision->allowed)->toBeFalse();
});

it('ismeretlen csatorna elutasítva, nem „nem marketing"-ként átengedve', function () {
    expect(attempt(['channel' => 'Marketing'])->decision->allowed)->toBeFalse();
    expect(attempt(['channel' => 'akarmi'])->decision->allowed)->toBeFalse();
});

it('a journey és campaign csatorna consentköteles', function () {
    foreach (['journey', 'campaign', 'marketing'] as $channel) {
        $result = attempt(['channel' => $channel, 'to' => 'nincs-consent@example.test']);

        expect($result->decision->reason)->toBe(GateReason::NoConsent->value, "csatorna: {$channel}");
    }
});

it('Bcc fejléccel nem lehet a kapuk mellett címzettet becsempészni', function () {
    // A fejlécek szűretlenül mentek a driverhez: egy Bcc olyan címzettet adott hozzá,
    // akit EGYETLEN kapu sem látott — se suppression, se consent, se kvóta.
    $result = attempt([
        'headers' => ['Bcc' => 'leiratkozott@example.test', 'From' => 'ceo@bank.example'],
    ]);

    expect($result->decision->allowed)->toBeFalse();
});

it('CRLF-injekció a tárgyban és a fejlécben elutasítva', function () {
    expect(attempt(['subject' => "Teszt\r\nBcc: aldozat@example.test"])->decision->allowed)->toBeFalse();
    expect(attempt(['headers' => ["X-Egyedi\r\nBcc" => 'aldozat@example.test']])->decision->allowed)->toBeFalse();
});

it('az X- fejlécek viszont átmennek', function () {
    expect(attempt(['headers' => ['X-Kampany' => 'tavasz']])->decision->allowed)->toBeTrue();
});

it('üres címzettlistával nem megy át a lánc', function () {
    // `foreach ([] as ...)` egyetlen ciklusos kapuban sem futott le → mind „passed".
    $result = attempt(['to' => []]);

    expect($result->decision->allowed)->toBeFalse();
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(0);
});

it('érvénytelen címzett-szintaxis elutasítva a kapcsolható kapu nélkül is', function () {
    config(['sending.validation.enabled' => false]);

    expect(attempt(['to' => 'nem-email'])->decision->allowed)->toBeFalse();
});

it('visszavont consent nem enged marketinget', function () {
    Consent::create([
        'email' => 'visszavont@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDay(),
        'revoked_at' => now(),
    ]);

    $result = attempt(['channel' => 'marketing', 'to' => 'visszavont@example.test']);

    expect($result->decision->reason)->toBe(GateReason::NoConsent->value);
});

it('érvényes consent viszont átenged', function () {
    Consent::create([
        'email' => 'megerositett@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDay(),
    ]);

    expect(attempt(['channel' => 'marketing', 'to' => 'megerositett@example.test'])->decision->allowed)->toBeTrue();
});

it('a rate-limit a CÍMZETTEK számával fogy, nem hívásonként', function () {
    config(['sending.rate_limit.per_minute' => 10]);

    $recipients = array_map(fn ($i) => "cimzett{$i}@example.test", range(1, 8));
    expect(attempt(['to' => $recipients])->decision->allowed)->toBeTrue();

    // 8 elfogyott a 10-ből: egy újabb 8-as batch már nem fér bele.
    expect(attempt(['to' => $recipients])->decision->reason)->toBe(GateReason::RateLimited->value);
});

it('a nyers címzett-cím nem kerül a törölhetetlen audit-naplóba', function () {
    attempt(['channel' => 'marketing', 'to' => 'leiratkozott@example.test']);

    $logs = AuditLog::query()->get();
    $serialized = (string) json_encode($logs->map(fn ($log) => [$log->before, $log->after, $log->context]));

    expect($serialized)->not->toContain('leiratkozott@example.test');
    // A döntés viszont visszakereshető marad.
    expect($serialized)->toContain('SUPPRESSED');
});

it('a nagybetűs feladó domain is hitelesítettnek számít', function () {
    expect(attempt(['from_email' => 'No-Reply@PELDA.HU'])->decision->allowed)->toBeTrue();
});

it('a gate workspace-kontextus nélkül hangosan megáll', function () {
    $message = Message::query()->first()
        ?? attempt()->message;

    app(WorkspaceContext::class)->forget();

    expect(fn () => app(PreSendGate::class)->evaluate($message))
        ->toThrow(RuntimeException::class);
});

it('kapu-hiba esetén a lánc fail-closed és auditált', function () {
    app()->instance(AddressRiskScorer::class, new class implements AddressRiskScorer
    {
        public function riskFor(string $email): float
        {
            throw new RuntimeException('a kockázat-szolgáltatás nem elérhető');
        }
    });

    $result = attempt();

    expect($result->decision->allowed)->toBeFalse();
    expect($result->decision->reason)->toBe(GateReason::GateError->value);

    $log = AuditLog::query()->where('action', 'sending.gate.rejected')->latest('id')->firstOrFail();
    // A kivétel szövege NEM kerül a naplóba (titok-szivárgás ellen).
    expect((string) json_encode($log->after))->not->toContain('nem elérhető');
});
