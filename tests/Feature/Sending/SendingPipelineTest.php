<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 | A2 · a küldési lánc EGYBEN: IP-választás → DKIM-aláírás → driver.
 |
 | A darabok külön is teszteltek; ez a teszt arra van, hogy a KÖTÉSEK meglegyenek. Egy
 | helyesen megírt aláíró semmit nem ér, ha a küldési út nem hívja meg.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->domain = Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'dkim_selector' => 'vm202609',
        'dkim_private_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pem')),
        'dkim_public_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem')),
    ]);
});

function sendOne(array $overrides = []): Message
{
    $result = app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'to' => ['cimzett@example.test'],
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'p-'.bin2hex(random_bytes(5)),
    ], $overrides));

    return $result->message->fresh();
}

it('a kiküldött levél ALÁ VAN ÍRVA, és a driver az aláírt MIME-ot kapja', function () {
    sendOne();

    $mime = FakeDriver::$sent[0]['bodies']['cimzett@example.test']['mime'];

    expect($mime)->toContain('DKIM-Signature:');
    expect($mime)->toContain('d=pelda.hu');
    expect($mime)->toContain('s=vm202609');
});

it('kulcs nélküli domainről aláírás nélkül megy — de a küldés nem áll meg', function () {
    // A DKIM hiánya a domain-hitelesítés kérdése (1. kapu). Ha a domain zöld, de kulcs
    // nincs (régi rekord), a levél kimegy — a kézbesíthetőségi figyelmeztetés az A6-é.
    $this->domain->forceFill(['dkim_selector' => null, 'dkim_private_key' => null])->save();

    $message = sendOne();

    expect($message->status)->toBe('delivered');
    expect(FakeDriver::$sent[0]['bodies']['cimzett@example.test']['mime'])->toBeNull();
});

/*
 | IP-választás a küldési úton.
 */

it('a küldéshez IP foglalódik le, és a napi számláló nő', function () {
    $pool = IpPool::create(['name' => 'megosztott', 'type' => 'shared']);
    $address = IpAddress::create([
        'ip_pool_id' => $pool->getKey(),
        'address' => '203.0.113.10',
        'daily_limit' => 10,
        'status' => 'active',
    ]);

    $message = sendOne();

    expect($message->ip_address_id)->toBe($address->getKey());
    expect($address->fresh()->sent_today)->toBe(1);
});

it('ha nincs szabad IP, a levél VÁR — nem megy ki limit fölött', function () {
    $pool = IpPool::create(['name' => 'kimeritett', 'type' => 'shared']);
    IpAddress::create([
        'ip_pool_id' => $pool->getKey(),
        'address' => '203.0.113.11',
        'daily_limit' => 1,
        'sent_today' => 1,
        'sent_on' => now()->toDateString(),
        'status' => 'active',
    ]);

    $message = sendOne();

    expect($message->status)->toBe('deferred');
    expect($message->reason)->toBe('NO_SENDING_IP');
    expect($message->next_attempt_at)->not->toBeNull();
    expect(FakeDriver::$sent)->toBeEmpty();
});

it('a karanténba tett IP mellett sem megy ki a levél', function () {
    $pool = IpPool::create(['name' => 'beteg', 'type' => 'shared']);
    IpAddress::create([
        'ip_pool_id' => $pool->getKey(),
        'address' => '203.0.113.12',
        'daily_limit' => 1000,
        'status' => 'quarantined',
    ]);

    expect(sendOne()->status)->toBe('deferred');
    expect(FakeDriver::$sent)->toBeEmpty();
});

it('a PostalDriver az ALÁÍRT MIME-ot küldi, nem szerkeszti újra', function () {
    // Bármilyen újraszerkesztés (fejléc-átrendezés, újrakódolás) érvénytelenné tenné az
    // aláírást, és a fogadó hamisítványnak látná a levelet.
    config([
        'sending.driver' => 'postal',
        'sending.postal.endpoint' => 'https://postal.test',
        'sending.postal.api_key' => 'teszt-'.bin2hex(random_bytes(6)),
    ]);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'p-1']])]);

    sendOne();

    Http::assertSent(function ($request) {
        $decoded = base64_decode((string) ($request['data'] ?? ''), true);

        return str_contains((string) $request->url(), '/send/raw')
            && is_string($decoded)
            && str_contains($decoded, 'DKIM-Signature:');
    });
});

/*
 | 20. kritérium — a bounce-osztályozó a VALÓSÁGBAN.
 */

it('a hard bounce AZONNAL suppression-listára teszi a címet', function () {
    // Az osztályozó nem önmagáért van: a nem létező címre küldés minden további
    // alkalommal a reputációt rombolja.
    config([
        'sending.driver' => 'postal',
        'sending.postal.endpoint' => 'https://postal.test',
        'sending.postal.api_key' => 'teszt-'.bin2hex(random_bytes(6)),
    ]);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['code' => 550, 'message' => 'User unknown']], 400)]);

    $message = sendOne();

    expect($message->status)->toBe('deferred');
    expect(Suppression::query()->where('email', 'cimzett@example.test')->exists())->toBeTrue();
    expect(DeliveryAttempt::query()->where('classification', 'hard')->count())->toBe(1);
});

it('a soft bounce NEM zárja ki azonnal a címzettet', function () {
    // „550 5.2.2 over quota": a postafiók tele van, a cím jó. Ha hardként kezelnénk, egy
    // nyaraló ügyfelet veszítenénk el véglegesen.
    config([
        'sending.driver' => 'postal',
        'sending.postal.endpoint' => 'https://postal.test',
        'sending.postal.api_key' => 'teszt-'.bin2hex(random_bytes(6)),
    ]);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['code' => 452, 'message' => 'over quota']], 400)]);

    sendOne();

    expect(Suppression::query()->where('email', 'cimzett@example.test')->exists())->toBeFalse();
    expect(DeliveryAttempt::query()->where('classification', 'mailbox_full')->count())->toBe(1);
});
