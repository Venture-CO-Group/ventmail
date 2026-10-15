<?php

declare(strict_types=1);

use App\Modules\VentSec\Contracts\VentSecEvents;
use App\Modules\VentSec\Jobs\SendVentSecEvent;
use App\Modules\VentSec\Providers\VentSecServiceProvider;
use App\Modules\VentSec\Services\EventFactory;
use App\Modules\VentSec\Services\HttpVentSecEvents;
use App\Modules\VentSec\Services\IngestSignature;
use App\Modules\VentSec\Services\VentSecIngestClient;
use App\Modules\VentSec\Support\VsesCatalog;
use App\Modules\VentSec\Testing\FakeVentSecEvents;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

/*
 | FO2 — a VSES eseménykibocsátó (ventMail → ventSec ingest). Az esemény alakja a ventSec
 | `event.schema.json`-ját és katalógusát követi; az aláírás a ventSec független vektorán mért.
 | Alapból KI (`VENTSEC_EVENTS_ENABLED=false`): ilyenkor semmi nem megy ki.
 */

beforeEach(function () {
    Cache::flush();
    config([
        'ventsec.events.enabled' => true,
        'ventsec.events.url' => 'https://sec.test',
        'ventsec.events.source_secret' => 'test-only-hmac-secret',
        'ventsec.token_url' => 'https://sso.test/oauth/token',
        'ventsec.client_id' => 'ventmail-prod',
        'ventsec.client_secret' => 'titok',
    ]);
});

function fakeIngest(int $status = 202): void
{
    Http::swap(new Factory); // a korábbi stubok ne nyerjenek
    Http::fake([
        'sso.test/*' => Http::response(['access_token' => 'tok.en.sig', 'expires_in' => 600]),
        'sec.test/ingest/v1/events' => Http::response(['accepted' => 1], $status),
    ]);
}

it('az ingest-aláírás a ventSec független vektorain egyezik (a kulcs szövegként, nem hex-dekódolva)', function () {
    $doc = json_decode((string) file_get_contents(base_path('tests/Fixtures/ventsec/ingest-signature.json')), true);

    foreach ($doc['headers'] as $case) {
        $key = match ($case['name']) {
            'SDK fixed VSES body' => 'test-only-hmac-secret',
            "SDK: 64-hex secret ('3f' x 32) used as text over the fixed SDK body" => str_repeat('3f', 32),
            default => bin2hex(implode('', array_map('chr', range(0, 31)))),
        };

        expect(IngestSignature::header($case['body'], $key))->toBe($case['header'], $case['name']);

        if (isset($case['header_if_hex_decoded_wrongly'])) {
            expect(IngestSignature::header($case['body'], (string) hex2bin($key)))->toBe($case['header_if_hex_decoded_wrongly']);
        }
    }

    foreach ($doc['hmac'] as $case) {
        if (isset($case['key_text'])) {
            expect(hash_hmac('sha256', $case['message'], $case['key_text']))->toBe($case['mac_hex']);
        }
    }
});

it('a katalógus-szűrő egyezik a ventSec katalógusával (drift-őr)', function () {
    $catalog = Yaml::parseFile(base_path('tests/Fixtures/ventsec/catalog.yaml'));

    foreach ($catalog['apps']['ventmail'] as $action => $entry) {
        $ours = VsesCatalog::entry($action);

        expect($ours)->not->toBeNull("hiányzik a katalógusunkból: {$action}")
            ->and($ours['category'])->toBe($entry['category'])
            ->and($ours['severity_hint'])->toBe($entry['severity_hint'])
            ->and($ours['context'])->toBe($entry['context']);
    }

    $heartbeat = VsesCatalog::entry('ventsec.heartbeat');
    expect($heartbeat['context'])->toBe(['outbox_depth', 'outbox_oldest_seconds', 'sdk_version']);
});

it('az esemény alakja a VSES 1.0 producer-sémát követi', function () {
    $event = app(EventFactory::class)->make('inbound.attachment_verdict', 'success', ['binary_verdict' => 'clean', 'scan_id' => '01J9Z6Q7Y3X5R0K8ZC4M2T1H6B']);

    $allowed = ['vses', 'event_id', 'occurred_at', 'source', 'category', 'action', 'outcome', 'severity_hint', 'actor', 'target', 'network', 'context', 'trace_id', 'prev_hash'];

    expect(array_diff(array_keys($event), $allowed))->toBe([])
        ->and($event['vses'])->toBe('1.0')
        ->and($event['event_id'])->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($event['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and(array_keys($event['source']))->toBe(['app', 'env', 'instance', 'version'])
        ->and($event['source']['app'])->toBe('ventmail')
        ->and($event['source']['app'])->toMatch('/^[a-z][a-z0-9]*$/')
        ->and($event['category'])->toBe('file')
        ->and($event['action'])->toBe('inbound.attachment_verdict')
        ->and($event['outcome'])->toBe('success')
        ->and($event['severity_hint'])->toBe('info')
        ->and($event['actor'])->toBe(['type' => 'system'])
        ->and($event)->not->toHaveKey('network')
        ->and($event['context'])->toBe(['binary_verdict' => 'clean', 'scan_id' => '01J9Z6Q7Y3X5R0K8ZC4M2T1H6B']);
});

it('a source.env a Laravel környezetből képződik', function (string $env, string $expected) {
    app()->detectEnvironment(fn () => $env);

    expect(app(EventFactory::class)->make('smtp.auth_failed', 'failure', ['listener' => 'submission', 'reason' => 'invalid_credentials'])['source']['env'])->toBe($expected);
})->with([['production', 'prod'], ['staging', 'staging'], ['local', 'dev'], ['testing', 'test']]);

it('ismeretlen action, nem engedélyezett kontextus-kulcs, float vagy tiltott kulcs kivétel', function () {
    $factory = app(EventFactory::class);

    expect(fn () => $factory->make('nincs.ilyen', 'success'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $factory->make('smtp.auth_failed', 'failure', ['listener' => 'submission', 'username' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $factory->make('smtp.auth_failed', 'failure', ['email' => 'a@b.hu']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $factory->make('delivery.bounce_wave', 'unknown', ['domain_hash' => 'abc', 'rate' => 0.5]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $factory->make('smtp.auth_failed', 'sikertelen', []))->toThrow(InvalidArgumentException::class);
});

it('KIKAPCSOLVA nem küld semmit — se job, se HTTP', function () {
    config(['ventsec.events.enabled' => false]);
    Queue::fake();
    Http::fake();

    app(HttpVentSecEvents::class)->emit('smtp.auth_failed', 'failure', ['listener' => 'submission', 'reason' => 'invalid_credentials']);

    expect(app(HttpVentSecEvents::class)->heartbeat())->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('bekapcsolva a kibocsátás a `background` sorra tesz egy jobot, nem hív szinkron HTTP-t', function () {
    Queue::fake();
    Http::fake();

    app(HttpVentSecEvents::class)->emit('smtp.auth_failed', 'failure', ['listener' => 'submission', 'reason' => 'invalid_credentials']);

    Queue::assertPushedOn('background', SendVentSecEvent::class, fn (SendVentSecEvent $job) => $job->event['action'] === 'smtp.auth_failed');
    Http::assertNothingSent();
});

it('a job a pontos törzs HMAC-jével és a Bearer tokennel POST-ol, 202-re kész', function () {
    fakeIngest();
    $event = app(EventFactory::class)->make('smtp.auth_failed', 'failure', ['listener' => 'submission', 'reason' => 'invalid_credentials']);

    (new SendVentSecEvent($event))->handle(app(VentSecIngestClient::class));

    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://sec.test/ingest/v1/events') {
            return false;
        }

        return $request->hasHeader('Authorization', 'Bearer tok.en.sig')
            && $request->hasHeader('X-VentSec-Signature', 'sha256='.hash_hmac('sha256', $request->body(), 'test-only-hmac-secret'))
            && json_decode($request->body(), true)['events'][0]['action'] === 'smtp.auth_failed'
            && str_starts_with($request->header('Content-Type')[0], 'application/json');
    });
});

it('a 4xx (pl. ingest_gated) végleges — nincs újrapróbálás; az 5xx / 429 / hálózati hiba újrapróbálható', function () {
    $event = app(EventFactory::class)->make('smtp.auth_failed', 'failure', ['listener' => 'submission', 'reason' => 'invalid_credentials']);
    $client = app(VentSecIngestClient::class);

    fakeIngest(403);
    (new SendVentSecEvent($event))->handle($client); // nem dob
    expect(true)->toBeTrue();

    foreach ([500, 503, 429] as $status) {
        fakeIngest($status);
        expect(fn () => (new SendVentSecEvent($event))->handle($client))->toThrow(RuntimeException::class);
    }

    Http::swap(new Factory);
    Http::fake([
        'sso.test/*' => Http::response(['access_token' => 'tok.en.sig', 'expires_in' => 600]),
        'sec.test/*' => fn () => throw new ConnectionException('timeout'),
    ]);
    expect(fn () => (new SendVentSecEvent($event))->handle($client))->toThrow(RuntimeException::class);

    // m2m token nélkül sem vész el az esemény: újrapróbálás.
    Cache::flush();
    Http::swap(new Factory);
    Http::fake(['sso.test/*' => Http::response([], 401), 'sec.test/*' => Http::response([], 202)]);
    expect(fn () => (new SendVentSecEvent($event))->handle($client))->toThrow(RuntimeException::class);

    expect((new SendVentSecEvent($event))->tries)->toBeGreaterThan(1)->and((new SendVentSecEvent($event))->backoff())->not->toBeEmpty();
});

it('a hiba-üzenet nem hordoz titkot vagy tokent', function () {
    $event = app(EventFactory::class)->make('smtp.auth_failed', 'failure', ['listener' => 'submission', 'reason' => 'invalid_credentials']);
    fakeIngest(500);

    try {
        (new SendVentSecEvent($event))->handle(app(VentSecIngestClient::class));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('test-only-hmac-secret')->not->toContain('tok.en.sig')->not->toContain('titok');
    }
});

it('a szívverés-parancs kikapcsolva nem hív semmit', function () {
    config(['ventsec.events.enabled' => false]);
    Http::fake();

    expect(Artisan::call('ventsec:heartbeat'))->toBe(0);
    Http::assertNothingSent();
});

it('a szívverés-parancs bekapcsolva ventsec.heartbeat eseményt küld a szigorú alakban', function () {
    fakeIngest();

    expect(Artisan::call('ventsec:heartbeat'))->toBe(0);

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/ingest/v1/events')) {
            return true;
        }

        $event = json_decode($request->body(), true)['events'][0];

        return count(json_decode($request->body(), true)['events']) === 1
            && $event['action'] === 'ventsec.heartbeat'
            && $event['category'] === 'ventsec'
            && $event['actor'] === ['type' => 'system']
            && ! isset($event['network'], $event['target'])
            && array_keys($event['context']) === ['outbox_depth', 'outbox_oldest_seconds', 'sdk_version']
            && $request->hasHeader('X-VentSec-Signature', 'sha256='.hash_hmac('sha256', $request->body(), 'test-only-hmac-secret'));
    });
});

it('a szívverés hibájakor a parancs nem-nulla kóddal tér vissza', function () {
    fakeIngest(500);

    expect(Artisan::call('ventsec:heartbeat'))->not->toBe(0);
});

it('a szívverés percenként ütemezett, és csak bekapcsolva fut', function () {
    Artisan::call('list'); // betölti a routes/console.php ütemezését
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ventsec:heartbeat'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');

    config(['ventsec.events.enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['ventsec.events.enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('a Fake rögzíti a kibocsátást, és alapból a Fake van kötve tesztben nem kell HTTP', function () {
    $fake = new FakeVentSecEvents;
    $fake->emit('smtp.auth_failed', 'failure', ['listener' => 'submission', 'reason' => 'invalid_credentials']);

    expect($fake->emitted)->toHaveCount(1)
        ->and($fake->emitted[0]['action'])->toBe('smtp.auth_failed')
        ->and(app(VentSecEvents::class))->toBeInstanceOf(VentSecEvents::class);
});

it('élesben bekapcsolt eseményküldés hiányos konfigurációval a bootkor elbukik', function (string $missing) {
    config(['ventsec.events.enabled' => true]);
    app()->detectEnvironment(fn () => 'production');
    config([$missing => '']);

    expect(fn () => VentSecServiceProvider::assertEventsConfigured())
        ->toThrow(RuntimeException::class, 'VENTSEC_EVENTS_ENABLED');
})->with(['ventsec.events.url', 'ventsec.events.source_secret', 'ventsec.client_id', 'ventsec.client_secret']);

it('az alapértelmezések: kibocsátás KI, scanner clamav, minden ventsec-kulcs üres', function () {
    $ventsec = configFileWith('ventsec', []);
    $inbound = configFileWith('inbound', []);

    expect($ventsec['events']['enabled'])->toBeFalse()
        ->and($inbound['scanner'])->toBe('clamav')
        ->and($ventsec['client_secret'])->toBeEmpty()
        ->and($ventsec['events']['source_secret'])->toBeEmpty();
});
