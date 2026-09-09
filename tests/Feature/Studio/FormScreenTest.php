<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Form;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M4-6 · ŰRLAP-ADMIN.
 |
 | A motor (`SubmitForm`, `PublicFormController`) régóta kész — a tenant viszont nem tudta
 | HASZNÁLNI: az űrlap slugja és a generált honeypot-mezőnév csak az adatbázisból volt
 | kiolvasható. A képernyő ezt a rést zárja be.
 */

function formUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'urlap-'.uniqid(),
        'name' => 'Marketinges',
        'email' => 'urlap-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

it('az űrlap-képernyő megmutatja a slugot és a GENERÁLT honeypot-mezőnevet', function () {
    /*
     | E kettő nélkül nem lehet működő űrlapot építeni: a csapda neve űrlaponként más
     | (ezért nem ismeri fel egy általános bot), és ha kimarad a beágyazott HTML-ből, a
     | bot-védelem CSENDBEN nem működik.
     */
    $workspace = Workspace::factory()->create();
    $user = formUser($workspace);

    $form = app(WorkspaceContext::class)->runFor($workspace, fn () => Form::create([
        'name' => 'Hírlevél',
        'consent_text' => 'Hozzájárulok.',
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/forms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Audience/Forms')
            ->has('forms', 1)
            ->where('forms.0.slug', (string) $form->slug)
            ->where('forms.0.honeypotField', (string) $form->honeypot_field)
            ->where('forms.0.active', true)
            ->where('endpointBase', rtrim((string) config('app.url'), '/').'/f/')
        );
});

it('a beágyazható kód a PUBLIKUS VÉGPONT szerződését hozza — csapdával és időméréssel', function () {
    /*
     | A `_elapsed` hiánya nem hiba a szerveren (egy régi integráció nem szűnhet meg
     | csendben működni) — épp ezért a beágyazott kódnak KELL hoznia, különben a
     | bot-védelem fele néma. A honeypot mezőnek pedig a GENERÁLT néven kell szerepelnie:
     | egy állandó név a bot-keretrendszerek kihagyási listáján van.
     */
    $workspace = Workspace::factory()->create();
    $user = formUser($workspace);

    $form = app(WorkspaceContext::class)->runFor($workspace, fn () => Form::create([
        'name' => 'Hírlevél',
        'consent_text' => 'Hozzájárulok.',
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/forms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('forms.0.snippet', fn ($snippet) => str_contains((string) $snippet, '/f/'.$form->slug)
            && str_contains((string) $snippet, 'name="'.$form->honeypot_field.'"')
            && str_contains((string) $snippet, 'name="_elapsed"')
            && str_contains((string) $snippet, 'name="email"')));
});

it('a TIME-TRAP nem kapcsolható ki a felületről', function () {
    /*
     | A `min_seconds = 0` a bot-védelem felét venné el, és a felületen egy ártalmatlan
     | „0"-nak látszana. A motor a küszöb ALATTI beküldést dobja el — nulla küszöbnél
     | tehát semmit.
     */
    $workspace = Workspace::factory()->create();
    $user = formUser($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/forms')
        ->post('/forms', [
            'name' => 'Rossz',
            'minSeconds' => 0,
            'consentText' => 'Hozzájárulok.',
        ])
        ->assertSessionHasErrors('minSeconds');

    expect(app(WorkspaceContext::class)->runFor($workspace, fn () => Form::query()->count()))->toBe(0);
});

it('az ÁTIRÁNYÍTÁS csak https lehet — a nyílt átirányító a saját domainünket rontaná', function () {
    $workspace = Workspace::factory()->create();
    $user = formUser($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/forms')
        ->post('/forms', [
            'name' => 'Rossz',
            'minSeconds' => 5,
            'consentText' => 'Hozzájárulok.',
            'redirectUrl' => 'http://pelda.hu/koszonjuk',
        ])
        ->assertSessionHasErrors('redirectUrl');
});

it('IDEGEN workspace listája nem köthető az űrlaphoz', function () {
    /*
     | A lista a global scope-on át keresődik: egy idegen uuid nem található, és `null`-lá
     | esik. Golden rule 6 — a felület nem köthet össze két tenantot.
     */
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    $user = formUser($mine);

    $foreignList = app(WorkspaceContext::class)->runFor($theirs, fn () => ContactList::create(['name' => 'Idegen lista']));

    $this->actingAs($user)->withSession(['workspace_uuid' => $mine->uuid])
        ->from('/forms')
        ->post('/forms', [
            'name' => 'Enyém',
            'listUuid' => (string) $foreignList->uuid,
            'minSeconds' => 5,
            'consentText' => 'Hozzájárulok.',
        ])
        ->assertRedirect('/forms');

    $form = app(WorkspaceContext::class)->runFor($mine, fn () => Form::query()->firstOrFail());

    expect($form->contact_list_id)->toBeNull();
});

it('a KIKAPCSOLÁS nem törlés: a végpont válaszol, de nem gyűjt', function () {
    /*
     | Az ügyfél oldalán maradt régi űrlap így nem hibázik, csak nem gyűjt — a törlés
     | pedig a bizonyíték-láncot rontaná (a `consent_events` a forrásra hivatkozik).
     */
    $workspace = Workspace::factory()->create();
    $user = formUser($workspace);

    $form = app(WorkspaceContext::class)->runFor($workspace, fn () => Form::create([
        'name' => 'Hírlevél',
        'consent_text' => 'Hozzájárulok.',
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/forms')
        ->post('/forms/'.$form->uuid.'/toggle')
        ->assertRedirect('/forms');

    expect($form->fresh()->active)->toBeFalse();

    // A PUBLIKUS VÉGPONT UGYANAZT válaszolja — nem ad orákulumot arról, hogy az űrlap
    // létezik-e vagy ki van kapcsolva.
    $this->post('/f/'.$form->slug, ['email' => 'valaki@pelda.hu'])->assertOk();
});

it('a lista NEVE javítható — a címzett is ezt látja a preference centerben', function () {
    /*
     | A preference center a kontakt SAJÁT listáit sorolja fel, névvel és leírással.
     | Szerkesztés nélkül egy elgépelt név örökre ott maradt volna a leiratkozó
     | felületen — javítani csak adatbázisból lehetett.
     */
    $workspace = Workspace::factory()->create();
    $user = formUser($workspace);

    $list = app(WorkspaceContext::class)->runFor($workspace, fn () => ContactList::create(['name' => 'Hrílevél']));

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/audience')
        ->post('/audience/lists/'.$list->uuid, [
            'name' => 'Hírlevél',
            'description' => 'Havi összefoglaló',
        ])
        ->assertRedirect('/audience');

    expect((string) $list->fresh()->name)->toBe('Hírlevél')
        ->and((string) $list->fresh()->description)->toBe('Havi összefoglaló');
});

it('IDEGEN workspace listája nem szerkeszthető', function () {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    $user = formUser($mine);
    $foreign = app(WorkspaceContext::class)->runFor($theirs, fn () => ContactList::create(['name' => 'Idegen']));

    $this->actingAs($user)->withSession(['workspace_uuid' => $mine->uuid])
        ->post('/audience/lists/'.$foreign->uuid, ['name' => 'Átírva'])
        ->assertNotFound();

    expect((string) $foreign->fresh()->name)->toBe('Idegen');
});

it('az űrlap-admin az audience.manage képességhez kötött', function () {
    $workspace = Workspace::factory()->create();
    $analyst = formUser($workspace, 'analyst');

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/forms')
        ->assertForbidden();
});
