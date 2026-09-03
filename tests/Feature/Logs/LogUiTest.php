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
