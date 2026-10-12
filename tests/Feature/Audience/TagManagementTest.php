<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Journey\Models\Journey;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — CÍMKÉK: kezelés (létrehozás, átnevezés, összevonás, törlés) és tömeges
 | címkézés a kontaktlistából és a szegmens-találatokból.
 |
 | A tömeges művelet EGY halmaz-utasítás (INSERT … SELECT), nem kontaktonkénti ciklus — és
 | a tenant-határt a kontakt-lekérdezés global scope-ja adja.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->user = StudioFixtures::user($this->workspace);
});

function tagAs($test)
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->workspace->uuid]);
}

function tagged(ContactTag $tag): array
{
    return $tag->contacts()->pluck('email')->sort()->values()->all();
}

it('a címke-lista a darabszámokkal jön — egyetlen csoportosított lekérdezésből', function () {
    $vip = ContactTag::create(['name' => 'vip']);
    $hirlevel = ContactTag::create(['name' => 'hírlevél']);

    StudioFixtures::contact('a@pelda.hu')->tags()->attach([$vip->getKey(), $hirlevel->getKey()]);
    StudioFixtures::contact('b@pelda.hu')->tags()->attach($vip->getKey());

    tagAs($this)->get('/audience/tags')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Audience/Tags')
            ->where('tags.0.name', 'hírlevél')
            ->where('tags.0.contacts', 1)
            ->where('tags.1.name', 'vip')
            ->where('tags.1.contacts', 2));
});

it('létrehozás és átnevezés — a név tenantonként egyedi, az ütközés validációs hiba', function () {
    tagAs($this)->post('/audience/tags', ['name' => 'vip'])->assertRedirect();
    tagAs($this)->post('/audience/tags', ['name' => 'vip'])->assertSessionHasErrors('name');

    $tag = ContactTag::query()->where('name', 'vip')->firstOrFail();

    tagAs($this)->put('/audience/tags/'.$tag->uuid, ['name' => 'VIP ügyfél'])->assertRedirect();

    expect($tag->fresh()->name)->toBe('VIP ügyfél');
});

it('ÖSSZEVONÁS: a tagság átkerül, a duplikátum nem duplázódik, a forrás-címke eltűnik — a szegmensek hivatkozása is átíródik', function () {
    $from = ContactTag::create(['name' => 'Vip']);
    $into = ContactTag::create(['name' => 'vip']);

    $a = StudioFixtures::contact('a@pelda.hu');
    $b = StudioFixtures::contact('b@pelda.hu');

    $a->tags()->attach([$from->getKey(), $into->getKey()]);
    $b->tags()->attach($from->getKey());

    $segment = Segment::create(['name' => 'Vipek', 'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'tag', 'op' => 'not_has_tag', 'value' => (string) $from->uuid],
    ]]]);

    tagAs($this)->post('/audience/tags/'.$from->uuid.'/merge', ['into' => (string) $into->uuid])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(ContactTag::query()->whereKey($from->getKey())->exists())->toBeFalse()
        ->and(tagged($into))->toBe(['a@pelda.hu', 'b@pelda.hu'])
        // A TÖRÖLT címkére mutató `not_has_tag` MINDENKIT beválasztana — ezért íródik át.
        ->and($segment->fresh()->definition['rules'][0]['value'])->toBe((string) $into->uuid)
        ->and(AuditLog::query()->where('action', 'audience.tag.merged')->exists())->toBeTrue();
});

it('TÖRLÉS: a hivatkozott címke nem törölhető (szegmens / journey), a többi igen — auditálva', function () {
    $used = ContactTag::create(['name' => 'használt']);
    $free = ContactTag::create(['name' => 'szabad']);

    StudioFixtures::contact('a@pelda.hu')->tags()->attach($free->getKey());

    Segment::create(['name' => 'S', 'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'tag', 'op' => 'has_tag', 'value' => (string) $used->uuid],
    ]]]);

    tagAs($this)->delete('/audience/tags/'.$used->uuid)->assertRedirect()->assertSessionHas('error');
    expect(ContactTag::query()->whereKey($used->getKey())->exists())->toBeTrue();

    tagAs($this)->delete('/audience/tags/'.$free->uuid)->assertRedirect()->assertSessionHas('success');
    expect(ContactTag::query()->whereKey($free->getKey())->exists())->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', ContactTag::class)->where('action', 'like', '%deleted%')->exists())->toBeTrue();
});

it('a journey által hivatkozott címke sem törölhető', function () {
    $tag = ContactTag::create(['name' => 'journey-cimke']);

    $journey = Journey::create(['name' => 'J', 'trigger_type' => 'tag_added', 'trigger_config' => ['tag' => (string) $tag->uuid]]);

    tagAs($this)->delete('/audience/tags/'.$tag->uuid)->assertRedirect()->assertSessionHas('error');

    expect(ContactTag::query()->whereKey($tag->getKey())->exists())->toBeTrue()
        ->and($journey)->not->toBeNull();
});

it('TÖMEGES címkézés a kijelölt kontaktokra — idegen workspace kontaktja kimarad', function () {
    $tag = ContactTag::create(['name' => 'kampány-2026']);

    $a = StudioFixtures::contact('a@pelda.hu');
    $b = StudioFixtures::contact('b@pelda.hu');
    StudioFixtures::contact('c@pelda.hu');

    $other = Workspace::factory()->create();
    $foreign = $this->context->runFor($other, fn () => StudioFixtures::contact('idegen@pelda.hu'));

    tagAs($this)->post('/audience/contacts/bulk-tags', [
        'action' => 'tag',
        'tagUuid' => (string) $tag->uuid,
        'target' => ['type' => 'contacts', 'uuids' => [(string) $a->uuid, (string) $b->uuid, (string) $foreign->uuid]],
    ])->assertRedirect()->assertSessionHas('success');

    expect(tagged($tag))->toBe(['a@pelda.hu', 'b@pelda.hu']);

    $this->context->runFor($other, fn () => expect(Contact::query()->first()->tags()->count())->toBe(0));

    // ISMÉTELT címkézés nem duplikál; a levétel csak a kijelölteket érinti.
    tagAs($this)->post('/audience/contacts/bulk-tags', [
        'action' => 'tag',
        'tagUuid' => (string) $tag->uuid,
        'target' => ['type' => 'contacts', 'uuids' => [(string) $a->uuid]],
    ])->assertRedirect();

    tagAs($this)->post('/audience/contacts/bulk-tags', [
        'action' => 'untag',
        'tagUuid' => (string) $tag->uuid,
        'target' => ['type' => 'contacts', 'uuids' => [(string) $a->uuid]],
    ])->assertRedirect();

    expect(tagged($tag))->toBe(['b@pelda.hu']);
});

it('TÖMEGES címkézés a szegmens TALÁLATAIRA — új címke névvel, köteg-szintű audit cím nélkül', function () {
    StudioFixtures::contact('anna@pelda.hu');
    StudioFixtures::contact('anita@pelda.hu');
    StudioFixtures::contact('bela@pelda.hu');

    $segment = Segment::create(['name' => 'An-ok', 'definition' => ['match' => 'all', 'rules' => [
        ['field' => 'email', 'op' => 'starts_with', 'value' => 'an'],
    ]]]);

    tagAs($this)->post('/audience/contacts/bulk-tags', [
        'action' => 'tag',
        'tagName' => 'an-betűsök',
        'target' => ['type' => 'segment', 'uuid' => (string) $segment->uuid],
    ])->assertRedirect()->assertSessionHas('success');

    $tag = ContactTag::query()->where('name', 'an-betűsök')->firstOrFail();

    expect(tagged($tag))->toBe(['anita@pelda.hu', 'anna@pelda.hu']);

    $audit = AuditLog::query()->where('action', 'audience.tags.bulk')->latest('id')->firstOrFail();

    expect($audit->context['affected'])->toBe(2)
        ->and($audit->context['target'])->toBe('segment')
        ->and(json_encode($audit->toArray()))->not->toContain('anna@pelda.hu');
});

it('TÖMEGES címkézés a keresés MINDEN találatára', function () {
    $tag = ContactTag::create(['name' => 'pelda']);

    StudioFixtures::contact('a@pelda.hu');
    StudioFixtures::contact('b@masik.hu');

    tagAs($this)->post('/audience/contacts/bulk-tags', [
        'action' => 'tag',
        'tagUuid' => (string) $tag->uuid,
        'target' => ['type' => 'search', 'search' => 'pelda.hu'],
    ])->assertRedirect();

    expect(tagged($tag))->toBe(['a@pelda.hu']);
});

it('a tömeges művelet bemenete korlátos és engedélylistás', function (array $payload) {
    $tag = ContactTag::create(['name' => 'x']);

    tagAs($this)->post('/audience/contacts/bulk-tags', array_merge(['action' => 'tag', 'tagUuid' => (string) $tag->uuid], $payload))
        ->assertSessionHasErrors();
})->with([
    'ismeretlen cél' => [['target' => ['type' => 'all-workspaces']]],
    'túl sok uuid' => [['target' => ['type' => 'contacts', 'uuids' => array_fill(0, 1001, '00000000-0000-7000-8000-000000000000')]]],
    'ismeretlen művelet' => [['action' => 'drop', 'target' => ['type' => 'search', 'search' => 'x']]],
]);

it('IDEGEN workspace címkéje nem használható és nem módosítható', function () {
    $other = Workspace::factory()->create();
    $foreign = $this->context->runFor($other, fn () => ContactTag::create(['name' => 'idegen']));

    $a = StudioFixtures::contact('a@pelda.hu');

    tagAs($this)->post('/audience/contacts/bulk-tags', [
        'action' => 'tag',
        'tagUuid' => (string) $foreign->uuid,
        'target' => ['type' => 'contacts', 'uuids' => [(string) $a->uuid]],
    ])->assertNotFound();

    tagAs($this)->put('/audience/tags/'.$foreign->uuid, ['name' => 'átírt'])->assertNotFound();
    tagAs($this)->delete('/audience/tags/'.$foreign->uuid)->assertNotFound();
});

it('a címke-képernyő és a tömeges művelet az audience.manage képességhez kötött', function () {
    $analyst = StudioFixtures::user($this->workspace, 'analyst');

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/audience/tags')->assertForbidden();

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->post('/audience/contacts/bulk-tags', ['action' => 'tag', 'tagName' => 'x', 'target' => ['type' => 'search', 'search' => '']])
        ->assertForbidden();
});
