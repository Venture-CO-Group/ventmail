<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Services\FormRenderTokens;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — ŰRLAP-ÉPÍTŐ, HOSZTOLT ŰRLAP és PREFERENCE CENTER (profil-mezőkkel).
 |
 | A hosztolt oldalak névtelenek: szigorú CSP, süti nélkül, rate-limittel. A beküldés
 | legfeljebb egy `pending` consentet ad (double opt-in, MINDIG), a preference center pedig
 | csak a kontakt SAJÁT adatait mutatja és írja.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create(['name' => 'Nádor Kávéház']);
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->user = StudioFixtures::user($this->workspace);

    ContactField::create(['key' => 'city', 'label' => 'Város', 'type' => 'text']);
    ContactField::create(['key' => 'age', 'label' => 'Életkor', 'type' => 'number']);

    $this->list = ContactList::create(['name' => 'Hírlevél', 'from_email' => 'hirlevel@pelda.hu']);
});

function builderPost($test, string $url, array $data)
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->workspace->uuid])->post($url, $data);
}

function builderForm(array $fields = [], array $page = []): Form
{
    return Form::create([
        'name' => 'Feliratkozás',
        'contact_list_id' => test()->list->getKey(),
        'consent_text' => 'Hozzájárulok a hírlevélhez.',
        'min_seconds' => 3,
        'fields' => $fields,
        'page' => $page,
    ]);
}

it('az építő a mezőket és az oldal-szövegeket NORMALIZÁLVA menti', function () {
    builderPost($this, '/forms', [
        'name' => 'Építő',
        'listUuid' => (string) $this->list->uuid,
        'minSeconds' => 4,
        'consentText' => 'Hozzájárulok.',
        'fields' => [
            ['key' => 'first_name', 'label' => 'Keresztneved', 'required' => true],
            ['key' => 'attr:age', 'label' => '', 'required' => false],
        ],
        'page' => ['title' => 'Iratkozz fel!', 'successMessage' => 'Nézd meg a postafiókod.', 'evil' => 'x'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $form = Form::query()->where('name', 'Építő')->firstOrFail();

    expect($form->fields)->toEqual([
        ['name' => 'first_name', 'label' => 'Keresztneved', 'type' => 'text', 'required' => true],
        ['name' => 'age', 'label' => 'Életkor', 'type' => 'number', 'required' => false],
    ])->and($form->page)->toMatchArray(['title' => 'Iratkozz fel!', 'successMessage' => 'Nézd meg a postafiókod.'])
        ->and($form->page)->not->toHaveKey('evil');
});

it('ismeretlen vagy IDEGEN workspace egyéni mezője nem tehető az űrlapra', function () {
    $this->context->runFor(Workspace::factory()->create(), fn () => ContactField::create(['key' => 'secret', 'label' => 'Titok', 'type' => 'text']));

    foreach (['attr:secret', 'attr:nincs', 'password', 'email'] as $key) {
        builderPost($this, '/forms', [
            'name' => 'Rossz '.$key,
            'minSeconds' => 4,
            'consentText' => 'x',
            'fields' => [['key' => $key, 'required' => false]],
        ])->assertSessionHasErrors('fields.0.key');
    }
});

it('a mezők nélküli régi mentés NEM törli az építőben összerakott mezőket', function () {
    $form = builderForm([['name' => 'city', 'label' => 'Város', 'type' => 'text', 'required' => false]]);

    builderPost($this, '/forms/'.$form->uuid, ['name' => 'Átnevezve', 'minSeconds' => 3, 'consentText' => 'x'])
        ->assertSessionHasNoErrors();

    expect($form->fresh()->fields)->toHaveCount(1)->and($form->fresh()->name)->toBe('Átnevezve');
});

it('a HOSZTOLT oldal a mezőket, a csapdát és a renderelési tokent adja — szigorú CSP-vel, süti nélkül', function () {
    $form = builderForm(
        [['name' => 'first_name', 'label' => 'Keresztnév', 'type' => 'text', 'required' => true], ['name' => 'age', 'label' => 'Életkor', 'type' => 'number', 'required' => false]],
        ['title' => 'Kávés hírek', 'button' => 'Kérem'],
    );

    $response = $this->get('/f/'.$form->slug)->assertOk();

    $response->assertSee('Kávés hírek')
        ->assertSee('Kérem')
        ->assertSee('name="first_name"', false)
        ->assertSee('type="number"', false)
        ->assertSee('name="'.$form->honeypot_field.'"', false)
        ->assertSee('name="_rt"', false)
        ->assertSee('Hozzájárulok a hírlevélhez.')
        ->assertDontSee('<script', false);

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'none'")
        ->toContain("default-src 'none'")
        ->toContain("form-action 'self'")
        ->toContain('frame-ancestors *')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('az INAKTÍV és az ismeretlen űrlap oldala 404', function () {
    $form = builderForm();
    $form->forceFill(['active' => false])->save();

    $this->get('/f/'.$form->slug)->assertNotFound();
    $this->get('/f/nincs-ilyen-urlap')->assertNotFound();
});

it('a hosztolt BEKÜLDÉS függő (double opt-in) feliratkozást ad, megerősítő levéllel — a mezők a helyükre kerülnek', function () {
    $form = builderForm([
        ['name' => 'first_name', 'label' => 'Keresztnév', 'type' => 'text', 'required' => true],
        ['name' => 'age', 'label' => 'Életkor', 'type' => 'number', 'required' => false],
    ], ['successTitle' => 'Már majdnem kész!']);

    $token = app(FormRenderTokens::class)->issue((string) $form->uuid);
    $this->travel(10)->seconds();

    $response = $this->post('/f/'.$form->slug, [
        'email' => 'Anna@Pelda.hu',
        'first_name' => 'Anna',
        'age' => '34',
        'consent' => '1',
        '_rt' => $token,
    ])->assertOk()->assertSee('Már majdnem kész!');

    expect($response->headers->getCookies())->toBe([]);

    $contact = Contact::query()->where('email', 'anna@pelda.hu')->firstOrFail();

    expect($contact->status)->toBe('pending')
        ->and($contact->first_name)->toBe('Anna')
        ->and($contact->attributes)->toBe(['age' => 34])
        ->and($contact->source)->toBe('form')
        ->and(Consent::query()->where('email', 'anna@pelda.hu')->value('status'))->toBe('pending')
        ->and(Message::query()->where('channel', 'transactional')->count())->toBe(1);
});

it('a TÚL GYORS (friss token) és a HAMIS tokenes beküldés csendben eldobva — ugyanaz a válasz', function () {
    $form = builderForm();

    $fresh = app(FormRenderTokens::class)->issue((string) $form->uuid);

    $this->post('/f/'.$form->slug, ['email' => 'bot1@pelda.hu', '_rt' => $fresh])->assertOk();
    $this->post('/f/'.$form->slug, ['email' => 'bot2@pelda.hu', '_rt' => 'hamisitott-token'])->assertOk();

    $other = builderForm();
    $foreignToken = app(FormRenderTokens::class)->issue((string) $other->uuid);
    $this->travel(30)->seconds();
    $this->post('/f/'.$form->slug, ['email' => 'bot3@pelda.hu', '_rt' => $foreignToken])->assertOk();

    expect(Contact::query()->whereIn('email', ['bot1@pelda.hu', 'bot2@pelda.hu', 'bot3@pelda.hu'])->count())->toBe(0);
});

it('a KÖTELEZŐ mező hiányában nincs feliratkozás', function () {
    $form = builderForm([['name' => 'first_name', 'label' => 'Keresztnév', 'type' => 'text', 'required' => true]]);

    $this->post('/f/'.$form->slug, ['email' => 'nevtelen@pelda.hu', '_elapsed' => 10])->assertOk();

    expect(Contact::query()->where('email', 'nevtelen@pelda.hu')->exists())->toBeFalse();
});

it('a hosztolt oldalak rate-limitáltak', function () {
    foreach (['forms.page' => 'throttle:hosted-form', 'forms.submit' => 'throttle:public-form', 'preferences.show' => 'throttle:preferences', 'preferences.update' => 'throttle:preferences', 'consent.confirm' => 'throttle:consent-confirm'] as $name => $limiter) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain($limiter);
    }
});

it('a PREFERENCE CENTER a kontakt saját profil-mezőit mutatja és menti — szigorú CSP, süti nélkül', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu'));

    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed', 'first_name' => 'Anna', 'attributes' => ['city' => 'Budapest', 'secret_note' => 'belső']]);
    $contact->lists()->attach($this->list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    $token = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'anna@pelda.hu');

    $page = $this->get('/p/'.$token)->assertOk()
        ->assertSee('value="Anna"', false)
        ->assertSee('value="Budapest"', false)
        ->assertSee('Életkor')
        // DEFINÍCIÓ NÉLKÜLI belső attribútum nem jelenik meg.
        ->assertDontSee('belső');

    expect((string) $page->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($page->headers->getCookies())->toBe([]);

    $this->post('/p/'.$token, [
        'lists' => [(string) $this->list->uuid],
        'profile' => ['first_name' => 'Anna Mária', 'city' => '', 'age' => 'negyven', 'secret_note' => 'átírva', 'status' => 'subscribed'],
    ])->assertOk();

    $fresh = $contact->fresh();

    expect($fresh->first_name)->toBe('Anna Mária')
        ->and($fresh->attributes)->toBe(['secret_note' => 'belső'])
        ->and($fresh->status)->toBe('subscribed');
});

it('a lista nélküli kontakt profil-mentése NEM iratkoztatja le', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('lista-nelkul@pelda.hu'));
    Contact::create(['email' => 'lista-nelkul@pelda.hu', 'status' => 'subscribed']);

    $token = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'lista-nelkul@pelda.hu');

    $this->post('/p/'.$token, ['profile' => ['first_name' => 'Béla']])->assertOk();

    expect(Contact::query()->where('email', 'lista-nelkul@pelda.hu')->value('status'))->toBe('subscribed')
        ->and(Suppression::query()->where('email', 'lista-nelkul@pelda.hu')->exists())->toBeFalse();
});

it('a GLOBÁLIS leiratkozás a preference centerből változatlanul suppression + visszavont consent', function () {
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('le@pelda.hu'));
    $contact = Contact::create(['email' => 'le@pelda.hu', 'status' => 'subscribed']);
    $contact->lists()->attach($this->list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    $token = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'le@pelda.hu');

    $this->post('/p/'.$token, ['unsubscribe_all' => '1', 'profile' => ['first_name' => 'Nem írja']])->assertOk();

    expect(Suppression::query()->where('email', 'le@pelda.hu')->exists())->toBeTrue()
        ->and(Consent::query()->where('email', 'le@pelda.hu')->value('status'))->toBe('revoked')
        ->and($contact->fresh()->status)->toBe('unsubscribed')
        ->and($contact->fresh()->first_name)->toBeNull();
});

it('az építő képernyő a hosztolt linket, az iframe-kódot és a választható egyéni mezőket adja', function () {
    builderForm();

    $this->actingAs($this->user)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->get('/forms')
        ->assertInertia(fn ($page) => $page
            ->component('Audience/Forms')
            ->where('forms.0.hostedUrl', fn ($url) => str_contains((string) $url, '/f/'))
            ->where('forms.0.iframe', fn ($code) => str_contains((string) $code, '<iframe'))
            ->has('customFields', 2));
});
