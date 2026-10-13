<?php

declare(strict_types=1);

use App\Modules\Consent\Models\Consent;
use App\Modules\Sending\Drivers\PostalDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Sending\Testing\FakeAddressRiskScorer;
use App\Modules\Sending\Testing\FakeConsentChecker;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Address;

uses(RefreshDatabase::class);

/*
 | A PostalDriver azért létezik M0-ban, hogy a kontraktus MÉRHETŐ legyen: ha a
 | `fake → postal` váltás bármi mást is igényelne, mint config-változást, az MOST derüljön
 | ki. Mérés nélkül ez az állítás semmit nem ér — ezért van rá teszt.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
});

function outbound(array $overrides = []): Message
{
    $message = Message::create(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Teszt',
        'recipients' => ['cimzett@example.test'],
        'options' => ['body_html' => '<p>Szia</p>'],
    ], $overrides));

    // A driver a KIRENDERELT törzset kapja — ezt a valóságban a MessageDispatcher tölti fel.
    return $message->withRenderedBodies(
        collect($message->recipients)
            ->mapWithKeys(fn ($recipient) => [(string) $recipient => ['html' => '<p>Szia</p>', 'text' => 'Szia']])
            ->all()
    );
}

it('a PostalDriver konfiguráció nélkül hangosan megáll', function () {
    config(['sending.postal.endpoint' => '', 'sending.postal.api_key' => '']);

    expect(fn () => (new PostalDriver)->send(outbound()))->toThrow(RuntimeException::class);
});

it('a PostalDriver titkosítatlan végpontot elutasít', function () {
    // Az API-kulcs fejlécben megy: http:// esetén titkosítatlanul hagyná el a hálózatot.
    config(['sending.postal.endpoint' => 'http://postal.belso', 'sending.postal.api_key' => 'kulcs']);

    expect(fn () => (new PostalDriver)->send(outbound()))->toThrow(RuntimeException::class);
});

it('a PostalDriver sikeres válaszra accepted-et ad, nem delivered-et', function () {
    // Egy MTA 2xx-e csak annyit jelent, hogy sorba állt — a delivered a webhookból jön (A3).
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'postal-1']])]);

    $result = (new PostalDriver)->send(outbound());

    expect($result->accepted)->toBeTrue();
    expect($result->delivered)->toBeFalse();
    expect($result->providerMessageId)->toBe('postal-1');
});

it('a PostalDriver hibás válaszra nem dob, hanem elutasítást jelez', function () {
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response([], 503)]);

    $result = (new PostalDriver)->send(outbound());

    expect($result->accepted)->toBeFalse();
});

it('a PostalDriver idempotencia-kulcsot küld', function () {
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'x']])]);

    $message = outbound();
    (new PostalDriver)->send($message);

    // Címzettenként EGYEDI: közös kulccsal a második címzett a firstre futna rá.
    Http::assertSent(fn ($request) => ($request['idempotency_key'] ?? null)
        === $message->uuid.':'.hash('sha256', 'cimzett@example.test'));
});

it('a PostalDriver TÖRZSET küld, címzettenként személyre szabva', function () {
    // A driver korábban csak fejléceket adott át: a `SENDING_DRIVER=postal` napján
    // tartalom nélküli levél ment volna ki, a batch személyre szabása pedig nyomtalanul
    // elveszett volna.
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'x']])]);

    $message = outbound(['recipients' => ['anna@example.test', 'bence@example.test']])
        ->withRenderedBodies([
            'anna@example.test' => ['html' => '<p>Kedves Anna</p>', 'text' => 'Kedves Anna'],
            'bence@example.test' => ['html' => '<p>Kedves Bence</p>', 'text' => 'Kedves Bence'],
        ]);

    (new PostalDriver)->send($message);

    Http::assertSent(fn ($request) => ($request['html_body'] ?? null) === '<p>Kedves Anna</p>'
        && $request['to'] === ['anna@example.test']);
    Http::assertSent(fn ($request) => ($request['html_body'] ?? null) === '<p>Kedves Bence</p>'
        && $request['to'] === ['bence@example.test']);
});

it('a PostalDriver NEM küld ki törzs nélküli levelet', function () {
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'x']])]);

    // Kirenderelt törzs nélkül: fail-closed. Üres levelet kiküldeni rosszabb, mint nem küldeni.
    $message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Teszt',
        'recipients' => ['cimzett@example.test'],
        'options' => ['body_html' => '<p>Szia</p>'],
    ]);

    expect(fn () => (new PostalDriver)->send($message))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('a nyers MIME-üzenetet a Postal RAW végpontja kapja', function () {
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'x']])]);

    $message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['cimzett@example.test'],
        'options' => ['raw_mime' => true],
    ]);

    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\n\r\nTorzs\r\n";

    RawMimeMessage::create(['message_uuid' => $message->uuid, 'raw' => $raw]);

    (new PostalDriver)->send($message);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/send/raw')
        && ($request['data'] ?? null) === base64_encode($raw));
});

it('a PostalDriver health-je nem szivárogtat belső részletet', function () {
    config(['sending.postal.endpoint' => 'https://postal.test/titkos-ut?token=SZIVARGO']);

    Http::fake(fn () => throw new RuntimeException('cURL error: https://postal.test/titkos-ut?token=SZIVARGO'));

    $health = (new PostalDriver)->health();

    expect($health->healthy)->toBeFalse();
    expect($health->detail)->not->toContain('SZIVARGO');
});

it('a FakeConsentChecker valódi consent-adatot olvas', function () {
    Consent::create([
        'email' => 'megerositett@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);

    $checker = new FakeConsentChecker;

    expect($checker->hasConfirmedConsent('MEGEROSITETT@example.test', 'marketing'))->toBeTrue();
    expect($checker->hasConfirmedConsent('ismeretlen@example.test', 'marketing'))->toBeFalse();
});

it('a FakeConsentChecker a megerősítetlen consentet sem fogadja el', function () {
    Consent::create(['email' => 'fuggoben@example.test', 'channel' => 'marketing', 'status' => 'pending']);

    expect((new FakeConsentChecker)->hasConfirmedConsent('fuggoben@example.test', 'marketing'))->toBeFalse();
});

it('a FakeAddressRiskScorer heurisztikája determinisztikus', function () {
    $scorer = new FakeAddressRiskScorer;

    expect($scorer->riskFor('nem-email'))->toBe(1.0);
    expect($scorer->riskFor('rendes@example.test'))->toBeLessThan(0.5);
    expect($scorer->riskFor('eldobhato@mailinator.com'))->toBeGreaterThan(0.5);
});

it('éles környezetben a fake függőségek nem köthetők be', function () {
    // A FakeVentPayClient configból ad kvótát: élesben a 4. kapu no-op lenne.
    expect(config('sending.allow_fake_dependencies'))->toBeTrue();

    $guard = file_get_contents(app_path('Modules/Sending/Providers/SendingServiceProvider.php'));

    expect($guard)->toContain('isProduction');
});

it('a PostalDriver a feladó NEVÉT RFC 5322 szerint idézi (vessző, idézőjel, nem ASCII)', function () {
    // N6 · a nyers `név <cím>` összefűzés egy `Kávé, Kft "Nádor"` névvel két címzettnek /
    // hibás fejlécnek látszott; a Symfony `Address` idézi és szükség esetén kódolja.
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'x']])]);

    (new PostalDriver)->send(outbound(['from_name' => 'Kávé, Kft "Nádor"']));

    Http::assertSent(fn ($request) => ($request['from'] ?? null) === (new Address('no-reply@pelda.hu', 'Kávé, Kft "Nádor"'))->toString()
        && str_starts_with((string) $request['from'], '"'));
});

it('NIT · CRLF a feladó nevében nem jut a Postal `from` mezőjébe (nincs fejléc-injekció)', function () {
    config(['sending.postal.endpoint' => 'https://postal.test', 'sending.postal.api_key' => 'kulcs']);

    Http::fake(['https://postal.test/*' => Http::response(['data' => ['message_id' => 'x']])]);

    (new PostalDriver)->send(outbound(['from_name' => "Kft\r\nBcc: tamado@evil.example"]));

    Http::assertSent(fn ($request) => ! preg_match('/[\r\n]/', (string) ($request['from'] ?? ''))
        && str_ends_with((string) $request['from'], '<no-reply@pelda.hu>'));
});
