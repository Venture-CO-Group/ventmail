<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/*
 | A1 #2 és #6 — nyers MIME továbbítás és tárolt sablonok.
 |
 | A MIME-út azoknak kell, akik saját levelet állítanak össze (pl. Rails ActionMailer):
 | enélkül az integrációt át kellene írni, az M1 DoD-ja pedig épp ezt tiltja.
 */

beforeEach(function () {
    FakeDriver::reset();

    app(WorkspaceContext::class)->set(Workspace::factory()->create());
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
    ['plain' => $this->plain] = ApiKey::issue('CI', ['messages:send', 'templates:write', 'templates:read']);
});

function authHeaders(array $extra = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.test()->plain,
        'Idempotency-Key' => 'k-'.bin2hex(random_bytes(5)),
        'Accept' => 'application/json',
    ], $extra);
}

function rawMime(): string
{
    return implode("\r\n", [
        'From: ventMail <no-reply@pelda.hu>',
        'To: cimzett@example.test',
        'Subject: Nyers MIME teszt',
        'X-Sajat-Fejlec: megorizve',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Szia, ez egy nyers MIME levél.',
        '',
    ]);
}

it('nyers MIME küldés multipart fájlként', function () {
    $file = UploadedFile::fake()->createWithContent('message.mime', rawMime());

    $this->withHeaders(authHeaders())
        ->post('/v1/messages.mime', ['to' => 'cimzett@example.test', 'message' => $file])
        ->assertAccepted();

    $message = Message::firstOrFail();

    expect($message->from_email)->toBe('no-reply@pelda.hu');
    expect($message->subject)->toBe('Nyers MIME teszt');
    // A saját fejlécek MEGŐRIZVE (a levél a feladó összeállítása szerint megy ki).
    expect($message->headers)->toHaveKey('X-Sajat-Fejlec');
    expect($message->options['raw_mime'])->toBeTrue();
});

it('a MIME-küldés is a PreSendGate-en megy át', function () {
    Domain::query()->update(['status' => 'pending']);

    $file = UploadedFile::fake()->createWithContent('message.mime', rawMime());

    $this->withHeaders(authHeaders())
        ->post('/v1/messages.mime', ['to' => 'cimzett@example.test', 'message' => $file])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DOMAIN_UNVERIFIED');
});

it('MIME fájl nélkül 400', function () {
    $this->withHeaders(authHeaders())
        ->post('/v1/messages.mime', ['to' => 'cimzett@example.test'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'MISSING_MIME');
});

it('tárolt sablon létrehozása verzióval', function () {
    $this->withHeaders(authHeaders())
        ->postJson('/v1/templates', [
            'name' => 'welcome',
            'description' => 'Üdvözlő levél',
            'template' => '<p>Szia {{ first_name }}!</p>',
        ])
        ->assertCreated()
        ->assertJsonPath('template.name', 'welcome')
        ->assertJsonPath('template.version', 1);

    expect(StoredTemplate::firstOrFail()->versions()->count())->toBe(1);
});

it('új verzió aktiválása', function () {
    $this->withHeaders(authHeaders())->postJson('/v1/templates', [
        'name' => 'welcome', 'template' => '<p>v1</p>',
    ])->assertCreated();

    $this->withHeaders(authHeaders())->postJson('/v1/templates/welcome/versions', [
        'template' => '<p>v2</p>', 'active' => true,
    ])->assertCreated()->assertJsonPath('version.version', 2);

    expect(StoredTemplate::firstOrFail()->activeVersion()->body)->toBe('<p>v2</p>');
});

it('sablonnal küldés behelyettesítéssel', function () {
    $this->withHeaders(authHeaders())->postJson('/v1/templates', [
        'name' => 'welcome', 'template' => '<p>Szia {{ first_name }}, egyenleg: {{ balance }}</p>',
    ])->assertCreated();

    $this->withHeaders(authHeaders())->postJson('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'subject' => 'Üdv',
        'template' => 'welcome',
        'v:first_name' => 'Anna',
        'v:balance' => '1000',
    ])->assertAccepted();

    // A behelyettesítés a KÜLDÉSI úton történik, egyetlen passban: a `messages` soron a
    // KANONIKUS sablon marad (a kétszeres renderelés gadget volt), a kirenderelt levelet
    // a driver kapja.
    $rendered = FakeDriver::$sent[0]['bodies']['cimzett@example.test']['html'];

    expect($rendered)->toContain('Szia Anna')->toContain('1000');
});

it('a sablon auto-escape-eli a változókat', function () {
    // XSS-kísérlet: a behelyettesítés SOHA nem lehet nyers HTML.
    $this->withHeaders(authHeaders())->postJson('/v1/templates', [
        'name' => 'welcome', 'template' => '<p>Szia {{ first_name }}</p>',
    ])->assertCreated();

    $this->withHeaders(authHeaders())->postJson('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'template' => 'welcome',
        'v:first_name' => '<script>alert(1)</script>',
    ])->assertAccepted();

    $rendered = FakeDriver::$sent[0]['bodies']['cimzett@example.test']['html'];

    expect($rendered)->not->toContain('<script>');
    expect($rendered)->toContain('&lt;script&gt;');
});

it('hiányzó változó fallback, nem hiba', function () {
    $this->withHeaders(authHeaders())->postJson('/v1/templates', [
        'name' => 'welcome', 'template' => '<p>Szia {{ first_name }}!</p>',
    ])->assertCreated();

    $this->withHeaders(authHeaders())->postJson('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'template' => 'welcome',
    ])->assertAccepted();

    expect(FakeDriver::$sent[0]['bodies']['cimzett@example.test']['html'])->toContain('Szia !');
});

it('ismeretlen sablonra 400', function () {
    $this->withHeaders(authHeaders())->postJson('/v1/messages', [
        'from' => 'no-reply@pelda.hu',
        'to' => 'cimzett@example.test',
        'template' => 'nincs-ilyen',
    ])->assertStatus(400)->assertJsonPath('error.code', 'UNKNOWN_TEMPLATE');
});

it('templates:write scope nélkül 403', function () {
    ['plain' => $plain] = ApiKey::issue('Szűk', ['messages:send']);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'])
        ->postJson('/v1/templates', ['name' => 'x', 'template' => '<p>x</p>'])
        ->assertForbidden();
});
