<?php

declare(strict_types=1);

use App\Modules\Consent\Models\Consent;
use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | Támadási vektorok a publikus `/v1` felület ellen. Mindegyiket a review-k tárták fel,
 | többet futtatott PoC-cal.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    ['model' => $this->key, 'plain' => $this->plain] = ApiKey::issue('CI', ['messages:send', 'templates:write']);
});

function apiSend(array $payload = [], array $headers = [], ?string $key = null): TestResponse
{
    return test()->withHeaders(array_merge([
        'Authorization' => 'Bearer '.($key ?? test()->plain),
        'Idempotency-Key' => 'k-'.bin2hex(random_bytes(6)),
        'Accept' => 'application/json',
    ], $headers))->postJson('/v1/messages', array_merge([
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Teszt',
        'html' => '<p>Szia</p>',
    ], $payload));
}

it('a publikus API-n NEM lehet consent-köteles csatornát választani', function () {
    // A csatorna a hívó ÖNBEVALLÁSA volt: `o:channel=transactional` címkével marketing
    // levél ment ki, mert a ConsentGate azonnal átengedte, a SuppressionGate pedig a
    // marketing-leiratkozásokat nem is nézte.
    foreach (['marketing', 'journey', 'campaign'] as $channel) {
        apiSend(['o:channel' => $channel])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CHANNEL_NOT_ALLOWED');
    }
});

it('marketing-scope-pal viszont engedett a marketing csatorna', function () {
    ['plain' => $marketingKey] = ApiKey::issue('Marketing', ['messages:send', 'messages:send:marketing']);

    Consent::create([
        'email' => 'cimzett@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);

    apiSend(['o:channel' => 'marketing'], key: $marketingKey)->assertAccepted();
});

it('ismeretlen csatorna 400', function () {
    apiSend(['o:channel' => 'promo'])->assertStatus(400)->assertJsonPath('error.code', 'INVALID_CHANNEL');
});

it('a teszt-kulcs SOHA nem küld élesben', function () {
    // Mailgunnál a teszt-kulcs nem küld. Egy CI-ban hagyott kulcs különben valódi
    // címzettekre küldene.
    ['plain' => $testKey] = ApiKey::issue('Teszt', ['messages:send'], testmode: true);

    apiSend([], key: $testKey)->assertAccepted();

    expect(Message::first()->testmode)->toBeTrue();
    expect(MessageEvent::where('type', 'delivered')->count())->toBe(0);
});

it('a teszt-kulcs testmode-ját a hívó nem tudja lekapcsolni', function () {
    ['plain' => $testKey] = ApiKey::issue('Teszt', ['messages:send'], testmode: true);

    apiSend(['o:testmode' => 'no'], key: $testKey)->assertAccepted();

    expect(Message::first()->testmode)->toBeTrue();
});

it('a renderelt levéltörzs NEM kerül az esemény-naplóba', function () {
    // A törzs jelszó-visszaállító linket és magic-link tokent tartalmazhat: egy 90 napig
    // élő naplóban ez credential leak, nem PII-kérdés. A webhook is ezt küldené ki.
    apiSend(['html' => '<p>A belépő linked: https://pelda.hu/magic/SUPER-TITKOS-TOKEN</p>'])->assertAccepted();

    $events = MessageEvent::all();
    $serialized = (string) json_encode($events->pluck('payload'));

    expect($serialized)->not->toContain('SUPER-TITKOS-TOKEN');
    // A trace-hez elég a lenyomat és a sablon-hivatkozás.
    expect($events->first()->payload)->toHaveKey('body_sha256');
});

it('a levéltörzs nem tárolódik kétszer az üzenet-soron', function () {
    apiSend(['html' => '<p>tartalom</p>'])->assertAccepted();

    $options = Message::first()->options;

    expect(isset($options['html']) && isset($options['rendered_html']))->toBeFalse();
});

it('idegen tenant sablonjára nem lehet hivatkozni', function () {
    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    StoredTemplate::create(['name' => 'idegen']);

    app(WorkspaceContext::class)->set($this->workspace);

    apiSend(['template' => 'idegen'])->assertStatus(400)->assertJsonPath('error.code', 'UNKNOWN_TEMPLATE');
});

it('idegen tenant verifikált domainjéről nem lehet küldeni', function () {
    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    Domain::create(['name' => 'masik.hu', 'status' => 'verified', 'verified_at' => now()]);

    app(WorkspaceContext::class)->set($this->workspace);

    apiSend(['from' => 'no-reply@masik.hu'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DOMAIN_UNVERIFIED');
});

it('idegen tenant idempotencia-kulcsa nem ütközik és nem replayelhető', function () {
    apiSend([], ['Idempotency-Key' => 'kozos'])->assertAccepted();

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    Domain::create(['name' => 'masik.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $masikKulcs] = ApiKey::issue('Másik', ['messages:send']);

    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.$masikKulcs,
        'Idempotency-Key' => 'kozos',
        'Accept' => 'application/json',
    ])->postJson('/v1/messages', [
        'from' => 'no-reply@masik.hu',
        'to' => 'b@example.test',
        'html' => '<p>x</p>',
    ]);

    $response->assertAccepted();
    // Két külön üzenet, nem replay.
    expect(Message::withoutGlobalScopes()->count())->toBe(2);
});

it('ugyanaz a kulcs MÁS tartalommal 409', function () {
    // Egy `order-123` kulccsal küldött visszaigazoló után ugyanazzal a kulccsal küldött
    // szállítási értesítő különben CSENDBEN eldobódott volna.
    apiSend(['subject' => 'Első'], ['Idempotency-Key' => 'order-123'])->assertAccepted();

    apiSend(['subject' => 'Második'], ['Idempotency-Key' => 'order-123'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
});

it('azonos kulcs azonos tartalommal továbbra is replay', function () {
    $first = apiSend(['subject' => 'Ugyanaz'], ['Idempotency-Key' => 'order-9']);
    $second = apiSend(['subject' => 'Ugyanaz'], ['Idempotency-Key' => 'order-9']);

    expect($second->json('id'))->toBe($first->json('id'));
    expect(Message::count())->toBe(1);
});

it('a hitelesítési hiba nem árulja el, hogy a kulcs valódi-e', function () {
    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    ['plain' => $torolt] = ApiKey::issue('Törölt workspace', ['messages:send']);
    $masik->delete();

    app(WorkspaceContext::class)->set($this->workspace);

    $valid = apiSend([], key: $torolt);
    $invalid = apiSend([], key: 'vm_live_teljesen-hamis');

    $valid->assertUnauthorized();
    $invalid->assertUnauthorized();
    // UGYANAZ az üzenet: különben orákulum lenne arra, hogy a kulcs létezik.
    expect($valid->json('error.message'))->toBe($invalid->json('error.message'));
});

it('a csatolmány néma eldobás helyett hibát ad', function () {
    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Idempotency-Key' => 'att-1',
        'Accept' => 'application/json',
    ])->post('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'html' => '<p>x</p>',
        'attachment' => [UploadedFile::fake()->create('szamla.pdf', 10)],
    ]);

    $response->assertStatus(400)->assertJsonPath('error.code', 'UNSUPPORTED_ATTACHMENT');
});

it('javascript: linket tartalmazó behelyettesítés elutasítva', function () {
    // Webmailben ez a tenant DKIM-jével aláírt tárolt phishing/XSS lenne — a
    // htmlspecialchars a sémát nem szűri.
    apiSend(['html' => '<a href="%recipient.link%">Kattints</a>', 'to' => ['a@example.test'],
        'recipient-variables' => json_encode(['a@example.test' => ['link' => 'javascript:alert(1)']])])
        ->assertStatus(422);
});

it('marketing pool nem vihet tranzakciós forgalmat', function () {
    // A reputáció-szeparáció az ESP-modell alapja: ha összekeverednek, a hírlevél
    // bounce-ai a jelszó-visszaállító leveleket is magukkal viszik.
    IpPool::create([
        'name' => 'marketing-pool',
        'type' => 'shared',
        'channel' => 'marketing',
    ]);

    apiSend(['o:sending-ip-pool' => 'marketing-pool'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'IP_POOL_CHANNEL_MISMATCH');
});

it('dedikált pool entitlement nélkül nem használható', function () {
    config(['sending.fake_entitlement.features' => []]);

    IpPool::create([
        'name' => 'dedikalt',
        'type' => 'dedicated',
        'channel' => null,
    ]);

    apiSend(['o:sending-ip-pool' => 'dedikalt'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ENTITLEMENT_REQUIRED');
});

it('a saját pool előnyben a platform-szintűvel szemben', function () {
    IpPool::onPlatform(fn () => IpPool::create([
        'name' => 'kozos', 'type' => 'shared', 'channel' => null,
    ]));

    $sajat = IpPool::create(['name' => 'kozos', 'type' => 'shared', 'channel' => null]);

    apiSend(['o:sending-ip-pool' => 'kozos'])->assertAccepted();

    expect(Message::firstOrFail()->ip_pool_id)->toBe($sajat->id);
});

it('az o:skip-verification nem kapcsolja ki a kockázati kaput', function () {
    // A paraméter neve a 6. kapu kikapcsolását ígéri — elfogadjuk (Mailgun-kompatibilitás),
    // de ELDOBJUK, és a gate soha nem olvassa.
    apiSend(['o:skip-verification' => 'yes'])->assertAccepted();

    expect(Message::firstOrFail()->options)->not->toHaveKey('skip_verification');
});

it('a /v1 validációs hibája az egységes formátumban jön', function () {
    // Egy generált kliens az `error.code`-ra ágazik: a Laravel alap `{message, errors}`
    // alakja ott `undefined` lenne.
    test()->withHeaders([
        'Authorization' => 'Bearer '.$this->plain,
        'Accept' => 'application/json',
    ])->postJson('/v1/templates', ['name' => 'ÉRVÉNYTELEN NÉV!!!', 'template' => '<p>x</p>'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('a rate-limit a KULCSHOZ tartozik, nem az IP-hez', function () {
    // IP-alapon két tenant egy NAT mögött megeszi egymás keretét.
    $source = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($source)->toContain("'key:'.hash('sha256', \$bearer)");
});
