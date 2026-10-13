<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\AudienceInsights;
use App\Modules\Audience\Services\FormRenderTokens;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\ConcurrentIndex;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudioFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 5. modul — a security és az általános review javításai (B1, S1–S3, RS3, RS5, NIT-ek).
 */

beforeEach(function () {
    FakeDriver::reset();
    Cache::flush();

    $this->workspace = Workspace::factory()->create();
    $this->context = app(WorkspaceContext::class);
    $this->context->set($this->workspace);
    $this->user = StudioFixtures::user($this->workspace);
    $this->list = ContactList::create(['name' => 'Hírlevél', 'from_email' => 'hirlevel@pelda.hu']);
});

function hardeningAs($test)
{
    return $test->actingAs($test->user)->withSession(['workspace_uuid' => $test->workspace->uuid]);
}

function hardeningForm(array $attributes = []): Form
{
    return Form::create(array_merge([
        'name' => 'Feliratkozás',
        'contact_list_id' => test()->list->getKey(),
        'consent_text' => 'Hozzájárulok.',
        'min_seconds' => 3,
    ], $attributes));
}

/* ---------------------------------------------------------------- B1 */

it('B1 · a JELÖLETLEN egyéni mező a preference centerben nem látszik és POST-tal sem írható', function () {
    ContactField::create(['key' => 'city', 'label' => 'Város', 'type' => 'text']);
    $visible = ContactField::create(['key' => 'nickname', 'label' => 'Becenév', 'type' => 'text']);
    $visible->forceFill(['preference_visible' => true])->save();

    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('anna@pelda.hu'));
    $contact = Contact::create(['email' => 'anna@pelda.hu', 'status' => 'subscribed', 'attributes' => ['city' => 'Budapest', 'nickname' => 'Annus']]);
    $contact->lists()->attach($this->list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    $token = app(PreferenceTokens::class)->issue((int) $this->workspace->getKey(), 'anna@pelda.hu');

    $this->get('/p/'.$token)->assertOk()
        ->assertSee('Becenév')
        ->assertSee('value="Annus"', false)
        ->assertDontSee('Város')
        ->assertDontSee('Budapest');

    $this->post('/p/'.$token, [
        'lists' => [(string) $this->list->uuid],
        'profile' => ['city' => 'Szeged', 'nickname' => 'Ancsa'],
    ])->assertOk();

    expect($contact->fresh()->attributes)->toBe(['city' => 'Budapest', 'nickname' => 'Ancsa']);
});

it('B1 · a mező láthatósága a mezőkezelőben kapcsolható — auditálva, tenant-szűrten, jogosultsághoz kötve', function () {
    $field = ContactField::create(['key' => 'city', 'label' => 'Város', 'type' => 'text']);

    hardeningAs($this)->post('/audience/fields/'.$field->uuid.'/preference', ['visible' => true])->assertRedirect();

    expect($field->fresh()->preference_visible)->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', ContactField::class)->where('action', 'updated')->exists())->toBeTrue();

    $foreign = $this->context->runFor(Workspace::factory()->create(), fn () => ContactField::create(['key' => 'x', 'label' => 'X', 'type' => 'text']));
    hardeningAs($this)->post('/audience/fields/'.$foreign->uuid.'/preference', ['visible' => true])->assertNotFound();

    $analyst = StudioFixtures::user($this->workspace, 'analyst');
    $this->actingAs($analyst)->withSession(['workspace_uuid' => $this->workspace->uuid])
        ->post('/audience/fields/'.$field->uuid.'/preference', ['visible' => false])->assertForbidden();

    hardeningAs($this)->get('/audience')->assertInertia(fn ($page) => $page
        ->where('fields.0.key', 'city')
        ->where('fields.0.preferenceVisible', true));
});

/* ---------------------------------------------------------------- S1 */

it('S1 · az építőben létrehozott űrlapon a mérés KÖTELEZŐ: token nélkül, `_elapsed=9999`-cel is bot', function () {
    hardeningAs($this)->post('/forms', ['name' => 'Új', 'minSeconds' => 3, 'consentText' => 'x'])->assertSessionHasNoErrors();

    $form = Form::query()->where('name', 'Új')->firstOrFail();

    expect($form->require_timing)->toBeTrue();

    $this->post('/f/'.$form->slug, ['email' => 'a@pelda.hu', 'consent' => '1'])->assertOk();
    $this->post('/f/'.$form->slug, ['email' => 'b@pelda.hu', 'consent' => '1', '_elapsed' => 9999])->assertOk();

    expect(Contact::query()->whereIn('email', ['a@pelda.hu', 'b@pelda.hu'])->count())->toBe(0);
});

it('S1 · a renderelési token EGYSZER használható', function () {
    $form = hardeningForm(['require_timing' => true]);

    $token = app(FormRenderTokens::class)->issue((string) $form->uuid);
    $this->travel(10)->seconds();

    $this->post('/f/'.$form->slug, ['email' => 'elso@pelda.hu', 'consent' => '1', '_rt' => $token])->assertOk();
    $this->post('/f/'.$form->slug, ['email' => 'masodik@pelda.hu', 'consent' => '1', '_rt' => $token])->assertOk();

    expect(Contact::query()->where('email', 'elso@pelda.hu')->exists())->toBeTrue()
        ->and(Contact::query()->where('email', 'masodik@pelda.hu')->exists())->toBeFalse();
});

it('S1 · a régi (mérés nélkül is elfogadó) űrlap beágyazott kódja továbbra is működik', function () {
    $form = hardeningForm();

    expect($form->require_timing)->toBeFalse();

    $this->post('/f/'.$form->slug, ['email' => 'regi@pelda.hu', 'consent' => '1', '_elapsed' => 10])->assertOk();

    expect(Contact::query()->where('email', 'regi@pelda.hu')->exists())->toBeTrue();
});

it('SN1 · a megerősítő levél plafonja (workspace, cím) 3/nap — a beiratkozás MINDEN beküldésnél létrejön, csak a levél marad ki', function () {
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    foreach (range(1, 5) as $i) {
        $this->post('/f/'.hardeningForm(['name' => "F{$i}"])->slug, ['email' => 'aldozat@pelda.hu', 'consent' => '1', '_elapsed' => 10])->assertOk();
    }

    expect(Message::query()->withoutGlobalScopes()->where('channel', 'transactional')->where('status', '!=', 'rejected')->count())->toBe(3)
        ->and(Message::query()->withoutGlobalScopes()->where('channel', 'transactional')->count())->toBe(3)
        ->and(Contact::query()->where('email', 'aldozat@pelda.hu')->exists())->toBeTrue()
        ->and(Consent::query()->where('email', 'aldozat@pelda.hu')->where('status', 'pending')->exists())->toBeTrue();
});

it('SN1 · a globális címenkénti plafon (10/nap) más tenantok beiratkozását NEM blokkolja, csak a levelet', function () {
    foreach (range(1, 12) as $i) {
        $workspace = Workspace::factory()->create();
        $form = $this->context->runFor($workspace, function () use ($i) {
            Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
            $list = ContactList::create(['name' => "L{$i}", 'from_email' => 'hirlevel@pelda.hu']);

            return Form::create(['name' => "F{$i}", 'contact_list_id' => $list->getKey(), 'consent_text' => 'x', 'min_seconds' => 1]);
        });

        $this->post('/f/'.$form->slug, ['email' => 'aldozat@pelda.hu', 'consent' => '1', '_elapsed' => 10])->assertOk();
    }

    expect(Message::query()->withoutGlobalScopes()->where('channel', 'transactional')->where('status', '!=', 'rejected')->count())->toBe(10)
        // A 11. és 12. tenantnál is LÉTREJÖTT a kontakt (pending) — az áldozat feliratkozása nem veszett el.
        ->and(Contact::query()->withoutGlobalScopes()->where('email', 'aldozat@pelda.hu')->count())->toBe(12);
});

it('SN1 · a plafon csak ELKÜLDÖTT levél után számol: feladó nélküli beküldés nem fogyasztja', function () {
    config(['mail.from.address' => null]);
    $list = ContactList::create(['name' => 'Feladó nélkül']);

    foreach (range(1, 4) as $i) {
        $this->post('/f/'.hardeningForm(['name' => "N{$i}", 'contact_list_id' => $list->getKey()])->slug, ['email' => 'x@pelda.hu', 'consent' => '1', '_elapsed' => 10])->assertOk();
    }

    expect(Message::query()->count())->toBe(0);

    $this->post('/f/'.hardeningForm(['name' => 'Rendes'])->slug, ['email' => 'x@pelda.hu', 'consent' => '1', '_elapsed' => 10])->assertOk();

    expect(Message::query()->count())->toBe(1);
});

/* ---------------------------------------------------------------- S3 */

it('S3 · a hozzájárulás jelölőnégyzete SZERVEROLDALON is kötelező', function () {
    $form = hardeningForm();

    $this->post('/f/'.$form->slug, ['email' => 'nincs@pelda.hu', '_elapsed' => 10])->assertOk();
    $this->post('/f/'.$form->slug, ['email' => 'nulla@pelda.hu', 'consent' => '0', '_elapsed' => 10])->assertOk();

    expect(Contact::query()->whereIn('email', ['nincs@pelda.hu', 'nulla@pelda.hu'])->count())->toBe(0);
});

/* ---------------------------------------------------------------- N3, N4 */

it('N3 · a névből képzett slug VÉLETLEN utótagot kap — két azonos nevű űrlap nem ütközik', function () {
    $a = hardeningForm(['name' => 'Hírlevél']);
    $b = hardeningForm(['name' => 'Hírlevél']);

    expect($a->slug)->toMatch('/^hirlevel-[a-z0-9]{10}$/')
        ->and($b->slug)->not->toBe($a->slug);
});

it('N4 · az átirányítás nem fogad el visszaperjelet és userinfót', function (string $url) {
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $form = hardeningForm(['redirect_url' => $url]);

    $response = $this->post('/f/'.$form->slug, ['email' => 'r@pelda.hu', 'consent' => '1', '_elapsed' => 10]);

    expect($response->status())->toBe(200);
})->with([
    'userinfo' => ['https://evil.example@pelda.hu/koszonjuk'],
    'visszaperjel' => ['https://pelda.hu\\@evil.example/'],
    'visszaperjel host' => ['https://evil.example\\.pelda.hu/'],
]);

it('N4 · az érvényes saját domainre továbbra is átirányít', function () {
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $form = hardeningForm(['redirect_url' => 'https://www.pelda.hu/koszonjuk']);

    $this->post('/f/'.$form->slug, ['email' => 'ok@pelda.hu', 'consent' => '1', '_elapsed' => 10])
        ->assertRedirect('https://www.pelda.hu/koszonjuk');
});

/* ---------------------------------------------------------------- S2 */

it('S2 · a lekérdezési keret a lassú lekérdezést „túl költséges" hibává fordítja', function () {
    expect(fn () => app(QueryBudget::class)->run(fn () => DB::selectOne('select pg_sleep(0.5)'), 50))
        ->toThrow(QueryTooExpensive::class);

    // A keret után a kapcsolat újra használható (a SET LOCAL a tranzakcióval együtt elmúlt).
    expect(app(QueryBudget::class)->run(fn () => 42))->toBe(42);
});

it('S2 · a szegmens-előnézet és a dashboard 422-t ad, ha a lekérdezés túl költséges', function () {
    app()->instance(QueryBudget::class, new class extends QueryBudget
    {
        public function run(callable $work, ?int $milliseconds = null): mixed
        {
            throw new QueryTooExpensive;
        }
    });

    hardeningAs($this)->postJson('/audience/segments/preview', ['definition' => ['match' => 'all', 'rules' => []]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('definition');

    hardeningAs($this)->getJson('/audience/insights')->assertStatus(422);

    hardeningAs($this)->post('/audience/segments', ['name' => 'Drága', 'definition' => ['match' => 'all', 'rules' => []]])
        ->assertSessionHasErrors('definition');
});

/* ---------------------------------------------------------------- RS3, NIT normalizálás */

it('RS3 · szabad attribútumra mentett szegmens nem törik el, ha utóbb típusos mező jön létre', function () {
    StudioFixtures::contact('a@pelda.hu', ['attributes' => ['age' => '34']]);
    StudioFixtures::contact('b@pelda.hu', ['attributes' => ['age' => '51']]);

    $definition = ['match' => 'all', 'rules' => [['field' => 'attr:age', 'op' => 'starts_with', 'value' => '3']]];

    ContactField::create(['key' => 'age', 'label' => 'Életkor', 'type' => 'number']);

    expect(app(SegmentEngine::class)->query($definition)->pluck('email')->all())->toBe(['a@pelda.hu']);
});

it('NIT · a mentett definíció operátora és címke-uuid-ja kisbetűs — a hivatkozás-őr így megtalálja', function () {
    $tag = ContactTag::create(['name' => 'vip']);

    hardeningAs($this)->post('/audience/segments', ['name' => 'Nagybetűs', 'definition' => ['match' => 'ALL', 'rules' => [
        ['field' => 'tag', 'op' => 'HAS_TAG', 'value' => strtoupper((string) $tag->uuid)],
    ]]])->assertSessionHasNoErrors();

    $definition = Segment::query()->where('name', 'Nagybetűs')->firstOrFail()->definition;

    expect($definition['match'])->toBe('all')
        ->and($definition['rules'][0]['op'])->toBe('has_tag')
        ->and($definition['rules'][0]['value'])->toBe((string) $tag->uuid);

    hardeningAs($this)->delete('/audience/tags/'.$tag->uuid)->assertSessionHas('error');
});

/* ---------------------------------------------------------------- RS5 */

it('RS5 · a dashboard-aggregátum workspace-enként gyorsítótárazott', function () {
    StudioFixtures::contact('a@pelda.hu', ['source' => 'form']);

    $first = app(AudienceInsights::class)->summary(30, 'UTC');

    StudioFixtures::contact('b@pelda.hu', ['source' => 'form']);

    $second = app(AudienceInsights::class)->summary(30, 'UTC');

    expect($second['totals']['total'])->toBe($first['totals']['total'])->toBe(1);

    // Másik workspace NEM kapja meg ugyanazt a gyorsítótár-bejegyzést.
    $this->context->runFor(Workspace::factory()->create(), fn () => expect(app(AudienceInsights::class)->summary(30, 'UTC')['totals']['total'])->toBe(0));
});

it('RS5 · a forrás-visszatöltés a NULL forrású kontaktokat kitölti, kötegekben', function () {
    StudioFixtures::contact('urlap@pelda.hu');
    StudioFixtures::consent('urlap@pelda.hu', 'pending', 'form');
    StudioFixtures::contact('kezi@pelda.hu');
    StudioFixtures::contact('import@pelda.hu', ['source' => 'import']);

    $this->artisan('audience:backfill-sources', ['--chunk' => 1])->assertSuccessful();

    expect(Contact::query()->pluck('source', 'email')->all())->toMatchArray([
        'urlap@pelda.hu' => 'form',
        'kezi@pelda.hu' => 'manual',
        'import@pelda.hu' => 'import',
    ]);
});

/* ---------------------------------------------------------------- 2. review-kör NIT-ek */

it('NIT · a lekérdezési keret BEÁGYAZOTT tranzakcióban sem szivárog: a statement_timeout visszaáll', function () {
    // A RefreshDatabase maga is tranzakció — a keret itt savepointban fut.
    $before = DB::selectOne("select current_setting('statement_timeout') as v")->v;

    DB::transaction(function () use ($before): void {
        expect(app(QueryBudget::class)->run(fn () => 42, 1234))->toBe(42)
            ->and(DB::selectOne("select current_setting('statement_timeout') as v")->v)->toBe($before);
    });
});

it('NIT · a CONCURRENTLY index INVALID maradványát a migrációs segéd eldobja és újraépíti', function () {
    DB::statement('create table if not exists nit_idx_probe (id bigint, v text)');
    DB::statement('create index nit_idx_probe_v on nit_idx_probe (v)');

    try {
        DB::statement("update pg_index set indisvalid = false where indexrelid = 'nit_idx_probe_v'::regclass");
    } catch (Throwable) {
        $this->markTestSkipped('A teszt-DB felhasználója nem írhatja a pg_index-et.');
    }

    ConcurrentIndex::create('nit_idx_probe_v', 'on nit_idx_probe (v)');

    expect((bool) DB::selectOne("select indisvalid from pg_index where indexrelid = 'nit_idx_probe_v'::regclass")->indisvalid)->toBeTrue();
});
