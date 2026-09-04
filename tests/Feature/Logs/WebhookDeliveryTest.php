<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Testing\FakeHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | A3 · 28. kritérium — webhook: HMAC-SHA256 + timestamp, retry, dead-letter, replay.
 */

beforeEach(function () {
    // A sort MI hajtjuk a tesztben: így látszik, mi történik EGY kísérletnél, és a
    // dead-letter felé vezető út is lépésről lépésre mérhető.
    Queue::fake();

    // A DNS determinisztikus: a „rendes" végpont publikus címre oldódik fel.
    app()->instance(HostResolver::class, new FakeHostResolver);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    ['endpoint' => $this->endpoint, 'secret' => $this->secret] = WebhookEndpoint::issue(
        'Integráció',
        'https://ugyfel.test/webhook',
        []
    );
});

function raiseEvent(string $type = 'delivered', array $attributes = []): MessageEvent
{
    return MessageEvent::create(array_merge([
        'message_uuid' => (string) Str::uuid(),
        'type' => $type,
        'recipient' => 'cimzett@example.test',
        'channel' => 'transactional',
        'tags' => ['szamla'],
        'variables' => ['rendeles' => 'A-42'],
    ], $attributes));
}

it('minden esemény kézbesítési sort kap a feliratkozott végponton', function () {
    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    expect($delivery->event_type)->toBe('delivered');
    expect($delivery->status)->toBe('pending');
    expect($delivery->payload['variables']['rendeles'])->toBe('A-42');
});

it('a végpont szűrhet eseménytípusra', function () {
    $this->endpoint->forceFill(['events' => ['bounced']])->save();

    raiseEvent('delivered');

    expect(WebhookDelivery::count())->toBe(0);

    raiseEvent('bounced');

    expect(WebhookDelivery::count())->toBe(1);
});

it('a kiküldött kérés ALÁÍRT, és az aláírás az IDŐBÉLYEGET is fedi', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('', 200)]);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set($this->workspace);

    Http::assertSent(function ($request) {
        $timestamp = (int) $request->header('X-VentMail-Timestamp')[0];
        $signature = $request->header('X-VentMail-Signature')[0];

        // A FOGADÓ oldal ellenőrzése: a payload és az időbélyeg együtt van aláírva.
        return app(WebhookSigner::class)->verify(test()->secret, $request->body(), $timestamp, $signature);
    });

    expect(WebhookDelivery::firstOrFail()->status)->toBe('delivered');
});

it('a HAMIS aláírás nem verifikálódik', function () {
    $signer = app(WebhookSigner::class);
    $now = now()->getTimestamp();

    expect($signer->verify($this->secret, '{"a":1}', $now, str_repeat('0', 64)))->toBeFalse();
    // Más titok → más aláírás.
    expect($signer->verify('masik-titok', '{"a":1}', $now, $signer->sign($this->secret, '{"a":1}', $now)))->toBeFalse();
});

it('a RÉGI aláírás elutasítva — replay-védelem', function () {
    // Aki egyszer elkapta a kérést (proxy-log, félrekonfigurált végpont), az különben
    // évekig újraküldhetné ugyanazt a „hiteles" eseményt.
    $signer = app(WebhookSigner::class);
    $regi = now()->subHour()->getTimestamp();

    $signature = $signer->sign($this->secret, '{"a":1}', $regi);

    expect($signer->verify($this->secret, '{"a":1}', $regi, $signature))->toBeFalse();
});

it('a hibás válasz ÚJRAPRÓBÁLÁST ütemez, nem dob el', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('szerver-hiba', 500)]);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set($this->workspace);

    $fresh = WebhookDelivery::firstOrFail();

    expect($fresh->status)->toBe('failed');
    expect($fresh->attempt)->toBe(1);
    expect($fresh->next_attempt_at)->not->toBeNull();
    expect($fresh->response_code)->toBe(500);
});

it('N hiba után DEAD-LETTER lesz — nem tűnik el, hanem láthatóvá válik', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('', 500)]);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    foreach (range(1, 6) as $attempt) {
        (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
            ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

        app(WorkspaceContext::class)->set($this->workspace);
    }

    $fresh = WebhookDelivery::firstOrFail();

    expect($fresh->status)->toBe('dead_letter');
    expect($fresh->dead_lettered_at)->not->toBeNull();
    expect($fresh->next_attempt_at)->toBeNull();
});

it('a DLQ-ból az újrajátszás kivezeti a sort', function () {
    ['plain' => $plain] = ApiKey::issue('CI', ['logs:read', 'webhooks:write']);

    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    // A sor a dead-letterben áll (hatszor hibázott a végpont)…
    $delivery->forceFill(['status' => 'dead_letter', 'attempt' => 6, 'dead_lettered_at' => now()])->save();

    // …az ügyfél megjavítja a végpontot.
    Http::fake(['https://ugyfel.test/*' => Http::response('', 200)]);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'])
        ->postJson("/v1/webhooks/deliveries/{$delivery->uuid}/replay")
        ->assertStatus(202);

    app(WorkspaceContext::class)->set($this->workspace);

    // …a sorba tett job pedig kézbesít.
    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set($this->workspace);

    expect(WebhookDelivery::firstOrFail()->status)->toBe('delivered');
});

it('az esedékes újrapróbálásokat a parancs sorba teszi', function () {
    Http::fake(['https://ugyfel.test/*' => Http::response('', 500)]);

    raiseEvent();

    WebhookDelivery::query()->update([
        'status' => 'failed',
        'next_attempt_at' => now()->subMinute(),
    ]);

    Queue::fake();

    $this->artisan('webhooks:retry')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class);
});

it('a payload NEM tartalmaz levéltörzset', function () {
    // A payload HARMADIK FÉLHEZ megy ki: a kirenderelt törzs magic-linket és tokent hordoz.
    raiseEvent('delivered', ['payload' => ['body_sha256' => str_repeat('a', 64)]]);

    $payload = json_encode(WebhookDelivery::firstOrFail()->payload);

    expect(str_contains((string) $payload, 'body_sha256'))->toBeFalse();
    expect(WebhookDelivery::firstOrFail()->payload['message']['recipient'])->toBe('cimzett@example.test');
});

it('a titok TITKOSÍTVA van, és nem jelenik meg a modell tömbjében', function () {
    // Adatbázis-szivárgás önmagában nem elég ahhoz, hogy valaki érvényes aláírást gyártson
    // a nevünkben: a titkosítás az APP_KEY-hez köti.
    $raw = DB::table('webhook_endpoints')->value('secret');

    expect($raw)->not->toBe($this->secret);
    expect($this->endpoint->signingSecret())->toBe($this->secret);
    expect($this->endpoint->toArray())->not->toHaveKey('secret');
});

/*
 | SSRF — a cél-URL-t az ÜGYFÉL adja meg, a kérést MI indítjuk a saját hálózatunkból.
 */

dataset('ssrf-cimek', [
    'loopback' => ['127.0.0.1'],
    'felhő-metadata' => ['169.254.169.254'],
    'privát háló' => ['10.1.2.3'],
    'privát háló 192' => ['192.168.1.10'],
    'CGNAT' => ['100.64.0.1'],
    'IPv6 loopback' => ['::1'],
    'IPv6 unique local' => ['fd00::1'],
]);

it('a nem publikus címre mutató webhook-URL ELUTASÍTVA', function (string $ip) {
    // Védelem nélkül ez belső szkenner: a felhő-metadata a példány hitelesítőjét adja, a
    // 127.0.0.1:5432 a saját adatbázisunk — és a válasz egy részlete még vissza is jut az
    // ügyfélhez a kézbesítési naplóban.
    $resolver = new FakeHostResolver;
    $resolver->map('belso.test', [$ip]);

    app()->instance(HostResolver::class, $resolver);

    expect(fn () => WebhookEndpoint::issue('Belső', 'https://belso.test/hook', []))
        ->toThrow(InvalidArgumentException::class);
})->with('ssrf-cimek');

it('a nem-https URL elutasítva', function () {
    // A `file://`, `gopher://` sémák eleve kizárva — és a titkos aláírás sem utazhat
    // titkosítatlan csatornán.
    foreach (['http://ugyfel.test/h', 'file:///etc/passwd', 'gopher://ugyfel.test/'] as $url) {
        expect(fn () => WebhookEndpoint::issue('Rossz', $url, []))->toThrow(InvalidArgumentException::class);
    }
});

it('a TÖBBCÍMŰ hosztnév is elbukik, ha bármelyik cím belső', function () {
    // A DNS több A-rekordot adhat: ha csak az elsőt néznénk, a második lehetne 127.0.0.1.
    $resolver = new FakeHostResolver;
    $resolver->map('vegyes.test', ['203.0.113.10', '127.0.0.1']);

    app()->instance(HostResolver::class, $resolver);

    expect(fn () => WebhookEndpoint::issue('Vegyes', 'https://vegyes.test/hook', []))
        ->toThrow(InvalidArgumentException::class);
});

it('a KÜLDÉS ELŐTT is ellenőrzünk — DNS-rebinding ellen', function () {
    // A rebinding épp arra épül, hogy a mentés és a küldés között változik a feloldás.
    raiseEvent();

    $delivery = WebhookDelivery::firstOrFail();

    $resolver = new FakeHostResolver;
    $resolver->map('ugyfel.test', ['169.254.169.254']);
    app()->instance(HostResolver::class, $resolver);

    Http::fake();

    (new DeliverWebhook((int) $this->workspace->getKey(), (string) $delivery->uuid))
        ->handle(app(WorkspaceContext::class), app(WebhookSigner::class));

    app(WorkspaceContext::class)->set($this->workspace);

    Http::assertNothingSent();
    expect(WebhookDelivery::firstOrFail()->status)->toBe('dead_letter');
});

it('a kérés NEM követ átirányítást', function () {
    // Egy 302 a belső hálózatra megkerülné a fenti ellenőrzést.
    $source = file_get_contents(base_path('app/Modules/Webhooks/Jobs/DeliverWebhook.php'));

    expect(str_contains((string) $source, "'allow_redirects' => false"))->toBeTrue();
});

/*
 | KAT-vektorok az aláírásra.
 |
 | Rögzített titok + payload + időbélyeg → rögzített aláírás. Ha az algoritmus vagy az
 | aláírt adat összeállítása bármikor elmozdul, MINDEN ügyfél integrációja csendben
 | elromlana — a fogadó oldalán „érvénytelen aláírás" lenne belőle.
 */

it('az aláírás KAT-vektora', function () {
    $signer = app(WebhookSigner::class);

    // A vektor forrása: HMAC-SHA256(`timestamp.payload`) a megadott titokkal.
    expect($signer->sign('whsec_teszt', '{"event":"delivered"}', 1_756_800_000))
        ->toBe(hash_hmac('sha256', '1756800000.{"event":"delivered"}', 'whsec_teszt'));

    // …és konkrét bájtsorként is rögzítve, hogy az összeállítás se mozdulhasson el.
    expect($signer->sign('whsec_teszt', '{"event":"delivered"}', 1_756_800_000))
        ->toBe('363c17654baea444d069d95204c10390972dda1d4118ed255c6d23537ec1fe24');
});

it('a payload EGYETLEN bájtjának változása más aláírást ad', function () {
    $signer = app(WebhookSigner::class);
    $now = now()->getTimestamp();

    expect($signer->sign('titok', '{"a":1}', $now))->not->toBe($signer->sign('titok', '{"a":2}', $now));
    expect($signer->sign('titok', '{"a":1}', $now))->not->toBe($signer->sign('titok', '{"a":1}', $now + 1));
});

it('a JÖVŐBELI időbélyeget szűkebb ablakkal fogadja el', function () {
    // Az ablak ASZIMMETRIKUS. A múltbeli tűrés valódi igényt fed (hálózati késleltetés,
    // újrapróbálás); a jövőbeli semmit — legfeljebb óracsúszást. Ez a `verify()` a
    // dokumentált fogadó-oldali minta: amit itt megengedünk, azt fogják lemásolni.
    $signer = app(WebhookSigner::class);
    $payload = '{"event":"delivered"}';

    $alair = fn (int $ts) => $signer->sign('titok', $payload, $ts);

    $most = now()->getTimestamp();

    expect($signer->verify('titok', $payload, $most + 30, $alair($most + 30)))->toBeTrue();
    expect($signer->verify('titok', $payload, $most + 295, $alair($most + 295)))->toBeFalse();

    // Visszafelé viszont marad az öt perc.
    expect($signer->verify('titok', $payload, $most - 295, $alair($most - 295)))->toBeTrue();
    expect($signer->verify('titok', $payload, $most - 305, $alair($most - 305)))->toBeFalse();
});
