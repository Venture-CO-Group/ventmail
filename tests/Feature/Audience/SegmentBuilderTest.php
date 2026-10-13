<?php

declare(strict_types=1);

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\AudienceResolver;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — SZEGMENSÉPÍTŐ.
 |
 | A tét: a kampány és a journey UGYANAZT a motort hívja, mint a szerkesztő előnézete. Ami
 | itt egy szélesebb halmazt ad a beállítottnál, az több embernek küld levelet — ezért a
 | tesztek a HALMAZT mérik, nem azt, hogy „nem dobott kivételt".
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->engine = app(SegmentEngine::class);
});

function segEmails(array $definition): array
{
    return app(SegmentEngine::class)->query($definition)->pluck('email')->sort()->values()->all();
}

it('a BEÁGYAZOTT csoport AND/OR szemantikája: all( A, any(B, C) )', function () {
    StudioFixtures::contact('anna@pelda.hu', ['first_name' => 'Anna', 'attributes' => ['city' => 'Budapest']]);
    StudioFixtures::contact('bela@pelda.hu', ['first_name' => 'Béla', 'attributes' => ['city' => 'Szeged']]);
    StudioFixtures::contact('cili@pelda.hu', ['first_name' => 'Cili', 'attributes' => ['city' => 'Pécs']]);
    StudioFixtures::contact('dani@masik.hu', ['first_name' => 'Dani', 'attributes' => ['city' => 'Budapest']]);

    $definition = [
        'match' => 'all',
        'rules' => [
            ['field' => 'email', 'op' => 'ends_with', 'value' => '@pelda.hu'],
            ['group' => [
                'match' => 'any',
                'rules' => [
                    ['field' => 'attr:city', 'op' => 'is', 'value' => 'Budapest'],
                    ['field' => 'attr:city', 'op' => 'is', 'value' => 'Pécs'],
                ],
            ]],
        ],
    ];

    expect(segEmails($definition))->toBe(['anna@pelda.hu', 'cili@pelda.hu']);
});

it('a régi, lapos definíció változatlanul működik (visszafelé kompatibilis)', function () {
    StudioFixtures::contact('anna@pelda.hu', ['first_name' => 'Anna']);
    StudioFixtures::contact('bela@pelda.hu', ['first_name' => 'Béla']);

    expect(segEmails(['match' => 'any', 'rules' => [
        ['field' => 'first_name', 'op' => 'is', 'value' => 'Anna'],
    ]]))->toBe(['anna@pelda.hu']);
});

it('a csoport mélysége és a szabályok ÖSSZES száma korlátos — a fán át is', function () {
    $deep = ['match' => 'all', 'rules' => [['group' => ['match' => 'all', 'rules' => [
        ['group' => ['match' => 'all', 'rules' => [
            ['group' => ['match' => 'all', 'rules' => [
                ['field' => 'email', 'op' => 'set'],
            ]]],
        ]]],
    ]]]]];

    expect(fn () => $this->engine->query($deep))->toThrow(InvalidArgumentException::class);

    // 30 + 30 szabály két csoportban: külön-külön a plafon alatt, együtt fölötte.
    $many = fn () => array_fill(0, 30, ['field' => 'email', 'op' => 'set']);

    expect(fn () => $this->engine->query(['match' => 'any', 'rules' => [
        ['group' => ['match' => 'all', 'rules' => $many()]],
        ['group' => ['match' => 'all', 'rules' => $many()]],
    ]]))->toThrow(InvalidArgumentException::class);
});

it('ismeretlen operátor, mező és csoport-alak HANGOS hiba — nem csendes eldobás', function (array $rule) {
    expect(fn () => $this->engine->query(['match' => 'all', 'rules' => [$rule]]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'operátor' => [['field' => 'email', 'op' => 'drop table']],
    'mező' => [['field' => 'password', 'op' => 'is', 'value' => 'x']],
    'attribútum-név' => [['field' => "attr:x'); --", 'op' => 'is', 'value' => 'x']],
    'csoport' => [['group' => 'nem objektum']],
    'engagement nap' => [['field' => 'engagement', 'op' => 'opened_within_days', 'value' => 'tegnap']],
    'consent érték' => [['field' => 'consent', 'op' => 'is', 'value' => 'mindegy']],
    'forrás érték' => [['field' => 'source', 'op' => 'is', 'value' => 'pirate']],
]);

it('engagement: „megnyitotta az elmúlt N napban" — a SAJÁT workspace eseményeiből', function () {
    $ws = (int) $this->workspace->getKey();

    StudioFixtures::contact('friss@pelda.hu');
    StudioFixtures::contact('regi@pelda.hu');
    StudioFixtures::contact('soha@pelda.hu');
    StudioFixtures::contact('kattint@pelda.hu');

    StudioFixtures::engagement($ws, 'friss@pelda.hu', now()->subDays(3));
    StudioFixtures::engagement($ws, 'regi@pelda.hu', now()->subDays(200));
    StudioFixtures::engagement($ws, 'soha@pelda.hu');
    StudioFixtures::engagement($ws, 'kattint@pelda.hu', now()->subDay(), now()->subDay());

    // IDEGEN workspace UGYANARRA a címre (a hash workspace-független!) friss nyitással: nem
    // számíthat bele — különben egy másik tenant forgalma döntené el, ki kap levelet.
    $other = Workspace::factory()->create();
    StudioFixtures::engagement((int) $other->getKey(), 'soha@pelda.hu', now()->subDay());

    $within = fn (string $op, int $days) => segEmails(['match' => 'all', 'rules' => [
        ['field' => 'engagement', 'op' => $op, 'value' => $days],
    ]]);

    expect($within('opened_within_days', 30))->toBe(['friss@pelda.hu', 'kattint@pelda.hu'])
        ->and($within('clicked_within_days', 30))->toBe(['kattint@pelda.hu'])
        ->and($within('not_opened_within_days', 30))->toBe(['regi@pelda.hu', 'soha@pelda.hu'])
        ->and(segEmails(['match' => 'all', 'rules' => [['field' => 'engagement', 'op' => 'never_opened']]]))
        ->toBe(['soha@pelda.hu']);
});

it('a régi (v1.1 előtti) engagement-sor első nyitása is számít', function () {
    StudioFixtures::contact('regisor@pelda.hu');
    StudioFixtures::engagement((int) $this->workspace->getKey(), 'regisor@pelda.hu', now()->subDays(2));

    CampaignEngagement::query()->update(['last_opened_at' => null]);

    expect(segEmails(['match' => 'all', 'rules' => [
        ['field' => 'engagement', 'op' => 'opened_within_days', 'value' => 7],
    ]]))->toBe(['regisor@pelda.hu']);
});

it('consent-állapot: megerősített, függő, visszavont, nincs', function () {
    StudioFixtures::contact('ok@pelda.hu');
    StudioFixtures::contact('fugg@pelda.hu', ['status' => 'pending']);
    StudioFixtures::contact('vissza@pelda.hu');
    StudioFixtures::contact('nincs@pelda.hu');

    StudioFixtures::consent('ok@pelda.hu', 'confirmed');
    StudioFixtures::consent('fugg@pelda.hu', 'pending');
    StudioFixtures::consent('vissza@pelda.hu', 'revoked');

    $consent = fn (string $op, string $value) => segEmails(['match' => 'all', 'rules' => [
        ['field' => 'consent', 'op' => $op, 'value' => $value],
    ]]);

    expect($consent('is', 'confirmed'))->toBe(['ok@pelda.hu'])
        ->and($consent('is', 'pending'))->toBe(['fugg@pelda.hu'])
        ->and($consent('is', 'revoked'))->toBe(['vissza@pelda.hu'])
        ->and($consent('is', 'none'))->toBe(['nincs@pelda.hu'])
        ->and($consent('is_not', 'confirmed'))->toBe(['fugg@pelda.hu', 'nincs@pelda.hu', 'vissza@pelda.hu']);
});

it('feliratkozási forrás: a saját oszlop, különben a consent forrása, különben kézi', function () {
    StudioFixtures::contact('urlap@pelda.hu', ['source' => 'form']);
    StudioFixtures::contact('import@pelda.hu', ['source' => 'import']);
    StudioFixtures::contact('regi-urlap@pelda.hu');
    StudioFixtures::contact('kezi@pelda.hu');

    StudioFixtures::consent('regi-urlap@pelda.hu', 'pending', 'form');

    $source = fn (string $value) => segEmails(['match' => 'all', 'rules' => [
        ['field' => 'source', 'op' => 'is', 'value' => $value],
    ]]);

    expect($source('form'))->toBe(['regi-urlap@pelda.hu', 'urlap@pelda.hu'])
        ->and($source('import'))->toBe(['import@pelda.hu'])
        ->and($source('manual'))->toBe(['kezi@pelda.hu']);
});

it('feliratkozás dátuma relatívan: az elmúlt N napban', function () {
    $new = StudioFixtures::contact('uj@pelda.hu');
    $old = StudioFixtures::contact('regi@pelda.hu');

    Contact::query()->whereKey($old->getKey())->update(['created_at' => now()->subDays(60)]);

    expect(segEmails(['match' => 'all', 'rules' => [
        ['field' => 'created_at', 'op' => 'within_days', 'value' => 30],
    ]]))->toBe(['uj@pelda.hu'])
        ->and(segEmails(['match' => 'all', 'rules' => [
            ['field' => 'created_at', 'op' => 'not_within_days', 'value' => 30],
        ]]))->toBe(['regi@pelda.hu']);

    expect($new)->not->toBeNull();
});

it('TÍPUSOS egyéni mező: szám összehasonlítás számként, nem szövegként', function () {
    ContactField::create(['key' => 'ltv', 'label' => 'LTV', 'type' => 'number']);

    StudioFixtures::contact('kilenc@pelda.hu', ['attributes' => ['ltv' => 9]]);
    StudioFixtures::contact('tizenot@pelda.hu', ['attributes' => ['ltv' => 15]]);
    StudioFixtures::contact('szoveg@pelda.hu', ['attributes' => ['ltv' => 'sok']]);

    // Szövegként a "15" < "9" — a számként értelmezett mező ezt nem hibázhatja el, és a nem
    // szám érték nem dönti el a lekérdezést (nincs cast-hiba → 500).
    expect(segEmails(['match' => 'all', 'rules' => [
        ['field' => 'attr:ltv', 'op' => 'gt', 'value' => 10],
    ]]))->toBe(['tizenot@pelda.hu']);
});

it('TÍPUSOS egyéni mező: dátum az elmúlt N napban', function () {
    ContactField::create(['key' => 'last_order', 'label' => 'Utolsó rendelés', 'type' => 'date']);

    StudioFixtures::contact('friss@pelda.hu', ['attributes' => ['last_order' => now()->subDays(2)->toDateString()]]);
    StudioFixtures::contact('regi@pelda.hu', ['attributes' => ['last_order' => '2020-01-01']]);
    StudioFixtures::contact('hibas@pelda.hu', ['attributes' => ['last_order' => '2024-02-31x']]);

    expect(segEmails(['match' => 'all', 'rules' => [
        ['field' => 'attr:last_order', 'op' => 'within_days', 'value' => 30],
    ]]))->toBe(['friss@pelda.hu']);
});

it('lista- és címke-tagság a csoportokban is', function () {
    $list = ContactList::create(['name' => 'Hírlevél']);
    $tag = ContactTag::create(['name' => 'vip']);

    $a = StudioFixtures::contact('a@pelda.hu');
    $b = StudioFixtures::contact('b@pelda.hu');
    StudioFixtures::contact('c@pelda.hu');

    $a->lists()->attach($list->getKey(), ['status' => 'subscribed']);
    $b->tags()->attach($tag->getKey());

    expect(segEmails(['match' => 'any', 'rules' => [
        ['field' => 'list', 'op' => 'in_list', 'value' => (string) $list->uuid],
        ['group' => ['match' => 'all', 'rules' => [
            ['field' => 'tag', 'op' => 'has_tag', 'value' => (string) $tag->uuid],
        ]]],
    ]]))->toBe(['a@pelda.hu', 'b@pelda.hu']);
});

it('a KAMPÁNY célzása ugyanazt a beágyazott szemantikát adja, mint az előnézet', function () {
    StudioFixtures::contact('anna@pelda.hu', ['attributes' => ['city' => 'Budapest']]);
    StudioFixtures::contact('bela@pelda.hu', ['attributes' => ['city' => 'Szeged']]);

    $definition = ['match' => 'all', 'rules' => [
        ['group' => ['match' => 'any', 'rules' => [
            ['field' => 'attr:city', 'op' => 'is', 'value' => 'Budapest'],
        ]]],
    ]];

    $segment = Segment::create(['name' => 'Budapest', 'definition' => $definition]);
    $campaign = Campaign::create(['name' => 'K', 'subject' => 'T', 'target_segments' => [(string) $segment->uuid]]);

    $resolved = collect(app(AudienceResolver::class)->resolve($campaign))->pluck('email')->all();

    expect($resolved)->toBe(segEmails($definition))->toBe(['anna@pelda.hu']);
});

it('a katalógus a SZERVERRŐL jön: mezők, típusok, operátorok és az egyéni mezők', function () {
    ContactField::create(['key' => 'ltv', 'label' => 'LTV', 'type' => 'number']);

    $catalog = $this->engine->catalog();

    expect(collect($catalog['fields'])->pluck('key'))->toContain('email', 'consent', 'source', 'engagement', 'list', 'tag', 'attr:ltv')
        ->and(collect($catalog['fields'])->firstWhere('key', 'attr:ltv')['type'])->toBe('number');
});

it('az ELŐNÉZET élő számot ad, a hibás definíció 422 — nem 500', function () {
    $user = StudioFixtures::user($this->workspace);

    StudioFixtures::contact('anna@pelda.hu');
    StudioFixtures::contact('bela@masik.hu');

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->postJson('/audience/segments/preview', ['definition' => ['match' => 'all', 'rules' => [
            ['field' => 'email', 'op' => 'ends_with', 'value' => '@pelda.hu'],
        ]]])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonCount(1, 'sample');

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->postJson('/audience/segments/preview', ['definition' => ['match' => 'all', 'rules' => [
            ['field' => 'email', 'op' => 'sql'],
        ]]])
        ->assertStatus(422);
});

it('MENTÉS: a szegmens létrejön a becsült mérettel, szerkeszthető és törölhető — auditálva', function () {
    $user = StudioFixtures::user($this->workspace);

    StudioFixtures::contact('anna@pelda.hu');

    $definition = ['match' => 'all', 'rules' => [['field' => 'email', 'op' => 'contains', 'value' => 'anna']]];

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->post('/audience/segments', ['name' => 'Annák', 'description' => null, 'definition' => $definition])
        ->assertRedirect();

    $segment = Segment::query()->where('name', 'Annák')->firstOrFail();

    expect($segment->estimated_size)->toBe(1)
        ->and($segment->definition)->toEqual($definition);

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->put('/audience/segments/'.$segment->uuid, ['name' => 'Annák 2', 'definition' => $definition])
        ->assertRedirect();

    expect($segment->fresh()->name)->toBe('Annák 2');

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->delete('/audience/segments/'.$segment->uuid)
        ->assertRedirect();

    expect(Segment::query()->whereKey($segment->getKey())->exists())->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', Segment::class)->count())->toBeGreaterThanOrEqual(3);
});

it('IDEGEN workspace szegmense nem nyitható, nem írható', function () {
    $user = StudioFixtures::user($this->workspace);

    $other = Workspace::factory()->create();
    $foreign = $this->context->runFor($other, fn () => Segment::create([
        'name' => 'Idegen', 'definition' => ['match' => 'all', 'rules' => []],
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/audience/segments/'.$foreign->uuid)
        ->assertNotFound();

    $this->actingAs($user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->delete('/audience/segments/'.$foreign->uuid)
        ->assertNotFound();
});

it('a szegmens-képernyők az audience.manage képességhez kötöttek', function () {
    $viewer = StudioFixtures::user($this->workspace, 'analyst');

    $this->actingAs($viewer)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/audience/segments')
        ->assertForbidden();

    $this->actingAs($viewer)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->postJson('/audience/segments/preview', ['definition' => ['match' => 'all', 'rules' => []]])
        ->assertForbidden();
});
