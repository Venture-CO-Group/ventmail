<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | Negatív vektorok a titok-szűrésre. Az audit-sor SOSEM törölhető, tehát ami egyszer
 | bekerül, az véglegesen bent marad — a szűrésnek itt kell megfognia mindent.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
});

function auditedContext(array $context): string
{
    app(AuditLogger::class)->log('teszt.muvelet', context: $context);

    return (string) json_encode(AuditLog::query()->latest('id')->firstOrFail()->context);
}

it('objektum-értékben rejtett titkot is kiszűr', function () {
    $dto = new class
    {
        public int $ok = 1;

        public string $password = 'NYERS-OBJEKTUMBAN';
    };

    expect(auditedContext(['dto' => $dto]))->not->toContain('NYERS-OBJEKTUMBAN');
});

it('JSON-sztringbe ágyazott titkot is kiszűr', function () {
    $payload = json_encode(['password' => 'NYERS-JSONBAN', 'user' => 'lathato']);

    $stored = auditedContext(['raw_body' => $payload]);

    expect($stored)->not->toContain('NYERS-JSONBAN');
});

it('az OIDC- és aláírás-tranzienseket is ismeri', function () {
    $stored = auditedContext([
        'code_verifier' => 'PKCE-TITOK',
        'authorization_code' => 'OIDC-CODE',
        'signature' => 'HMAC-NYERS',
        'refresh' => 'REFRESH-NYERS',
        'hash_pepper' => 'PEPPER-TITOK',
        'bearer' => 'Bearer abc',
    ]);

    foreach (['PKCE-TITOK', 'OIDC-CODE', 'HMAC-NYERS', 'REFRESH-NYERS', 'PEPPER-TITOK', 'Bearer abc'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
});

it('érték-mintára is véd, nem csak kulcsnévre', function () {
    // Egy ártatlan nevű mező is hordozhat kulcsot; a kulcsnév-alapú szűrés önmagában
    // nem elég védőháló.
    $stored = auditedContext([
        'value' => 'vm_live_EGESZ_NYERS_KULCS_1234567890',
        'blob' => "-----BEGIN PRIVATE KEY-----\nMIIE",
    ]);

    expect($stored)->not->toContain('vm_live_EGESZ_NYERS_KULCS_1234567890');
    expect($stored)->not->toContain('BEGIN PRIVATE KEY');
});

it('a suppression audit-sora nem tartalmaz nyílt e-mail-címet', function () {
    // Az audit-tábla sosem törölhető; egy nyílt cím ott örökre bent maradna.
    Suppression::create(['email' => 'erintett@example.test', 'reason' => 'manual']);

    $log = AuditLog::query()->where('auditable_type', Suppression::class)->latest('id')->firstOrFail();
    $serialized = json_encode([$log->before, $log->after]);

    expect($serialized)->not->toContain('erintett@example.test');
    expect($serialized)->toContain('reason');
});

it('a túl mély vagy túl nagy payload nem dönti el a műveletet', function () {
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => ['j' => 'melyen']]]]]]]]]];

    $log = app(AuditLogger::class)->log('teszt.muvelet', context: array_merge($deep, [
        'big' => str_repeat('x', 200_000),
    ]));

    expect($log->exists)->toBeTrue();
    expect(strlen((string) json_encode($log->context)))->toBeLessThan(100_000);
});
