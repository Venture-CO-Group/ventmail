<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Logs\Services\MessageTrace;
use App\Modules\Sending\Actions\RecordDeliveryOutcome;
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
    // A base64-elt AUTH sor MAGA a jelszó — nem elrejtve, csak átkódolva. A NYERS
    // párbeszédet adjuk be, ahogy a driver látná: a redakciónak KELL megtörténnie, nem
    // nekünk kell előre redaktálva beírni.
    $message = sendTraced();

    $titok = base64_encode("\0relay\0Titk0sJelsz0");

    app(RecordDeliveryOutcome::class)(
        $message,
        'cimzett@example.test',
        451,
        'Temporary failure',
        '4.3.0',
        [
            ['direction' => 'in', 'line' => '220 mx1.example.test ESMTP'],
            ['direction' => 'out', 'line' => 'AUTH PLAIN '.$titok],
            ['direction' => 'in', 'line' => '235 2.7.0 Authentication successful'],
            ['direction' => 'out', 'line' => 'MAIL FROM:<no-reply@pelda.hu>'],
        ],
    );

    $trace = app(MessageTrace::class)->for((string) $message->uuid);

    $naplo = json_encode($trace['smtp']);

    expect($naplo)->not->toContain($titok);
    expect($naplo)->not->toContain('Titk0sJelsz0');
    // A parancs NEVE marad: a support épp azt akarja látni, hogy a hitelesítés megtörtént.
    expect($naplo)->toContain('AUTH PLAIN');
    expect($naplo)->toContain('redacted');
    expect($naplo)->toContain('MAIL FROM');
});

it('az AUTH LOGIN KÖVETKEZŐ sorai is kiesnek', function () {
    // Az `AUTH LOGIN`-nál a felhasználónév és a jelszó KÜLÖN sorban, parancsszó nélkül jön:
    // önmagában ártatlan base64-nek látszik. Ezért tart állapotot a redakció.
    $message = sendTraced();

    app(RecordDeliveryOutcome::class)(
        $message,
        'cimzett@example.test',
        451,
        'Temporary failure',
        null,
        [
            ['direction' => 'out', 'line' => 'AUTH LOGIN'],
            ['direction' => 'in', 'line' => '334 VXNlcm5hbWU6'],
            ['direction' => 'out', 'line' => base64_encode('relay-user')],
            ['direction' => 'in', 'line' => '334 UGFzc3dvcmQ6'],
            ['direction' => 'out', 'line' => base64_encode('Titk0sJelsz0')],
            ['direction' => 'in', 'line' => '235 2.7.0 Authentication successful'],
        ],
    );

    $naplo = json_encode(app(MessageTrace::class)->for((string) $message->uuid)['smtp']);

    expect($naplo)->not->toContain(base64_encode('relay-user'));
    expect($naplo)->not->toContain(base64_encode('Titk0sJelsz0'));
    expect($naplo)->toContain('AUTH LOGIN');
});

it('a párbeszéd TÖBBI sora viszont megmarad — ez a lényege', function () {
    $message = sendTraced();

    app(RecordDeliveryOutcome::class)(
        $message,
        'cimzett@example.test',
        550,
        'No such user here',
        '5.1.1',
        [
            ['direction' => 'out', 'line' => 'RCPT TO:<nincs-ilyen@example.test>'],
            ['direction' => 'in', 'line' => '550 5.1.1 No such user here'],
        ],
    );

    $smtp = app(MessageTrace::class)->for((string) $message->uuid)['smtp'][0];

    expect($smtp['lines'][0]['line'])->toBe('RCPT TO:<nincs-ilyen@example.test>');
    expect($smtp['lines'][1]['line'])->toContain('550 5.1.1');
});

it('ismeretlen üzenetre null a trace', function () {
    expect(app(MessageTrace::class)->for('nincs-ilyen-uuid'))->toBeNull();
});

it('a TÖBB KÖRÖS hitelesítés minden sora kiesik', function () {
    // Az RFC 4954 SASL-párbeszéde több körös: az `AUTH PLAIN` initial-response NÉLKÜLI
    // alakjánál és a `CRAM-MD5`-nél a hitelesítő KÜLÖN sorban, parancsszó nélkül érkezik —
    // ártatlan base64-nek látszik. A szabály ezért nem „az AUTH LOGIN után két sor", hanem:
    // az AUTH után MINDEN kliens-sor, amíg a szerver véglegesen le nem zárja.
    $message = sendTraced();

    app(RecordDeliveryOutcome::class)(
        $message,
        'cimzett@example.test',
        451,
        'Temporary failure',
        null,
        [
            ['direction' => 'out', 'line' => 'AUTH PLAIN'],
            ['direction' => 'in', 'line' => '334 '],
            ['direction' => 'out', 'line' => base64_encode("user\0user\0TITKOS789")],
            ['direction' => 'in', 'line' => '235 2.7.0 Authentication successful'],
            ['direction' => 'out', 'line' => 'MAIL FROM:<no-reply@pelda.hu>'],
        ],
    );

    $naplo = json_encode(app(MessageTrace::class)->for((string) $message->uuid)['smtp']);

    expect($naplo)->not->toContain(base64_encode("user\0user\0TITKOS789"));
    expect($naplo)->not->toContain('TITKOS789');
    // …a hitelesítés UTÁNI sor viszont megmarad: a lezárás után már nincs mit rejteni.
    expect($naplo)->toContain('MAIL FROM');
});

it('a CRAM-MD5 válasza sem marad nyersen', function () {
    $message = sendTraced();

    app(RecordDeliveryOutcome::class)(
        $message,
        'cimzett@example.test',
        451,
        'Temporary failure',
        null,
        [
            ['direction' => 'out', 'line' => 'AUTH CRAM-MD5'],
            ['direction' => 'in', 'line' => '334 PDEyMz4='],
            ['direction' => 'out', 'line' => base64_encode('user TITKOS000')],
            ['direction' => 'in', 'line' => '535 5.7.8 Authentication credentials invalid'],
            ['direction' => 'out', 'line' => 'QUIT'],
        ],
    );

    $naplo = json_encode(app(MessageTrace::class)->for((string) $message->uuid)['smtp']);

    expect($naplo)->not->toContain('TITKOS000');
    expect($naplo)->not->toContain(base64_encode('user TITKOS000'));
    // Az 5xx is LEZÁR: a `QUIT` már nem hitelesítő.
    expect($naplo)->toContain('QUIT');
});
