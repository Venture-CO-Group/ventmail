<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | v1.1 · 7. modul — tiltólisták: külön listák, indoklással auditált törlés, a panasz nem
 | törölhető (felületen ÉS /v1-en), `/v1/suppressions` (D174).
 */

beforeEach(function () {
    $this->withoutVite();
    $this->workspace = Workspace::factory()->create();

    $this->user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'sup-'.uniqid(),
        'name' => 'Üzemeltető',
        'email' => 'sup-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);
    $this->workspace->members()->create(['user_id' => $this->user->getKey(), 'roles' => ['developer']]);

    app(WorkspaceContext::class)->runFor($this->workspace, function (): void {
        foreach ([
            ['bounce@example.test', 'bounce'],
            ['blokk@example.test', 'blocked'],
            ['panasz@example.test', 'complaint'],
            ['leiratkozott@example.test', 'unsubscribe'],
            ['kezi@example.test', 'manual'],
        ] as [$email, $reason]) {
            Suppression::create(['email' => $email, 'reason' => $reason, 'applies_to' => 'all', 'source' => 'test']);
        }
    });

    ['plain' => $this->plain] = app(WorkspaceContext::class)->runFor(
        $this->workspace,
        fn () => ApiKey::issue('Sync', ['suppressions:read', 'suppressions:write']),
    );
});

function supAs(): mixed
{
    return test()->actingAs(test()->user)->withSession(['workspace_uuid' => test()->workspace->uuid]);
}

function supApi(): mixed
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->plain]);
}

it('a felület négy külön listát ad, darabszámmal', function () {
    supAs()->get('/suppressions')
        ->assertInertia(fn ($page) => $page
            ->where('list', 'bounces')
            ->where('counts', ['bounces' => 2, 'complaints' => 1, 'unsubscribes' => 1, 'manual' => 1])
            ->has('suppressions.items', 2)
        );

    supAs()->get('/suppressions?list=complaints')
        ->assertInertia(fn ($page) => $page->has('suppressions.items', 1)->where('suppressions.items.0.revocable', false));

    supAs()->get('/suppressions?list=manual&search=kezi')
        ->assertInertia(fn ($page) => $page->has('suppressions.items', 1)->where('suppressions.items.0.email', 'kezi@example.test'));
});

it('a törlés indoklást kér, és az indoklás az auditban van', function () {
    supAs()->from('/suppressions')->delete('/suppressions', ['email' => 'kezi@example.test'])
        ->assertSessionHasErrors('justification');

    supAs()->from('/suppressions')->delete('/suppressions', ['email' => 'kezi@example.test', 'justification' => 'Jegy #2041: tévesen felvett cím'])
        ->assertSessionHas('success');

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'suppression.removed')->firstOrFail();

    expect($audit->context['justification'])->toBe('Jegy #2041: tévesen felvett cím')
        ->and($audit->context['reason'])->toBe('manual')
        ->and($audit->context['via'])->toBe('ui');
});

it('a panasz a felületen sem törölhető, indoklással sem', function () {
    supAs()->from('/suppressions')->delete('/suppressions', ['email' => 'panasz@example.test', 'justification' => 'Az ügyfél kérte'])
        ->assertSessionHas('error');

    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => Suppression::query()->where('email', 'panasz@example.test')->exists()))->toBeTrue();
});

it('a /v1 lista szűr és lapoz, és a scope nélküli kulcs 403', function () {
    supApi()->getJson('/v1/suppressions?list=bounces&limit=1')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.deletable', true)
        ->assertJsonStructure(['items' => [['id', 'email', 'reason', 'applies_to', 'deletable']], 'paging' => ['next']]);

    supApi()->getJson('/v1/suppressions?list=nincs')->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_ERROR');

    ['plain' => $readOnly] = app(WorkspaceContext::class)->runFor($this->workspace, fn () => ApiKey::issue('RO', ['events:read']));

    $this->withHeaders(['Authorization' => 'Bearer '.$readOnly])->getJson('/v1/suppressions')
        ->assertStatus(403)->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE');
});

it('a /v1 felvétel monoton, és a policy ok nem írható', function () {
    supApi()->postJson('/v1/suppressions', ['email' => 'Uj@Example.test', 'reason' => 'manual'])
        ->assertCreated()
        ->assertJsonPath('reason', 'manual');

    // A panaszt egy későbbi kézi tiltás nem írja felül.
    supApi()->postJson('/v1/suppressions', ['email' => 'panasz@example.test', 'reason' => 'manual'])
        ->assertCreated()
        ->assertJsonPath('reason', 'complaint');

    supApi()->postJson('/v1/suppressions', ['email' => 'x@example.test', 'reason' => 'policy'])
        ->assertStatus(400);
});

it('a /v1 törlés indoklást kér, a panaszt és a leiratkozást 403-mal elutasítja, auditál', function () {
    supApi()->deleteJson('/v1/suppressions/kezi@example.test')
        ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_ERROR');

    supApi()->deleteJson('/v1/suppressions/panasz@example.test', ['reason' => 'CRM-szinkron'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SUPPRESSION_IRREVOCABLE');

    supApi()->deleteJson('/v1/suppressions/leiratkozott@example.test', ['reason' => 'CRM-szinkron'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SUPPRESSION_IRREVOCABLE');

    supApi()->deleteJson('/v1/suppressions/'.rawurlencode('bounce@example.test'), ['reason' => 'A postafiók javítva'])
        ->assertNoContent();

    supApi()->deleteJson('/v1/suppressions/nincs@example.test', ['reason' => 'x-y-z'])
        ->assertNotFound()->assertJsonPath('error.code', 'UNKNOWN_SUPPRESSION');

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'suppression.removed')->firstOrFail();

    expect($audit->context['via'])->toStartWith('api:')
        ->and($audit->context['justification'])->toBe('A postafiók javítva');
});

it('a /v1 tiltólista tenant-izolált', function () {
    $idegen = Workspace::factory()->create();
    app(WorkspaceContext::class)->runFor($idegen, fn () => Suppression::create(['email' => 'idegen@example.test', 'reason' => 'manual', 'applies_to' => 'all', 'source' => 'test']));

    supApi()->getJson('/v1/suppressions?search=idegen')->assertOk()->assertJsonCount(0, 'items');
    supApi()->deleteJson('/v1/suppressions/idegen@example.test', ['reason' => 'próba'])->assertNotFound();
});
