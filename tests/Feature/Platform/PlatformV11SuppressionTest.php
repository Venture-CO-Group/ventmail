<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
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

/*
 | Review 1. kör — B1: a visszavonhatatlan ok RAGADÓS; S2/RB1: a platform-szintű tiltás nem orákulum.
 */

it('B1: a leiratkozás bounce-szal nem írható át, és utána sem törölhető (API)', function () {
    supApi()->postJson('/v1/suppressions', ['email' => 'leiratkozott@example.test', 'reason' => 'bounce'])
        ->assertCreated()
        ->assertJsonPath('reason', 'unsubscribe')
        ->assertJsonPath('deletable', false);

    supApi()->deleteJson('/v1/suppressions/leiratkozott@example.test', ['reason' => 'feltámasztás'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SUPPRESSION_IRREVOCABLE');

    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => Suppression::query()->where('email', 'leiratkozott@example.test')->value('reason')))
        ->toBe('unsubscribe');
});

it('B1: a panasz policy-val nem írható át a felületen, a policy ok nem is írható tenantból', function () {
    supAs()->from('/suppressions')->post('/suppressions', ['email' => 'panasz@example.test', 'reason' => 'policy', 'appliesTo' => 'all'])
        ->assertSessionHasErrors('reason');

    supAs()->from('/suppressions')->post('/suppressions', ['email' => 'panasz@example.test', 'reason' => 'blocked', 'appliesTo' => 'all'])
        ->assertSessionHasNoErrors();

    supAs()->from('/suppressions')->delete('/suppressions', ['email' => 'panasz@example.test', 'justification' => 'feltámasztás'])
        ->assertSessionHas('error');

    expect(app(WorkspaceContext::class)->runFor($this->workspace, fn () => Suppression::query()->where('email', 'panasz@example.test')->value('reason')))
        ->toBe('complaint');
});

it('B1: egy bounce-ra érkező leiratkozás visszavonhatatlanná teszi a sort (a ragadós ok nyer)', function () {
    supApi()->postJson('/v1/suppressions', ['email' => 'bounce@example.test', 'reason' => 'unsubscribe'])
        ->assertCreated()->assertJsonPath('reason', 'unsubscribe');

    supApi()->deleteJson('/v1/suppressions/bounce@example.test', ['reason' => 'próba'])
        ->assertStatus(403)->assertJsonPath('error.code', 'SUPPRESSION_IRREVOCABLE');
});

it('S2/RB1: a platform-szintű tiltás nem látszik és nem orákulum, de a kapu továbbra is blokkol', function () {
    Suppression::onPlatform(fn () => Suppression::create(['email' => 'csapda@example.test', 'reason' => 'policy']));

    // Lista és keresés: nem jelenik meg (API és felület).
    supApi()->getJson('/v1/suppressions?search=csapda')->assertOk()->assertJsonCount(0, 'items');
    supApi()->getJson('/v1/suppressions?list=manual')->assertOk()->assertJsonMissing(['email' => 'csapda@example.test']);
    supAs()->get('/suppressions?list=manual&search=csapda')->assertInertia(fn ($page) => $page->has('suppressions.items', 0));

    // Törlés: ugyanaz a 404, mint egy nem létező címre.
    $missing = supApi()->deleteJson('/v1/suppressions/nincs-ilyen@example.test', ['reason' => 'próba']);
    $platform = supApi()->deleteJson('/v1/suppressions/csapda@example.test', ['reason' => 'próba']);

    expect($platform->status())->toBe(404)
        ->and($platform->json())->toBe($missing->json());

    // Felvétel: ugyanaz a 201, mint bármely címre — a tenant saját sora jön létre.
    supApi()->postJson('/v1/suppressions', ['email' => 'csapda@example.test', 'reason' => 'manual'])
        ->assertCreated()->assertJsonPath('reason', 'manual');

    // A saját sor törlése sem nyitja ki a címet: a platform-sor a PreSendGate 2. kapujában blokkol.
    supApi()->deleteJson('/v1/suppressions/csapda@example.test', ['reason' => 'próba'])->assertNoContent();

    app(WorkspaceContext::class)->runFor($this->workspace, function (): void {
        Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
        app(SendMessage::class)([
            'channel' => 'transactional', 'from_email' => 'no-reply@pelda.hu', 'to' => ['csapda@example.test'],
            'html' => '<p>x</p>', 'idempotency_key' => 'csapda-1',
        ]);

        $message = Message::query()->latest('id')->firstOrFail();

        expect($message->status)->toBe('rejected')->and($message->reason)->toBe('SUPPRESSED');
    });
});

it('NIT: a DELETE indoklása query-paraméterként is jöhet, és a + jeles cím nem romlik el', function () {
    supApi()->postJson('/v1/suppressions', ['email' => 'anna+hirlevel@example.test'])->assertCreated();

    supApi()->deleteJson('/v1/suppressions/'.rawurlencode('anna+hirlevel@example.test').'?reason=query-indok')
        ->assertNoContent();
});

it('N2 (D200): a felvétel a MENTETT ok listájára visz (ragadós leiratkozásnál a leiratkozásokhoz)', function () {
    supAs()->from('/suppressions')->post('/suppressions', ['email' => 'leiratkozott@example.test', 'reason' => 'blocked', 'appliesTo' => 'all'])
        ->assertRedirect('/suppressions?list=unsubscribes');
});

/*
 | Review 3 S2 (D205/D206): a legacy `hard_bounce`/`soft_bounce` olvasáskor `bounce`; az
 | automatikus bounce-tiltás mostantól `bounce` okkal, hard/soft a `detail`-ben.
 */
it('a legacy hard_bounce sor a Bounce-ok fülön és a /v1-en bounce-ként jelenik meg', function () {
    app(WorkspaceContext::class)->runFor($this->workspace, fn () => Suppression::create(['email' => 'regi@example.test', 'reason' => 'hard_bounce', 'applies_to' => 'all', 'source' => 'legacy']));

    supAs()->get('/suppressions?list=bounces&search=regi')
        ->assertInertia(fn ($page) => $page
            ->has('suppressions.items', 1)
            ->where('suppressions.items.0.reason', 'bounce')
            ->where('suppressions.items.0.detail', 'hard')
            ->where('counts.bounces', 3)
        );

    supApi()->getJson('/v1/suppressions?list=bounces&search=regi')
        ->assertOk()
        ->assertJsonPath('items.0.reason', 'bounce')
        ->assertJsonPath('items.0.detail', 'hard');

    // A későbbi kézi felvétel nem „fokozza le" (a legacy érték bounce-súlyú).
    supApi()->postJson('/v1/suppressions', ['email' => 'regi@example.test', 'reason' => 'manual'])
        ->assertCreated()->assertJsonPath('reason', 'bounce');
});

it('az automatikus bounce-tiltás bounce okkal íródik, hard/soft a detailben, és nem fokozható le', function () {
    $row = app(WorkspaceContext::class)->runFor($this->workspace, fn () => app(\App\Modules\Suppression\Services\SuppressionStore::class)->recordBounce('Uj.Bounce@example.test', true));

    expect($row->reason)->toBe('bounce')->and($row->detail)->toBe('hard');

    supApi()->postJson('/v1/suppressions', ['email' => 'uj.bounce@example.test', 'reason' => 'manual'])
        ->assertCreated()->assertJsonPath('reason', 'bounce');
});
