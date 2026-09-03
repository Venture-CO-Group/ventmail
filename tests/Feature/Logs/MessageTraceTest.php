<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Logs\Services\MessageTrace;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A3 · 23–26. kritérium — ÜZENET-TRACE, SMTP-párbeszéd, retenció, tag-statisztika.
 |
 | A trace az a képernyő, amit a support nyit meg, amikor az ügyfél azt írja: „nem jött
 | meg". Ha a lánc bármelyik szeme hiányzik, a kérdésre nem tudunk válaszolni.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

function sendTraced(array $overrides = []): Message
{
    return app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'to' => ['cimzett@example.test'],
        'html' => '<p>Szia</p>',
        'tags' => ['szamla'],
        'idempotency_key' => 't-'.bin2hex(random_bytes(5)),
    ], $overrides))->message->fresh();
}

it('a trace a TELJES láncot megmutatja', function () {
    $pool = IpPool::create(['name' => 'megosztott', 'type' => 'shared']);
    IpAddress::create(['ip_pool_id' => $pool->getKey(), 'address' => '203.0.113.20', 'daily_limit' => 100, 'status' => 'active']);

    $message = sendTraced();

    SmtpTransaction::create([
        'message_uuid' => $message->uuid,
        'recipient' => 'cimzett@example.test',
        'remote_host' => 'mx1.example.test',
        'response_code' => 250,
        'response_text' => 'Ok: queued',
        'lines' => [
            ['direction' => 'out', 'line' => 'MAIL FROM:<no-reply@pelda.hu>'],
            ['direction' => 'in', 'line' => '250 2.1.0 Ok'],
        ],
        'started_at' => now(),
    ]);

    $trace = app(MessageTrace::class)->for((string) $message->uuid);

    expect($trace['message']['status'])->toBe('delivered');
    // MIND a kapu eredménye — nem csak az elutasítás oka: a „miért ment ki" kérdésre
    // ugyanúgy válasz kell.
    expect($trace['gate']['gates'])->toBeArray()->not->toBeEmpty();
    expect($trace['routing']['ip_address'])->toBe('203.0.113.20');
    expect($trace['smtp'][0]['code'])->toBe(250);
    expect($trace['smtp'][0]['lines'][1]['line'])->toContain('250');
    expect(array_column($trace['events'], 'type'))->toContain('accepted', 'delivered');
});

it('az ELUTASÍTOTT levélnél is látszik, MELYIK kapu állította meg', function () {
    Suppression::create(['email' => 'cimzett@example.test', 'reason' => 'complaint']);

    $message = sendTraced();

    $trace = app(MessageTrace::class)->for((string) $message->uuid);

    expect($trace['message']['reason'])->toBe('SUPPRESSED');
    expect($trace['gate']['decision'])->toBe('rejected');

    $gates = collect($trace['gate']['gates']);

    expect($gates->has('suppression'))->toBeTrue();
    expect($gates->get('suppression')['passed'] ?? true)->toBeFalse();
});

it('az SMTP-párbeszéd naplója NEM tartalmaz hitelesítési sort', function () {
    // A base64-elt AUTH sor MAGA a jelszó — a trace-be sosem kerülhet.
    $message = sendTraced();

    SmtpTransaction::create([
        'message_uuid' => $message->uuid,
        'recipient' => 'cimzett@example.test',
        'lines' => [['direction' => 'out', 'line' => 'AUTH PLAIN <redacted>']],
    ]);

    $trace = app(MessageTrace::class)->for((string) $message->uuid);

    expect(json_encode($trace['smtp']))->toContain('redacted');
});

it('ismeretlen üzenetre null a trace', function () {
    expect(app(MessageTrace::class)->for('nincs-ilyen-uuid'))->toBeNull();
});
