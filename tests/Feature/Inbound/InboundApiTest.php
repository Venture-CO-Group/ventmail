<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Inbound\Testing\FakeRawMailStore;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
 | A4 · 30., 33., 35., 37. kritérium — a bejövő API.
 */

beforeEach(function () {
    $this->store = new FakeRawMailStore;
    $this->app->instance(RawMailStore::class, $this->store);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    $this->domain = Domain::create([
        'name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now(),
        'inbound_mx_status' => 'verified',
    ]);

    ['plain' => $this->plain] = ApiKey::issue('CI', ['routes:write', 'logs:read']);
});

function api(string $method, string $uri, array $body = [], ?string $key = null): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.($key ?? test()->plain),
        'Accept' => 'application/json',
    ])->json($method, $uri, $body);
}

it('létrehoz és listáz egy route-szabályt', function () {
    $response = api('POST', '/v1/routes', [
        'description' => 'support',
        'priority' => 10,
        'match_type' => 'match_recipient',
        'expression' => '^support@pelda\.hu$',
        'actions' => [['type' => 'forward', 'target' => 'https://hook.example.test/mail'], ['type' => 'stop']],
    ]);

    $response->assertStatus(201);

    expect($response->json('priority'))->toBe(10);
    expect($response->json('actions'))->toHaveCount(2);
    expect(api('GET', '/v1/routes')->json('items'))->toHaveCount(1);
});

it('az ELGÉPELT kifejezést MENTÉSKOR utasítja el', function () {
    // Az ügyfél azonnal tudja meg, ne három nap múlva abból, hogy „nem jön a levél".
    $response = api('POST', '/v1/routes', [
        'match_type' => 'match_recipient',
        'expression' => '(((((',
        'actions' => [['type' => 'stop']],
    ]);

    $response->assertStatus(400);

    expect($response->json('error.code'))->toBe('INVALID_ROUTE');
    expect(InboundRoute::query()->count())->toBe(0);
});

it('a kifejezés nélküli match_recipient-et elutasítja', function () {
    expect(api('POST', '/v1/routes', [
        'match_type' => 'match_recipient',
        'actions' => [['type' => 'stop']],
    ])->json('error.code'))->toBe('INVALID_ROUTE');
});

it('a match_header fejlécnév nélkül nem menthető', function () {
    expect(api('POST', '/v1/routes', [
        'match_type' => 'match_header',
        'expression' => '.*',
        'actions' => [['type' => 'stop']],
    ])->json('error.code'))->toBe('INVALID_ROUTE');
});

it('a forward URL-je csak https lehet', function () {
    // A titkos aláírás nem utazhat titkosítatlan csatornán — és a hibát ITT jelezzük, ahol
    // az ügyfél beírja, nem a hatodik sikertelen kézbesítésnél.
    expect(api('POST', '/v1/routes', [
        'match_type' => 'catch_all',
        'actions' => [['type' => 'forward', 'target' => 'http://hook.example.test/mail']],
    ])->json('error.code'))->toBe('INVALID_ROUTE');
});

it('a TÚL HOSSZÚ kifejezést elutasítja', function () {
    expect(api('POST', '/v1/routes', [
        'match_type' => 'match_recipient',
        'expression' => str_repeat('a', 600),
        'actions' => [['type' => 'stop']],
    ])->json('error.code'))->toBe('VALIDATION_ERROR');
});

it('routes:write scope nélkül nem lehet szabályt írni', function () {
    ['plain' => $olvaso] = ApiKey::issue('Csak olvas', ['logs:read']);

    api('POST', '/v1/routes', [
        'match_type' => 'catch_all',
        'actions' => [['type' => 'stop']],
    ], $olvaso)->assertStatus(403);

    // …olvasni viszont lehet.
    api('GET', '/v1/routes', [], $olvaso)->assertOk();
});

it('IDEGEN workspace szabálya nem érhető el', function () {
    $route = InboundRoute::query()->create([
        'match_type' => 'catch_all',
        'actions' => [['type' => 'stop']],
    ]);

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    ['plain' => $idegen] = ApiKey::issue('Idegen', ['routes:write', 'logs:read']);

    api('PATCH', '/v1/routes/'.$route->uuid, ['priority' => 1], $idegen)->assertStatus(404);
    expect(api('GET', '/v1/routes', [], $idegen)->json('items'))->toBe([]);
});

/*
 | A TÁROLT levelek (33. kritérium).
 */

it('a tárolt levél lekérhető, a nyers MIME külön', function () {
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'sender' => 'valaki@kuldo.example',
        'recipient' => 'support@pelda.hu',
        'subject' => 'Kérdés',
        'body_plain' => 'Szia',
        'stripped_text' => 'Szia',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $message->forceFill(['storage_key' => 'inbound/x.eml'])->save();
    $this->store->put('inbound/x.eml', "Subject: Kérdés\r\n\r\nSzia");

    expect(api('GET', '/v1/inbound/messages')->json('items'))->toHaveCount(1);

    $show = api('GET', '/v1/inbound/messages/'.$message->uuid);

    expect($show->json('subject'))->toBe('Kérdés');
    expect($show->json('stripped-text'))->toBe('Szia');
    expect($show->json('authentication'))->toBeArray();

    $raw = $this->withHeaders(['Authorization' => 'Bearer '.$this->plain])
        ->get('/v1/inbound/messages/'.$message->uuid.'/raw');

    $raw->assertOk();
    expect($raw->getContent())->toContain('Szia');
});

it('a lejárt (törölt) nyers levél 404, nem 500', function () {
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $message->forceFill(['storage_key' => 'inbound/nincs.eml'])->save();

    expect(api('GET', '/v1/inbound/messages/'.$message->uuid.'/raw')->json('error.code'))->toBe('RAW_UNAVAILABLE');
});

it('a TISZTA csatolmányra ad lejáró linket', function () {
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $attachment = InboundAttachment::query()->create([
        'inbound_message_id' => $message->getKey(),
        'filename' => 'szamla.pdf',
        'content_type' => 'application/pdf',
        'size' => 3,
        'sha256' => hash('sha256', 'PDF'),
        'storage_key' => 'inbound/a.pdf',
        'scan_result' => 'clean',
    ]);

    $response = api('GET', '/v1/inbound/messages/'.$message->uuid.'/attachments/'.$attachment->uuid);

    $response->assertOk();

    expect($response->json('url'))->toContain('expires=');
    expect($response->json('expires_in'))->toBeGreaterThan(0);
});

it('a FERTŐZÖTT csatolmányra NEM ad linket', function () {
    // Nem a mi dolgunk kártevőt terjeszteni, még kérésre sem.
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $attachment = InboundAttachment::query()->create([
        'inbound_message_id' => $message->getKey(),
        'filename' => 'virus.exe',
        'content_type' => 'application/octet-stream',
        'size' => 10,
        'sha256' => hash('sha256', 'x'),
        'storage_key' => 'inbound/quarantine/a.bin',
        'scan_result' => 'infected',
    ]);

    $response = api('GET', '/v1/inbound/messages/'.$message->uuid.'/attachments/'.$attachment->uuid);

    $response->assertStatus(409);

    expect($response->json('error.code'))->toBe('ATTACHMENT_NOT_DOWNLOADABLE');
    expect($response->json('error.message'))->toContain('infected');
});

it('IDEGEN workspace levele nem kérhető le', function () {
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    ['plain' => $idegen] = ApiKey::issue('Idegen', ['logs:read']);

    api('GET', '/v1/inbound/messages/'.$message->uuid, [], $idegen)->assertStatus(404);
    expect(api('GET', '/v1/inbound/messages', [], $idegen)->json('items'))->toBe([]);
});

/*
 | RETENCIÓ (33. kritérium, golden rule 10).
 */

it('a lejárt bejövő levelet a FÁJLTÁRBÓL is törli', function () {
    // Ha csak az adatbázis-sort törölnénk, a nyers levél és a csatolmányok örökre a
    // fájltárban maradnának — láthatatlanul, és pont azok a bájtok, amikben a legtöbb
    // személyes adat van.
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now()->subDays(30),
    ]);

    $message->forceFill(['storage_key' => 'inbound/regi.eml', 'expires_at' => now()->subDay()])->save();
    $this->store->put('inbound/regi.eml', 'nyers levél');

    $attachment = InboundAttachment::query()->create([
        'inbound_message_id' => $message->getKey(),
        'filename' => 'a.pdf',
        'content_type' => 'application/pdf',
        'size' => 3,
        'sha256' => hash('sha256', 'PDF'),
        'storage_key' => 'inbound/regi-a.pdf',
        'scan_result' => 'clean',
    ]);

    $this->store->put('inbound/regi-a.pdf', 'PDF');

    $this->artisan('inbound:purge')->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(InboundMessage::query()->count())->toBe(0);
    expect(InboundAttachment::query()->count())->toBe(0);
    expect($this->store->get('inbound/regi.eml'))->toBeNull();
    expect($this->store->get('inbound/regi-a.pdf'))->toBeNull();
});

it('a FRISS bejövő levelet nem bántja', function () {
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $message->forceFill(['storage_key' => 'inbound/friss.eml'])->save();
    $this->store->put('inbound/friss.eml', 'friss');

    $this->artisan('inbound:purge')->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(InboundMessage::query()->count())->toBe(1);
    expect($this->store->get('inbound/friss.eml'))->not->toBeNull();
});

it('a LEJÁRAT NÉLKÜLI régi sort is takarítja', function () {
    // Az `expires_at`-ot a modell hookja tölti; a modellt megkerülő beszúrás `NULL`-t hagy.
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now()->subYear(),
    ]);

    $message->forceFill(['expires_at' => null])->save();

    $this->artisan('inbound:purge')->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(InboundMessage::query()->count())->toBe(0);
});

it('az érintetti törlés a BEJÖVŐ levelet is elviszi', function () {
    // A hozzánk írt levél TARTALMA maga a személyes adat. Az érintett lehet a feladó (ő írt
    // nekünk) és a címzett is (neki írtak a mi domainünkön) — mindkettőt nézzük.
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'sender' => 'erintett@example.test',
        'recipient' => 'support@pelda.hu',
        'subject' => 'Bizalmas kérdés',
        'body_plain' => 'A személyes ügyem',
        'stripped_text' => 'A személyes ügyem',
        'headers' => ['from' => 'erintett@example.test'],
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $message->forceFill(['storage_key' => 'inbound/e.eml'])->save();

    InboundAttachment::query()->create([
        'inbound_message_id' => $message->getKey(),
        'filename' => 'lelet.pdf',
        'content_type' => 'application/pdf',
        'size' => 3,
        'sha256' => hash('sha256', 'x'),
        'storage_key' => 'inbound/e.pdf',
        'scan_result' => 'clean',
    ]);

    $this->artisan('logs:redact-subject', ['email' => 'erintett@example.test'])->assertSuccessful();

    app(WorkspaceContext::class)->set($this->workspace);

    $message->refresh();

    expect($message->sender)->toBeNull();
    expect($message->subject)->toBeNull();
    expect($message->body_plain)->toBeNull();
    expect($message->headers)->toBe([]);
    expect($message->storage_key)->toBeNull();
    expect($message->redacted_at)->not->toBeNull();
    expect(InboundAttachment::query()->count())->toBe(0);

    // A TÉNY marad: mikor és milyen státusszal érkezett.
    expect($message->status)->toBe('routed');
    expect($message->received_at)->not->toBeNull();
});

/*
 | A KÉPERNYŐ (37. kritérium — a jogosultság is izoláció).
 */

it('a bejövő képernyő domain.manage jogot kér', function () {
    // A route-szabály KÜLDÉSI viselkedést változtat (továbbítás idegen címre), a levelek
    // listája pedig ügyfél-levelezést mutat. A workspace-tagság egyikhez sem elég.
    $this->withoutVite();

    $owner = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $owner->id, 'roles' => [Role::Owner->value]]);

    $this->actingAs($owner)->withSession(['workspace_uuid' => $this->workspace->uuid]);
    $this->get('/inbound')->assertOk();

    $author = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $author->id, 'roles' => [Role::Author->value]]);

    $this->actingAs($author)->withSession(['workspace_uuid' => $this->workspace->uuid]);
    $this->get('/inbound')->assertForbidden();
});

it('a bejövő képernyő BEJELENTKEZÉS nélkül nem érhető el', function () {
    // A modul `/v1` API-ja kulccsal hitelesít, ezért a provider `routesPrecedeAuthentication`-je
    // be van kapcsolva — az viszont a WEB-stackről is leveszi az alapértelmezett őrt. Ez a
    // teszt őrzi, hogy az explicit `auth` a helyén maradjon.
    $this->withoutVite();

    $this->get('/inbound')->assertRedirect();
});

it('a NYERS levél sem kerüli meg a karantént', function () {
    // A nyers MIME a csatolmányokat is TARTALMAZZA, base64-ben: a `downloadable()` zárat ez
    // a végpont megkerülné, és a 409-cel tiltott melléklet a levél törzsében kijönne.
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $message->forceFill(['storage_key' => 'inbound/f.eml'])->save();
    $this->store->put('inbound/f.eml', 'nyers levél a kártevővel');

    InboundAttachment::query()->create([
        'inbound_message_id' => $message->getKey(),
        'filename' => 'virus.exe',
        'content_type' => 'application/octet-stream',
        'size' => 10,
        'sha256' => hash('sha256', 'x'),
        'storage_key' => 'inbound/quarantine/f.bin',
        'scan_result' => 'infected',
    ]);

    $response = api('GET', '/v1/inbound/messages/'.$message->uuid.'/raw');

    $response->assertStatus(409);

    expect($response->json('error.code'))->toBe('RAW_CONTAINS_INFECTED_ATTACHMENT');
});

it('a TISZTA levél nyers MIME-ja letölthető, de nem renderelhető', function () {
    $message = InboundMessage::query()->create([
        'domain_id' => $this->domain->getKey(),
        'recipient' => 'support@pelda.hu',
        'status' => 'routed',
        'received_at' => now(),
    ]);

    $message->forceFill(['storage_key' => 'inbound/t.eml'])->save();
    $this->store->put('inbound/t.eml', 'Subject: rendben');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plain])
        ->get('/v1/inbound/messages/'.$message->uuid.'/raw');

    $response->assertOk();
    // Idegen tartalom: a böngésző ne találgassa a típust, és ne rendereljen.
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('a route ALÁÍRÓ TITKA egyszer látszik, és rotálható', function () {
    // Egy aláírás, amit a fogadó nem tud ellenőrizni, nem aláírás. A korábbi változat egy
    // `APP_KEY`-ből származtatott értékkel írt alá, amit sehol nem adtunk ki.
    $created = api('POST', '/v1/routes', [
        'match_type' => 'catch_all',
        'actions' => [['type' => 'forward', 'target' => 'https://hook.example.test/mail']],
    ]);

    $created->assertStatus(201);

    $secret = (string) $created->json('signing_secret');

    expect($secret)->toStartWith('rsec_');

    // A listában MÁR NEM.
    expect(json_encode(api('GET', '/v1/routes')->json()))->not->toContain($secret);

    $uj = (string) api('POST', '/v1/routes/'.$created->json('id').'/rotate-secret')->json('signing_secret');

    expect($uj)->not->toBe($secret);
    expect(InboundRoute::query()->firstOrFail()->signingSecret())->toBe($uj);
});

it('a forward célja csak e-mail-cím vagy https URL lehet', function (string $target) {
    // A korábbi „csak akkor kell https, ha `http`-vel kezdődik" szabály átengedte ezeket,
    // aztán a küldési úton némán elhaltak — az ügyfél sosem tudta meg, miért nem működik.
    expect(api('POST', '/v1/routes', [
        'match_type' => 'catch_all',
        'actions' => [['type' => 'forward', 'target' => $target]],
    ])->json('error.code'))->toBe('INVALID_ROUTE');
})->with([
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://belso.example/',
    'http' => 'http://hook.example.test/mail',
    'kukac nélkül' => 'support',
    'szemét' => 'nem-url-nem-email',
]);
