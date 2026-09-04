<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | A3 · 22. és 29. kritérium — a napló-képernyő szerveroldali szerződése.
 |
 | A Playwright a böngésző-flow-t méri; ITT azt, hogy a szűrés valóban a szerveren történik
 | (nem kliens-oldali rejtés), és hogy idegen tenant üzenete nem is kerül a válaszba.
 */

beforeEach(function () {
    // A backend-tesztek nem függhetnek a lefordított frontend-eszközöktől: a Vite-manifest
    // hiánya különben 500-at adna, és a szerződést nem mérnénk.
    $this->withoutVite();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $user = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $user->id, 'roles' => [Role::Developer->value]]);

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid]);
});

function logMessage(array $overrides = []): void
{
    app(SendMessage::class)(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'to' => ['cimzett@example.test'],
        'html' => '<p>Szia</p>',
        'tags' => ['szamla'],
        'idempotency_key' => 'l-'.bin2hex(random_bytes(5)),
    ], $overrides));
}

it('a napló listázza a saját üzeneteket', function () {
    logMessage();

    $this->get('/logs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logs/Index')
            ->has('messages.items', 1)
            ->where('messages.items.0.status', 'delivered'));
});

it('a szűrés a SZERVEREN történik', function () {
    logMessage(['tags' => ['szamla']]);
    logMessage(['tags' => ['hirlevel']]);

    $this->get('/logs?tag=hirlevel')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('messages.items', 1)
            ->where('messages.items.0.tags.0', 'hirlevel'));
});

it('idegen tenant üzenete nem kerül a válaszba', function () {
    logMessage();

    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);

    $idegen = User::factory()->create();
    $masik->members()->create(['user_id' => $idegen->id, 'roles' => [Role::Developer->value]]);

    $this->actingAs($idegen)->withSession(['workspace_uuid' => $masik->uuid]);

    $this->get('/logs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('messages.items', 0));
});

it('a trace-képernyő a teljes láncot átadja', function () {
    logMessage();

    $uuid = Message::firstOrFail()->uuid;

    $this->get("/logs/{$uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logs/Trace')
            ->has('trace.gate.gates')
            ->has('trace.events')
            ->where('trace.message.uuid', $uuid));
});

it('ismeretlen üzenetre 404 — nem 500', function () {
    $this->get('/logs/'.Str::uuid())->assertNotFound();
});

/*
 | JOGOSULTSÁG — a workspace-tagság önmagában NEM elég.
 |
 | A napló a címzettek e-mail-címét, a levél tárgyát és a fogadó szerver válaszát mutatja.
 | Az `AbilityMap` fehérlistás, és az `author` szerep szándékosan nem kapja meg a
 | `logs.view`-t: aki szöveget ír, annak nincs dolga a címlistával.
 */

it('a logs.view nélküli szerep NEM látja a naplót', function () {
    $author = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $author->id, 'roles' => [Role::Author->value]]);

    logMessage();

    $message = Message::query()->firstOrFail();

    $this->actingAs($author)->withSession(['workspace_uuid' => $this->workspace->uuid]);

    $this->get('/logs')->assertForbidden();
    $this->get('/logs/'.$message->uuid)->assertForbidden();
});

it('a logs.view birtokában viszont látja', function () {
    logMessage();

    // A beforeEach `Developer` szerepe MEGKAPJA a `logs.view`-t — ha ez a teszt is 403-at
    // adna, a fenti tiltás csak azt bizonyítaná, hogy a felület mindenkinek elromlott.
    $this->get('/logs')->assertOk();
});

/*
 | A CÍMZETT-SZŰRŐ a tárolt alakra keres.
 |
 | A `messages.recipients` a `DomainNormalizer`-rel tárol: a helyi rész VÁLTOZATLAN, a
 | domain punycode. Naiv `mb_strtolower()`-rel a nagybetűs helyi rész és az ékezetes domain
 | is csendben 0 találatot adna — pont a 22. kritérium fő szűrője.
 */

it('a címzett-szűrő megtalálja a NAGYBETŰS helyi részt', function () {
    logMessage(['to' => ['Ugyfel@pelda.hu']]);

    $response = $this->get('/logs?recipient='.urlencode('Ugyfel@pelda.hu'));

    expect($response->viewData('page')['props']['messages']['items'])->toHaveCount(1);
});

it('a címzett-szűrő megtalálja az ÉKEZETES domaint is', function () {
    Domain::create(['name' => 'xn--plda-bpa.hu', 'status' => 'verified', 'verified_at' => now()]);

    logMessage(['from_email' => 'no-reply@xn--plda-bpa.hu', 'to' => ['user@példa.hu']]);

    // Az ügyfél az OLVASHATÓ alakot írja be — a tárolt alak punycode.
    $response = $this->get('/logs?recipient='.urlencode('user@példa.hu'));

    expect($response->viewData('page')['props']['messages']['items'])->toHaveCount(1);
});

/*
 | TAG-STATISZTIKA képernyő (26. kritérium).
 */

it('a statisztika-képernyő az analytics.view jogot kéri, a napló a logs.view-t', function () {
    // A kettő NEM ugyanaz, és a szerepek szándékosan eltérnek: a napló EGYEDI címzetteket
    // mutat, a statisztika aggregátumot. Az `author` az utóbbit megkapja, az előbbit nem —
    // aki szöveget ír, annak a kampánya eredményét látnia kell, a címlistát nem.
    $this->get('/logs/statistics')->assertOk();

    $author = User::factory()->create();
    $this->workspace->members()->create(['user_id' => $author->id, 'roles' => [Role::Author->value]]);

    $this->actingAs($author)->withSession(['workspace_uuid' => $this->workspace->uuid]);

    $this->get('/logs/statistics')->assertOk();
    $this->get('/logs')->assertForbidden();
});

it('a statisztika útvonala nem nyeli el az üzenet-azonosítót', function () {
    // A `/logs/statistics` a `/logs/{uuid}` ELŐTT van regisztrálva: fordított sorrendben a
    // „statistics" szó üzenet-azonosítónak látszana, és a képernyő 404-et adna.
    logMessage();

    $message = Message::query()->firstOrFail();

    $this->get('/logs/statistics')->assertOk();
    $this->get('/logs/'.$message->uuid)->assertOk();
});
