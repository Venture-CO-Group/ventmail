<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Smtp\Services\GateReasonToSmtpReply;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/*
 | A B2 utómunkái (security re-review): versenyvesztes hívás, `queued` visszajátszás, régi sorok
 | kulcs-felszabadítása, ütemezett (dispatcher-oldali) elutasítás, SMTP 4xx leképezés.
 */

it('S3: egy még döntetlen (`queued`) sor visszajátszása 409 IN_PROGRESS — nem 202, és más kód, mint az IDEMPOTENCY_KEY_REUSED', function () {
    Message::create(['channel' => 'transactional', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['cimzett@example.test'], 'subject' => 'Szia', 'headers' => [], 'idempotency_key' => 'queued-key-1']);

    $response = sendKeyed('queued-key-1')->assertStatus(409);

    expect($response->json('error.code'))->toBe('IN_PROGRESS')->not->toBe('IDEMPOTENCY_KEY_REUSED')
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and(FakeDriver::$sent)->toBe([]);
});

it('S2: a versenyt vesztő hívás, ha a nyertes közben felszabadította a kulcsot, nem 500 — újrapróbálja, és elfogadott', function () {
    $armed = true;
    $selects = 0;

    // A nyertes sor beszúrása a vesztes INSERT ELŐTT; a vesztes második lekérdezése előtt a nyertes
    // kulcsa felszabadul (átmeneti elutasítás).
    Message::creating(function () use (&$armed) {
        if ($armed) {
            $armed = false;
            DB::table('messages')->insert([
                'uuid' => (string) Str::uuid(), 'workspace_id' => app(WorkspaceContext::class)->workspaceId(),
                'channel' => 'transactional', 'from_email' => 'no-reply@pelda.hu', 'recipients' => '["cimzett@example.test"]',
                'status' => 'rejected', 'reason' => 'RATE_LIMITED', 'idempotency_key' => 'race-key-1', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });
    DB::beforeExecuting(function (string $query) use (&$selects) {
        if (str_contains($query, 'select') && str_contains($query, '"idempotency_key" = ') && str_contains($query, 'from "messages"')) {
            $selects++;

            if ($selects === 2) {
                DB::table('messages')->where('idempotency_key', 'race-key-1')->update(['idempotency_key' => null]);
            }
        }
    });

    sendKeyed('race-key-1')->assertAccepted();
    expect(FakeDriver::$sent)->toHaveCount(1);
});

it('N2: a régi, átmenetileg elutasított sorok kulcsát a parancs felszabadítja (idempotens), a végleseket nem', function () {
    $make = fn (string $key, string $reason) => Message::create([
        'channel' => 'transactional', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['a@example.test'], 'subject' => 'x', 'headers' => [],
        'status' => 'rejected', 'reason' => $reason, 'idempotency_key' => $key,
    ]);
    $make('k-rate', 'RATE_LIMITED');
    $make('k-restricted', 'SENDING_RESTRICTED');
    $make('k-supp', 'SUPPRESSED');
    Message::create(['channel' => 'transactional', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['a@example.test'], 'subject' => 'x', 'headers' => [], 'status' => 'accepted', 'idempotency_key' => 'k-ok']);

    expect(Artisan::call('sending:release-transient-keys'))->toBe(0);
    expect(Artisan::call('sending:release-transient-keys'))->toBe(0);

    expect(Message::query()->whereNotNull('idempotency_key')->orderBy('idempotency_key')->pluck('idempotency_key')->all())->toBe(['k-ok', 'k-supp']);
});

it('N3: a dispatcher-oldali (küldéskori) átmeneti elutasítás is felszabadítja a kulcsot', function () {
    $message = Message::create([
        'channel' => 'transactional', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['cimzett@example.test'], 'subject' => 'x', 'headers' => [],
        'tags' => [], 'status' => 'accepted', 'gate_decided_at' => now(), 'idempotency_key' => 'sched-key', 'options' => ['body_html' => '<p>x</p>'],
    ]);

    config(['sending.live' => false, 'sending.allowlist_enforced' => true, 'sending.allowlist' => []]);
    app(MessageDispatcher::class)->dispatch($message);

    $fresh = $message->fresh();
    expect($fresh->status)->toBe('rejected')->and($fresh->reason)->toBe('SENDING_RESTRICTED')->and($fresh->idempotency_key)->toBeNull();
});

it('N4: az átmeneti okok SMTP-n 4xx, a végleges 5xx', function () {
    foreach (['SENDING_RESTRICTED', 'CHANNEL_DISABLED', 'QUOTA_EXCEEDED', 'RATE_LIMITED', 'GATE_ERROR', 'IN_PROGRESS'] as $reason) {
        expect(GateReasonToSmtpReply::map($reason, 'u')->code)->toBeBetween(400, 499, $reason);
    }

    // A domain-hitelesítés SMTP-n szándékosan permanens (meglévő tesztelt viselkedés).
    foreach (['DOMAIN_UNVERIFIED', 'SUPPRESSED', 'NO_CONSENT', 'VALIDATION_RISK', 'INVALID_REQUEST'] as $reason) {
        expect(GateReasonToSmtpReply::map($reason, 'u')->code)->toBeBetween(500, 599, $reason);
    }
});
