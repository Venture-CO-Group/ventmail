<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | FO — az átmeneti kapu-elutasítás NEM köti le az Idempotency-Key-t. Egy determinisztikus
 | kulccsal küldő kliens (ventSSO, Vemoa, …) a SENDING_RESTRICTED / RATE_LIMITED / QUOTA_EXCEEDED
 | után ugyanazzal a kulccsal és törzzsel újrapróbálhat, és a kapu ÚJRA lefut. A végleges
 | elutasítás (SUPPRESSED, NO_CONSENT, INVALID_REQUEST…) továbbra is visszajátszódik.
 */

beforeEach(function () {
    FakeDriver::reset();
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send']);
});

function sendKeyed(string $key, array $extra = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->plain, 'Idempotency-Key' => $key, 'Accept' => 'application/json'])
        ->postJson('/v1/messages', array_merge([
            'from' => 'no-reply@pelda.hu', 'to' => 'cimzett@example.test', 'subject' => 'Szia', 'html' => '<p>x</p>',
        ], $extra));
}

it('SENDING_RESTRICTED után ugyanazzal a kulccsal és törzzsel, a feltétel megszűnte után 202 — egyszer kézbesítve', function () {
    config(['sending.live' => false, 'sending.allowlist_enforced' => true, 'sending.allowlist' => []]);

    sendKeyed('fixed-key-1')->assertStatus(422)->assertJsonPath('error.code', 'SENDING_RESTRICTED');
    expect(FakeDriver::$sent)->toBe([]);

    config(['sending.live' => true, 'sending.allowlist_enforced' => false]);

    $retry = sendKeyed('fixed-key-1')->assertAccepted();
    expect(FakeDriver::$sent)->toHaveCount(1);

    // A sikeres küldés kulcsa már lekötött: ugyanaz a kulcs ugyanazt a választ adja, nem küld újra.
    $again = sendKeyed('fixed-key-1')->assertAccepted();
    expect($again->json('id'))->toBe($retry->json('id'))->and(FakeDriver::$sent)->toHaveCount(1);
});

it('az átmenetileg elutasított üzenet naplója megmarad, csak a kulcs szabadul fel', function () {
    config(['sending.live' => false, 'sending.allowlist_enforced' => true, 'sending.allowlist' => []]);

    $first = sendKeyed('fixed-key-2')->assertStatus(422);
    $row = Message::query()->where('uuid', $first->json('error.details.id') ?? $first->json('id') ?? '')->first()
        ?? Message::query()->firstOrFail();

    expect($row->status)->toBe('rejected')->and($row->reason)->toBe('SENDING_RESTRICTED')->and($row->idempotency_key)->toBeNull();
});

it('az átmeneti elutasítás után eltérő törzsű, azonos kulcsú kérés sem kap 409-et — új kérés', function () {
    config(['sending.live' => false, 'sending.allowlist_enforced' => true, 'sending.allowlist' => []]);
    sendKeyed('fixed-key-3')->assertStatus(422);

    config(['sending.live' => true, 'sending.allowlist_enforced' => false]);
    sendKeyed('fixed-key-3', ['subject' => 'Más tárgy'])->assertAccepted();
});

it('a VÉGLEGES elutasítás (SUPPRESSED) továbbra is visszajátszódik, a kapu nem fut újra', function () {
    Suppression::create(['email' => 'cimzett@example.test', 'reason' => 'unsubscribe', 'applies_to' => 'all']);

    sendKeyed('fixed-key-4')->assertStatus(422)->assertJsonPath('error.code', 'SUPPRESSED');

    Suppression::query()->delete();

    sendKeyed('fixed-key-4')->assertStatus(422)->assertJsonPath('error.code', 'SUPPRESSED');
    expect(FakeDriver::$sent)->toBe([])->and(Message::query()->count())->toBe(1);
});

it('az elfogadott küldés párhuzamos duplikátuma továbbra is deduplikál', function () {
    sendKeyed('fixed-key-5')->assertAccepted();
    sendKeyed('fixed-key-5')->assertAccepted();
    sendKeyed('fixed-key-5', ['subject' => 'Más'])->assertStatus(409);

    expect(FakeDriver::$sent)->toHaveCount(1)->and(Message::query()->count())->toBe(1);
});

it('az átmeneti okok listája: a kapu-állapottól függők igen, a kérés-függők nem', function () {
    foreach (['DOMAIN_UNVERIFIED', 'QUOTA_EXCEEDED', 'RATE_LIMITED', 'SENDING_RESTRICTED', 'CHANNEL_DISABLED', 'GATE_ERROR'] as $reason) {
        expect(GateReason::from($reason)->isTransient())->toBeTrue($reason);
    }

    foreach (['SUPPRESSED', 'NO_CONSENT', 'INVALID_REQUEST', 'VALIDATION_RISK', 'IN_PROGRESS', 'IDEMPOTENCY_KEY_REUSED'] as $reason) {
        expect(GateReason::from($reason)->isTransient())->toBeFalse($reason);
    }
});
