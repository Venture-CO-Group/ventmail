<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/*
 | M4-1 · A PLATFORM ÜZEMELTETŐI KÉPERNYŐI.
 |
 | Négy képernyő, egy közös szabály: a FELÜLET nem lehet kiskapu. Amit az API-n
 | jogosultság véd, azt itt is védeni kell — és amit a szolgáltatás megtilt (visszavonhatatlan
 | tiltás, SSRF-es webhook-URL), azt a felület sem kerülheti meg, mert épp ott a
 | legkönnyebb kattintani.
 */

function screenUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'screen-'.uniqid(),
        'name' => 'Teszt',
        'email' => 'screen-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

/*
 | DOMAINEK
 */

it('a domain-lista a NÉGY RÉSZ-ÁLLAPOTOT is mutatja, nem csak az összesítettet', function () {
    // A „pending" önmagában nem mondja meg, MELYIK rekord hiányzik — a támogatás fél
    // óráját pont az viszi el.
    $workspace = Workspace::factory()->create();
    $user = screenUser($workspace);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        Domain::create(['name' => 'kesz.hu', 'status' => 'verified', 'verified_at' => now()]);
        Domain::create(['name' => 'folyamatban.hu', 'status' => 'pending']);
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/domains')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Domains/Index')
            ->has('domains', 2)
            ->has('domains.0.spf')
            ->has('domains.0.dkim')
            ->has('domains.0.dmarc')
            ->has('domains.0.tracking')
        );
});

it('a domain-listát a naplót látó, de domaint nem kezelő szerep NEM nyithatja meg', function () {
    $workspace = Workspace::factory()->create();
    $analyst = screenUser($workspace, Role::Analyst->value);

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/domains')
        ->assertForbidden();
});

/*
 | API-KULCSOK
 */

it('a kiállított API-kulcs EGYSZER látszik — és utána már csak a prefix', function () {
    $workspace = Workspace::factory()->create();
    $user = screenUser($workspace);

    $response = $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/api-keys')
        ->post('/api-keys', ['name' => 'CI', 'scopes' => ['messages:send'], 'testmode' => false]);

    $response->assertRedirect('/api-keys');
    $response->assertSessionHas('secret');

    $plain = session('secret')['value'] ?? '';

    expect($plain)->toStartWith('vm_live_');

    /*
     | EGYSZER, azaz PONTOSAN EGY kérésben.
     |
     | A flash a KÖVETKEZŐ kérésig él — ott meg KELL jelennie, különben a felhasználó
     | sosem látná a kulcsot. A rá következőben viszont már nem lehet ott: ez a
     | „megmutatjuk, aztán soha" szabály mérhető alakja.
     */
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/api-keys')
        ->assertInertia(fn ($page) => $page
            ->component('Identity/ApiKeys')
            ->has('keys', 1)
            ->where('keys.0.prefix', substr((string) $plain, 0, 16))
            ->where('keys.0.scopes', fn (Collection $scopes) => $scopes->contains('messages:send'))
            ->where('flash.secret.value', $plain)
        );

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/api-keys')
        ->assertInertia(fn ($page) => $page->where('flash.secret', null))
        ->assertDontSee((string) $plain);

    // …és a TÁBLÁBAN sincs: kizárólag a hash és a prefix.
    expect(ApiKey::query()->where('key_hash', $plain)->exists())->toBeFalse();
});

it('az API-kulcs scope-ja KÖTELEZŐ és zárt listából jön', function () {
    // Egy scope nélküli kulcs vagy semmit nem tud, vagy — rosszabb — később valaki
    // „mindent szabad"-ként értelmezi. A kiállításnak explicitnek kell lennie.
    $workspace = Workspace::factory()->create();
    $user = screenUser($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/api-keys')
        ->post('/api-keys', ['name' => 'CI', 'scopes' => []])
        ->assertSessionHasErrors('scopes');

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/api-keys')
        ->post('/api-keys', ['name' => 'CI', 'scopes' => ['mindent:szabad']])
        ->assertSessionHasErrors('scopes.0');

    expect(ApiKey::query()->count())->toBe(0);
});

/*
 | SMTP-HITELESÍTŐK
 */

it('az SMTP-jelszó EGYSZER látszik, és a hitelesítő visszavonható', function () {
    $workspace = Workspace::factory()->create();
    $user = screenUser($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/smtp-credentials')
        ->post('/smtp-credentials', ['name' => 'Legacy CRM', 'channel' => 'transactional'])
        ->assertSessionHas('secret');

    $credential = SmtpCredential::query()->firstOrFail();

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/smtp-credentials')
        ->assertInertia(fn ($page) => $page
            ->component('Smtp/Credentials')
            ->has('credentials', 1)
            ->where('credentials.0.username', $credential->username)
        );

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/smtp-credentials')
        ->delete('/smtp-credentials/'.$credential->uuid)
        ->assertRedirect('/smtp-credentials');

    // VISSZAVONVA, de a nyoma megmarad: a naplóban szereplő SMTP-munkamenetek
    // visszakereshetők maradnak.
    $revoked = SmtpCredential::withTrashed()->find($credential->getKey());

    expect($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->trashed())->toBeTrue();
});

it('idegen workspace domainjére nem lehet SMTP-hitelesítőt kötni', function () {
    $enyem = Workspace::factory()->create();
    $idegen = Workspace::factory()->create();
    $user = screenUser($enyem);

    $idegenDomain = app(WorkspaceContext::class)->runFor(
        $idegen,
        fn () => Domain::create(['name' => 'idegen.hu', 'status' => 'verified', 'verified_at' => now()])
    );

    $this->actingAs($user)->withSession(['workspace_uuid' => $enyem->uuid])
        ->from('/smtp-credentials')
        ->post('/smtp-credentials', [
            'name' => 'Lopott',
            'domain' => $idegenDomain->uuid,
            'channel' => 'transactional',
        ])
        ->assertNotFound();

    expect(SmtpCredential::query()->count())->toBe(0);
});

/*
 | TILTÓLISTA
 */

it('a tiltólista felvesz, keres és felold — de a PANASZT nem oldja fel', function () {
    $workspace = Workspace::factory()->create();
    $user = screenUser($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/suppressions')
        ->post('/suppressions', ['email' => 'kezi@pelda.hu', 'reason' => 'manual', 'appliesTo' => 'all'])
        ->assertRedirect('/suppressions?list=manual');

    // A PANASZ az ÉRINTETT akarata: a tenant nem törölheti, hogy újra küldhessen.
    app(WorkspaceContext::class)->runFor($workspace, fn () => Suppression::create([
        'email' => 'panaszos@pelda.hu',
        'applies_to' => 'marketing',
        'reason' => 'complaint',
        'source' => 'fbl',
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/suppressions?list=complaints&search=panaszos')
        ->assertInertia(fn ($page) => $page
            ->component('Suppression/Index')
            ->has('suppressions.items', 1)
            // A FELÜLET TUDJA, mit nem lehet feloldani — különben a gomb ott van, a
            // kattintás pedig hibát ad.
            ->where('suppressions.items.0.revocable', false)
        );

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/suppressions')
        ->delete('/suppressions', ['email' => 'panaszos@pelda.hu', 'justification' => 'Az ügyfél kérte (jegy #1)'])
        ->assertSessionHas('error');

    expect(app(WorkspaceContext::class)->runFor(
        $workspace,
        fn () => Suppression::query()->where('email', 'panaszos@pelda.hu')->count()
    ))->toBe(1);

    // A KÉZI tiltás viszont feloldható.
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/suppressions')
        ->delete('/suppressions', ['email' => 'kezi@pelda.hu', 'justification' => 'Tévesen felvett cím'])
        ->assertSessionHas('success');
});

/*
 | WEBHOOKOK
 */

it('a webhook-végpont SSRF-es URL-t nem fogad el — ott, ahol beírják', function () {
    /*
     | A hibát OTT jelezzük, ahol az ügyfél beírja a címet, nem a hatodik sikertelen
     | kézbesítésnél. A felület ugyanazt a `WebhookUrlGuard`-ot használja, mint a `/v1`.
     */
    $workspace = Workspace::factory()->create();
    $user = screenUser($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/webhooks')
        ->post('/webhooks', ['name' => 'Belső', 'url' => 'http://169.254.169.254/latest/meta-data/'])
        ->assertSessionHas('error');

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('a webhook-végpont titka EGYSZER látszik, és a kézbesítés újrajátszható', function () {
    $workspace = Workspace::factory()->create();
    $user = screenUser($workspace);

    $endpoint = app(WorkspaceContext::class)->runFor(
        $workspace,
        fn () => WebhookEndpoint::issue('CRM', 'https://pelda.hu/hook', ['delivered'])
    );

    $delivery = app(WorkspaceContext::class)->runFor($workspace, fn () => $endpoint['endpoint']->deliveries()->create([
        'event_type' => 'delivered',
        'payload' => ['message' => 'teszt'],
        'status' => 'failed',
        'attempt' => 6,
        'dead_lettered_at' => now(),
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/webhooks')
        ->assertInertia(fn ($page) => $page
            ->component('Webhooks/Index')
            ->has('endpoints', 1)
            ->has('deliveries', 1)
            ->where('deliveries.0.replayable', true)
        );

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/webhooks')
        ->post('/webhooks/deliveries/'.$delivery->uuid.'/replay')
        ->assertSessionHas('success');

    // A REPLAY a számlálót NEM nullázza: a trace-nek meg kell mutatnia, hányadik
    // kísérletnél jártunk.
    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->attempt)->toBe(6)
        ->and($delivery->fresh()->next_attempt_at)->not->toBeNull();
});

it('minden platform-képernyő MÁS tenant adatát nem mutatja', function () {
    /*
     | A global scope ezt garantálja, de a képernyők ATTÓL még kaphatnának saját, scope
     | nélküli lekérdezést. Ez a teszt azért van, hogy egy ilyen csendben ne kerülhessen be.
     */
    $enyem = Workspace::factory()->create();
    $idegen = Workspace::factory()->create();
    $user = screenUser($enyem);

    app(WorkspaceContext::class)->runFor($idegen, function () {
        Domain::create(['name' => 'idegen.hu', 'status' => 'verified', 'verified_at' => now()]);
        ApiKey::issue('Idegen kulcs', ['messages:send']);
        SmtpCredential::issue('Idegen SMTP');
        Suppression::create(['email' => 'idegen@pelda.hu', 'applies_to' => 'all', 'reason' => 'manual', 'source' => 'ui']);
        WebhookEndpoint::issue('Idegen hook', 'https://idegen.hu/hook', []);
    });

    $session = ['workspace_uuid' => $enyem->uuid];

    $this->actingAs($user)->withSession($session)->get('/domains')
        ->assertInertia(fn ($page) => $page->has('domains', 0));

    $this->actingAs($user)->withSession($session)->get('/api-keys')
        ->assertInertia(fn ($page) => $page->has('keys', 0));

    $this->actingAs($user)->withSession($session)->get('/smtp-credentials')
        ->assertInertia(fn ($page) => $page->has('credentials', 0));

    $this->actingAs($user)->withSession($session)->get('/suppressions')
        ->assertInertia(fn ($page) => $page->has('suppressions.items', 0));

    $this->actingAs($user)->withSession($session)->get('/webhooks')
        ->assertInertia(fn ($page) => $page->has('endpoints', 0));
});
