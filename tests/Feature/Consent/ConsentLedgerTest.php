<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consent\Contracts\ConsentProofLedger;
use App\Modules\Consent\Jobs\SendConsentProof;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Consent\Providers\ConsentServiceProvider;
use App\Modules\Consent\Services\ConsentLedgerReporter;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Consent\Services\HttpConsentProofLedger;
use App\Modules\Consent\Testing\FakeConsentProofLedger;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Contracts\ConsentChecker;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | FO3 — a ventCookie e-mail-consent főkönyv kliense (ventMail → ventCookie, C3).
 |
 | A ventMail a double opt-in bizonyítékát (kérés, megerősítés, visszavonás) egy hash-láncolt,
 | append-only főkönyvbe is leteszi. A helyi consent MARAD A HIVATALOS (a küldési kapu abból
 | dönt): a főkönyv-hiba soha nem blokkolja a helyi folyamatot. A főkönyvbe NYERS cím, IP, UA
 | nem megy: csak a HMAC-elt alany-hivatkozás és zárt mezőkészletű bizonyíték.
 */

const LEDGER_KEY = 'subject-key-for-tests-only';

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    config([
        'consent.ledger.driver' => 'ventcookie',
        'consent.ledger.url' => 'https://cookie.test',
        'consent.ledger.token' => 'svc-token',
        'consent.ledger.site_id' => '0b4f1c6e-8d3a-4a57-9e0f-2d7a6c1b9e11',
        'consent.ledger.subject_key' => LEDGER_KEY,
    ]);

    fakeLedger();
});

/** @param list<array{0: int, 1: array}>|null $responses */
function fakeLedger(int $status = 201, ?Closure $callback = null): void
{
    Http::swap(new Factory);
    Http::fake(['cookie.test/*' => $callback ?? function (Request $request) use ($status) {
        $body = json_decode($request->body(), true);

        return Http::response($status === 201 ? [
            'receipt_id' => 'rcpt_'.substr(hash('sha256', $request->header('Idempotency-Key')[0] ?? ''), 0, 16),
            'site_id' => $body['site_id'], 'subject_ref' => $body['subject_ref'], 'scope_ref' => $body['scope_ref'],
            'purpose' => $body['purpose'], 'action' => $body['action'], 'recorded_at' => now()->toIso8601String(),
            'proof' => ['record_hash' => str_repeat('a', 64), 'chain_sequence' => 7],
        ] : ['error' => 'x'], $status);
    }]);
}

function ledgerCalls(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/api/internal/v1/email-consent'))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

function array_last_call(): Request
{
    $calls = ledgerCalls();

    return $calls[count($calls) - 1];
}

function ledgerBody(Request $request): array
{
    return json_decode($request->body(), true);
}

it('a beiratkozási KÉRÉS opt_in_requested bizonyítékot tesz a főkönyvbe — a pontos szerződés szerint', function () {
    $consent = app(ConsentRecorder::class)->request('  Anna@Pelda.HU ', 'marketing', 'form', '203.0.113.9', 'Mozilla/5.0 (X11) SecretUA', '2026-10');

    $calls = ledgerCalls();

    expect($calls)->toHaveCount(1);

    $request = $calls[0];
    $body = ledgerBody($request);

    expect($request->url())->toBe('https://cookie.test/api/internal/v1/email-consent')
        ->and($request->method())->toBe('POST')
        ->and($request->hasHeader('Authorization', 'Bearer svc-token'))->toBeTrue()
        ->and($request->header('Idempotency-Key')[0])->toStartWith('vm-consent-')
        ->and(strlen($request->header('Idempotency-Key')[0]))->toBeLessThanOrEqual(128)
        ->and(array_keys($body))->toEqualCanonicalizing(['site_id', 'subject_ref', 'scope_ref', 'purpose', 'action', 'occurred_at', 'evidence'])
        ->and($body['site_id'])->toBe('0b4f1c6e-8d3a-4a57-9e0f-2d7a6c1b9e11')
        ->and($body['subject_ref'])->toBe(hash_hmac('sha256', $this->workspace->uuid.'|anna@pelda.hu', LEDGER_KEY))
        ->and($body['subject_ref'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($body['scope_ref'])->toBe('ws:'.$this->workspace->uuid.'/channel:marketing')
        ->and($body['scope_ref'])->toMatch('/^[A-Za-z0-9:_.\/-]{1,128}$/')
        ->and($body['purpose'])->toBe('email_marketing')
        ->and($body['action'])->toBe('opt_in_requested')
        ->and(Carbon\Carbon::parse($body['occurred_at'])->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and(array_keys($body['evidence']))->toEqualCanonicalizing(['method', 'requested_at', 'consent_text_version', 'source_ref', 'legal_basis'])
        ->and($body['evidence']['method'])->toBe('double_opt_in')
        ->and($body['evidence']['legal_basis'])->toBe('consent')
        ->and($body['evidence']['consent_text_version'])->toBe('2026-10')
        ->and($body['evidence']['source_ref'])->toBe('consent:'.$consent->uuid)
        ->and(strlen($body['evidence']['source_ref']))->toBeLessThanOrEqual(128);
});

it('NYERS cím, IP és user-agent SOHA nem megy a főkönyvbe', function () {
    app(ConsentRecorder::class)->request('anna@pelda.hu', 'marketing', 'form', '203.0.113.9', 'Mozilla/5.0 SecretUA', '2026-10');

    $consent = Consent::query()->firstOrFail();
    app(ConsentRecorder::class)->confirm($consent, '203.0.113.9', 'Mozilla/5.0 SecretUA');
    app(ConsentRecorder::class)->revoke($consent->fresh(), 'preference_center', '203.0.113.9');

    expect(ledgerCalls())->toHaveCount(3);

    foreach (ledgerCalls() as $request) {
        $dump = $request->body().json_encode($request->headers()).$request->url();

        expect($dump)->not->toContain('anna')->not->toContain('pelda.hu')->not->toContain('203.0.113.9')
            ->not->toContain('SecretUA')->not->toContain('Mozilla');
    }
});

it('a MEGERŐSÍTÉS opt_in_confirmed, és a receipt_id a helyi consent-rekordra kerül', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu', 'marketing', 'form');
    $recorder->confirm($consent);

    $calls = ledgerCalls();
    $body = ledgerBody($calls[1]);

    expect($body['action'])->toBe('opt_in_confirmed')
        ->and($body['evidence']['method'])->toBe('double_opt_in')
        ->and($body['evidence'])->toHaveKeys(['requested_at', 'confirmed_at'])
        ->and(Carbon\Carbon::parse($body['evidence']['confirmed_at'])->diffInSeconds(now(), true))->toBeLessThan(5);

    $fresh = Consent::query()->firstOrFail();

    expect($fresh->ledger_receipt_id)->toBe('rcpt_'.substr(hash('sha256', $calls[1]->header('Idempotency-Key')[0]), 0, 16))
        ->and($fresh->ledger_receipt_at)->not->toBeNull()
        // A helyi consent a HIVATALOS: a főkönyv a státuszt nem írja át.
        ->and($fresh->status)->toBe('confirmed');
});

it('a VISSZAVONÁS withdrawn bizonyíték, a forrásnak megfelelő módszerrel', function (?string $source, string $method) {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu', 'marketing', 'form');
    $recorder->confirm($consent);
    $recorder->revoke($consent->fresh(), $source);

    $body = ledgerBody(ledgerCalls()[2]);

    expect($body['action'])->toBe('withdrawn')->and($body['evidence']['method'])->toBe($method);
})->with([['preference_center', 'preference_center'], ['unsubscribe_link', 'unsubscribe_link'], [null, 'api'], ['admin', 'api']]);

it('az egykattintásos leiratkozás withdrawn(unsubscribe_link) — a helyi consentet nem írja át', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@example.test', 'marketing', 'form');
    $recorder->confirm($consent);

    Domain::query()->firstOrCreate(['name' => 'pelda.hu'], ['status' => 'verified', 'verified_at' => now()]);
    $message = Message::create(['channel' => 'marketing', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['anna@example.test'], 'subject' => 'Hírlevél', 'headers' => []]);
    $token = app(UnsubscribeTokens::class)->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'anna@example.test');

    $this->post('/u/'.$token)->assertOk();
    app(WorkspaceContext::class)->set($this->workspace);

    $calls = ledgerCalls();
    $body = ledgerBody(end($calls));

    expect($calls)->toHaveCount(3)
        ->and($body['action'])->toBe('withdrawn')
        ->and($body['evidence']['method'])->toBe('unsubscribe_link')
        ->and($body['subject_ref'])->toBe(hash_hmac('sha256', $this->workspace->uuid.'|anna@example.test', LEDGER_KEY))
        // A suppression a hivatalos tiltás; a consent-sor állapota változatlan.
        ->and(Suppression::query()->where('email', 'anna@example.test')->exists())->toBeTrue()
        ->and(Consent::query()->firstOrFail()->status)->toBe('confirmed');
});

it('az egykattintásos leiratkozás újrapróbálása (Gmail) azonos Idempotency-Key-jel megy', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@example.test', 'marketing', 'form'));

    Domain::query()->firstOrCreate(['name' => 'pelda.hu'], ['status' => 'verified', 'verified_at' => now()]);
    $message = Message::create(['channel' => 'marketing', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['anna@example.test'], 'subject' => 'H', 'headers' => []]);
    $token = app(UnsubscribeTokens::class)->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'anna@example.test');

    $this->post('/u/'.$token)->assertOk();
    $this->post('/u/'.$token)->assertOk();

    $withdrawn = array_values(array_filter(ledgerCalls(), fn (Request $r) => ledgerBody($r)['action'] === 'withdrawn'));

    expect($withdrawn)->toHaveCount(2)
        ->and($withdrawn[0]->header('Idempotency-Key')[0])->toBe($withdrawn[1]->header('Idempotency-Key')[0]);
});

it('az egykattintásos leiratkozás consent nélküli címen nem küld semmit', function () {
    Domain::query()->firstOrCreate(['name' => 'pelda.hu'], ['status' => 'verified', 'verified_at' => now()]);
    $message = Message::create(['channel' => 'marketing', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['nincs@example.test'], 'subject' => 'H', 'headers' => []]);
    $token = app(UnsubscribeTokens::class)->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'nincs@example.test');

    $this->post('/u/'.$token)->assertOk();

    expect(ledgerCalls())->toBe([]);
});

it('az IMPORT nem kerül a főkönyvbe: a ventMail oldalán nem megerősített hozzájárulás', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->imported('import@pelda.hu', 'marketing', 'CSV: 2026-09 hírlevél-lista');
    $recorder->importedBatch(
        [['email' => 'b@pelda.hu', 'hash' => str_repeat('b', 64), 'status' => 'pending']],
        'marketing', 'consent', 'CSV', ['import' => 'x'],
    );

    expect(ledgerCalls())->toBe([]);
});

it('a már megerősített consent újrakérése, a visszavont megerősítése és a token-újrajátszás nem hoz új bizonyítékot', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu', 'marketing', 'form');
    $recorder->confirm($consent);
    expect(ledgerCalls())->toHaveCount(2);

    $recorder->request('anna@pelda.hu', 'marketing', 'form');   // már megerősített
    $recorder->confirm($consent->fresh());                       // már megerősített + egyszer használható
    expect(ledgerCalls())->toHaveCount(2);

    $recorder->revoke($consent->fresh(), 'preference_center');
    expect(ledgerCalls())->toHaveCount(3);

    $recorder->confirm($consent->fresh());                       // visszavont: nem támasztható fel
    expect(ledgerCalls())->toHaveCount(3);
});

it('a főkönyv HIBÁJA nem blokkolja a helyi folyamatot, és a küldési kapu a helyi consentből dönt', function (int $status) {
    fakeLedger($status);

    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu', 'marketing', 'form');
    $confirmed = $recorder->confirm($consent);

    expect($confirmed->status)->toBe('confirmed')
        ->and($confirmed->ledger_receipt_id)->toBeNull()
        ->and(app(ConsentChecker::class)->hasConfirmedConsent('anna@pelda.hu', 'marketing'))->toBeTrue();
})->with([500, 503, 429, 404, 422]);

it('hálózati hiba (timeout) sem blokkol', function () {
    fakeLedger(callback: fn () => throw new ConnectionException('timeout'));

    $recorder = app(ConsentRecorder::class);
    $confirmed = $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));

    expect($confirmed->status)->toBe('confirmed')->and($confirmed->ledger_receipt_id)->toBeNull();
});

it('a job a `default` sorra megy, commit után, újrapróbálással és backoff-fal', function () {
    Queue::fake();

    app(ConsentRecorder::class)->request('anna@pelda.hu', 'marketing', 'form');

    Queue::assertPushedOn('default', SendConsentProof::class, function (SendConsentProof $job) {
        return $job->afterCommit === true && $job->tries >= 5 && count($job->backoff()) >= 4
            // A job NEM hordoz nyers címet: csak a HMAC-elt alany-hivatkozást.
            && ! str_contains(serialize($job), 'anna@pelda.hu');
    });
});

it('a 409 (azonos kulcs, eltérő törzs) lezárt: a bizonyíték már a főkönyvben van — nincs újrapróbálás', function () {
    fakeLedger(callback: fn () => Http::response(['error' => 'idempotency_conflict'], 409));

    $recorder = app(ConsentRecorder::class);
    $confirmed = $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));

    expect($confirmed->status)->toBe('confirmed');
    // Sync queue-nál egy újrapróbálható hiba kivételként látszana; itt nincs.
    expect(ledgerCalls())->toHaveCount(2);
});

it('egy RÉGEBBI bizonyíték késői nyugtája nem írja felül az újabbét', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));
    $newer = $consent->fresh()->ledger_receipt_id;

    $older = new SendConsentProof(
        workspaceId: (int) $this->workspace->getKey(),
        consentId: (int) $consent->getKey(),
        payload: ledgerBody(ledgerCalls()[0]),
        idempotencyKey: 'vm-consent-older',
        occurredAt: now()->subHour()->toIso8601String(),
    );
    $older->handle(app(ConsentProofLedger::class));

    expect(Consent::query()->firstOrFail()->ledger_receipt_id)->toBe($newer);
});

it('a job idegen workspace consent-jére nem ír nyugtát (tenant-izoláció)', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->request('anna@pelda.hu', 'marketing', 'form');

    $before = Consent::query()->firstOrFail()->ledger_receipt_id;
    $other = Workspace::factory()->create();

    // ÚJABB időpont és más kulcs: ha a job írna, a nyugta megváltozna.
    (new SendConsentProof(
        workspaceId: (int) $other->getKey(),
        consentId: (int) $consent->getKey(),
        payload: ledgerBody(ledgerCalls()[0]),
        idempotencyKey: 'vm-consent-x',
        occurredAt: now()->addMinute()->toIso8601String(),
    ))->handle(app(ConsentProofLedger::class));

    app(WorkspaceContext::class)->set($this->workspace);
    expect($before)->not->toBeNull()
        ->and(Consent::query()->firstOrFail()->ledger_receipt_id)->toBe($before);
});

it('hiányzó konfigurációnál nem küld, és a folyamat megy tovább', function (string $missing) {
    config(["consent.ledger.{$missing}" => '']);
    Http::swap(new Factory);
    Http::fake();

    $recorder = app(ConsentRecorder::class);
    $confirmed = $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));

    expect($confirmed->status)->toBe('confirmed');
    Http::assertNothingSent();
})->with(['url', 'token', 'site_id', 'subject_key']);

it('az alapértelmezett meghajtó a FAKE: nincs HTTP, nincs nyugta, de a szándék rögzül', function () {
    config(['consent.ledger.driver' => 'fake']);
    $fake = new FakeConsentProofLedger;
    $this->app->instance(ConsentProofLedger::class, $fake);
    Http::swap(new Factory);
    Http::fake();

    $recorder = app(ConsentRecorder::class);
    $confirmed = $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));

    expect($fake->recorded)->toHaveCount(2)
        ->and($fake->recorded[1]['action'])->toBe('opt_in_confirmed')
        ->and($confirmed->ledger_receipt_id)->toBeNull();
    Http::assertNothingSent();

    expect(configFileWith('consent', [])['ledger']['driver'])->toBe('fake');
});

it('a meghajtó-kötés: fake alapból, ventcookie kapcsolóra a HTTP; ismeretlen érték hiba', function () {
    config(['consent.ledger.driver' => 'fake']);
    expect(app(ConsentProofLedger::class))->toBeInstanceOf(FakeConsentProofLedger::class);

    config(['consent.ledger.driver' => 'ventcookie']);
    expect(app(ConsentProofLedger::class))->toBeInstanceOf(HttpConsentProofLedger::class);

    config(['consent.ledger.driver' => 'ventcokie']);
    expect(fn () => ConsentServiceProvider::ledgerDriver())->toThrow(InvalidArgumentException::class, 'CONSENT_LEDGER_DRIVER');
});

it('élesben a ventcookie meghajtó hiányos konfigurációval a bootkor elbukik', function (string $missing) {
    app()->detectEnvironment(fn () => 'production');
    config(["consent.ledger.{$missing}" => '']);

    expect(fn () => ConsentServiceProvider::ledgerDriver())->toThrow(RuntimeException::class, 'CONSENT_LEDGER_DRIVER=ventcookie');
})->with(['url', 'token', 'site_id', 'subject_key']);

it('az alapértelmezett konfiguráció: fake meghajtó, minden ledger-kulcs üres', function () {
    $ledger = configFileWith('consent', [])['ledger'];

    expect($ledger['driver'])->toBe('fake')
        ->and($ledger['token'])->toBeEmpty()->and($ledger['subject_key'])->toBeEmpty()
        ->and($ledger['url'])->toBeEmpty()->and($ledger['site_id'])->toBeEmpty();
});

it('az alany-hivatkozás a WORKSPACE-hez kötött, kisbetűsített, vágott cím HMAC-ja (S7)', function () {
    $reporter = app(ConsentLedgerReporter::class);

    expect($reporter->subjectRef("  MiXed@Pelda.HU\t", 'ws-uuid-1'))->toBe(hash_hmac('sha256', 'ws-uuid-1|mixed@pelda.hu', LEDGER_KEY))
        ->and($reporter->subjectRef('a@b.hu', 'ws-A'))->not->toBe($reporter->subjectRef('a@b.hu', 'ws-B'));
});

it('a receipt_id módosítása auditált (domain rekord írása)', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));

    $audited = AuditLog::query()->get()
        ->contains(fn ($log) => str_contains(json_encode($log->toArray()), 'ledger_receipt_id'));

    expect($audited)->toBeTrue();
});

it('a bizonyíték scope_ref-je a consent workspace-éből jön, nem a pillanatnyi kontextusból; kontextus nélkül is commitol (S2)', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));

    app(WorkspaceContext::class)->set(Workspace::factory()->create()); // MÁS kontextus
    $recorder->revoke($consent->fresh(), 'preference_center');

    $withdrawn = ledgerBody(array_last_call());

    expect($withdrawn['scope_ref'])->toBe('ws:'.$this->workspace->uuid.'/channel:marketing');

    app(WorkspaceContext::class)->set($this->workspace);
    expect(Consent::query()->firstOrFail()->status)->toBe('revoked');
});

it('a reporter semmilyen hibája nem gördíti vissza a hívó tranzakcióját (S2)', function () {
    // Nem létező workspace: a scope_ref nem képezhető — a reporter nem dob, és nem küld.
    $consent = new Consent(['email' => 'anna@pelda.hu', 'channel' => 'marketing']);
    $consent->workspace_id = 987654321;
    $consent->uuid = (string) Str::uuid();

    expect(fn () => app(ConsentLedgerReporter::class)->confirmed($consent, 'ev-1'))->not->toThrow(Throwable::class);
    expect(ledgerCalls())->toBe([]);
});

it('üres (GDPR-törölt) címnél nem jelent a főkönyvbe (S7/N4)', function () {
    $reporter = app(ConsentLedgerReporter::class);
    $consent = app(ConsentRecorder::class)->request('anna@pelda.hu', 'marketing', 'form');
    $before = count(ledgerCalls());

    $consent->forceFill(['email' => ''])->saveQuietly();
    $reporter->withdrawn($consent->fresh(), 'preference_center', 'x');
    $reporter->confirmed($consent->fresh(), 'y');

    expect(count(ledgerCalls()))->toBe($before);
});

it('a consent_text_version csak ^[A-Za-z0-9._:-]{1,40}$ alakban megy ki, különben kimarad (S6)', function (string $version, bool $sent) {
    app(ConsentRecorder::class)->request('v'.md5($version).'@pelda.hu', 'marketing', 'form', null, null, $version);

    $evidence = ledgerBody(array_last_call())['evidence'];

    expect(array_key_exists('consent_text_version', $evidence))->toBe($sent);
})->with([['2026-10', true], ['v1.2:hu_HU', true], ['Szabad szöveg szóközzel', false], ['kiss@pelda.hu', false], ["sor\ntörés", false], [str_repeat('a', 41), false], [str_repeat('a', 40), true]]);

it('az egykattintásos leiratkozás kulcsa a consent állapot-verzióját is tartalmazza: withdraw→re-optin→withdraw új kulcs (S3)', function () {
    $recorder = app(ConsentRecorder::class);
    $consent = $recorder->confirm($recorder->request('anna@example.test', 'marketing', 'form'));

    Domain::query()->firstOrCreate(['name' => 'pelda.hu'], ['status' => 'verified', 'verified_at' => now()]);
    $message = Message::create(['channel' => 'marketing', 'from_email' => 'no-reply@pelda.hu', 'recipients' => ['anna@example.test'], 'subject' => 'H', 'headers' => []]);
    $token = app(UnsubscribeTokens::class)->issue((int) $this->workspace->getKey(), (string) $message->uuid, 'anna@example.test');

    $this->post('/u/'.$token)->assertOk();
    $this->post('/u/'.$token)->assertOk();   // újrapróbálás: azonos kulcs

    // Újra-feliratkozás: új megerősítés-esemény + új confirmed_at.
    Suppression::query()->delete();
    app(WorkspaceContext::class)->set($this->workspace);
    ConsentEvent::query()->create(['consent_id' => $consent->getKey(), 'email' => 'anna@example.test', 'type' => 'confirmed', 'source' => 'form']);
    Consent::query()->whereKey($consent->getKey())->firstOrFail()->forceFill(['confirmed_at' => now()->addMinute()])->save();

    $this->post('/u/'.$token)->assertOk();

    $keys = collect(ledgerCalls())->filter(fn (Request $r) => ledgerBody($r)['action'] === 'withdrawn')
        ->map(fn (Request $r) => $r->header('Idempotency-Key')[0])->values();

    expect($keys)->toHaveCount(3)->and($keys[0])->toBe($keys[1])->and($keys[2])->not->toBe($keys[0]);
});

it('a 409 külön, PII-mentes warning eseményt naplóz (S3)', function () {
    Log::spy();
    fakeLedger(callback: fn () => Http::response(['error' => 'idempotency_conflict'], 409));

    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu', 'marketing', 'form'));

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'consent.ledger.idempotency_conflict' && ! str_contains(json_encode($context), 'pelda'))->atLeast()->once();
});

it('élesben a subject_key legalább 32 bájt (S4)', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['consent.ledger.subject_key' => str_repeat('k', 31)]);

    expect(fn () => ConsentServiceProvider::ledgerDriver())->toThrow(RuntimeException::class, '32');

    config(['consent.ledger.subject_key' => str_repeat('k', 32)]);
    expect(ConsentServiceProvider::ledgerDriver())->toBe('ventcookie');
});
