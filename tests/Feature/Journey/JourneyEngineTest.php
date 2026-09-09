<?php

declare(strict_types=1);

use App\Modules\Audience\Actions\SubmitForm;
use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\ContactGdpr;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Inbound\Services\RouteEngine;
use App\Modules\Journey\Actions\FireJourneyEvent;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyRunStep;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Journey\Services\JourneyEngine;
use App\Modules\Journey\Services\JourneyTrigger;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | B3 · 68–71. kritérium — journey motor.
 |
 | A packet TÉTJE: a motor LÉPTET, nem fut. Egy journey napokig–hetekig tart, tehát nem
 | tarthat egy folyamatban egy `sleep()`-pel — a worker újraindul, a deploy lecseréli a
 | kódot. Az állapot az adatbázisban van, és a FAKE ÓRA teszi tesztelhetővé: egy háromnapos
 | várakozást nem lehet háromnapos teszttel ellenőrizni.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->template = Template::create([
        'name' => 'Journey levél',
        'subject' => 'Szia!',
        'blocks' => [[
            'type' => 'section',
            'children' => [['type' => 'column', 'children' => [
                ['type' => 'text', 'attrs' => ['content' => 'Szia {{ nev || Vásárló }}!']],
                ['type' => 'unsubscribe', 'attrs' => []],
            ]]],
        ]],
    ]);
});

/** Megerősített consenttel bíró kontakt. */
function confirmedContact(string $email = 'anna@pelda.hu'): Contact
{
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request($email));

    return Contact::create(['email' => $email, 'status' => 'subscribed', 'subscribed_at' => now()]);
}

/**
 * Egy journey a megadott lépés-leírásokból — LÁNCOLVA, hátulról előre.
 *
 * @param  list<array<string, mixed>>  $steps
 */
function journeyWith(array $steps, array $attributes = []): Journey
{
    $journey = Journey::create(array_merge([
        'name' => 'Teszt journey '.bin2hex(random_bytes(3)),
        'trigger_type' => TriggerType::FormSignup->value,
    ], $attributes));

    $journey->forceFill(['status' => 'active'])->save();

    $created = [];

    // HÁTULRÓL előre: a `next_step_id` a következőre mutat, tehát azt előbb létre kell hozni.
    foreach (array_reverse($steps, true) as $index => $step) {
        $created[$index] = JourneyStep::create(array_merge([
            'journey_id' => $journey->getKey(),
            'position' => $index,
            'next_step_id' => isset($created[$index + 1]) ? $created[$index + 1]->getKey() : null,
        ], $step));
    }

    $journey->forceFill(['first_step_id' => $created[0]->getKey()])->save();

    return $journey->fresh();
}

/*
 | 68. DETERMINISZTIKUS LÉPTETÉS FAKE ÓRÁN + IDEMPOTENCIA
 */

it('a trigger → wait → send lánc a FAKE ÓRÁN lépteti a futást', function () {
    // Egy háromnapos várakozást nem lehet háromnapos teszttel ellenőrizni: a motor `now()`-t
    // használ, és semmit nem tud arról, hogy „valódi" idő-e.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 3 * 24 * 60]],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    $runs = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu');

    expect($runs)->toHaveCount(1);

    $run = $runs[0];
    $engine = app(JourneyEngine::class);

    // ELSŐ tick: a `wait` felfüggeszti a futást.
    $engine->tick($run);

    expect($run->fresh()->status)->toBe('waiting');
    expect($run->fresh()->resume_at)->not->toBeNull();
    expect(Message::query()->count())->toBe(0);

    // A VÁRAKOZÁS közben SEMMI nem történik — akárhányszor tickel az ütemező.
    $engine->tick($run->fresh());
    $engine->tick($run->fresh());

    expect(Message::query()->count())->toBe(0);

    // HÁROM NAP MÚLVA a levél kimegy.
    $this->travel(3)->days();
    $this->travel(1)->minute();

    $engine->tick($run->fresh());

    expect(Message::query()->count())->toBe(1);
    expect(Message::query()->firstOrFail()->channel)->toBe('journey');
    // A lánc végére érve a futás BEFEJEZŐDIK.
    expect($run->fresh()->status)->toBe('completed');
});

it('KÉTSZERI TICK = EGY HATÁS — az idempotencia adatbázis-szintű', function () {
    // Két párhuzamos tick között az „megnéztem, még nem futott" és a „lefuttattam" közé
    // befér a másik. Ezért a lépés végrehajtásának ELŐFELTÉTELE egy beszúrás a
    // `journey_run_steps` egyedi kulcsára: aki beírta, az futtat.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    $engine = app(JourneyEngine::class);

    $engine->tick($run);
    $engine->tick($run->fresh());
    $engine->tick($run->fresh());

    // EGY levél, EGY végrehajtott lépés — akárhányszor tickeltünk.
    expect(Message::query()->count())->toBe(1);
    expect(JourneyRunStep::query()->count())->toBe(1);
});

it('a VÉGREHAJTOTT LÉPÉS duplikálása adatbázis-szinten hasal el', function () {
    // Nem az alkalmazás jóindulatán múlik: az egyedi index dönt.
    confirmedContact();

    $journey = journeyWith([['type' => 'exit', 'config' => []]]);
    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];
    $step = $journey->steps()->firstOrFail();

    JourneyRunStep::create([
        'journey_run_id' => $run->getKey(),
        'journey_step_id' => $step->getKey(),
        'iteration' => 1,
        'executed_at' => now(),
    ]);

    expect(fn () => JourneyRunStep::create([
        'journey_run_id' => $run->getKey(),
        'journey_step_id' => $step->getKey(),
        'iteration' => 1,
        'executed_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('a CONDITION két ága determinisztikus, és a HAMIS ág hiánya BEFEJEZÉS', function () {
    // A „hamis ág nincs megadva, tehát átesik az igazra" viselkedés azt jelentené, hogy egy
    // félkész elágazásból TÖBB levél megy ki, mint amit a felhasználó beállított.
    $contact = confirmedContact();
    $tag = ContactTag::create(['name' => 'vip']);

    $journey = journeyWith([
        ['type' => 'condition', 'config' => ['type' => 'has_tag', 'tag' => (string) $tag->uuid]],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    // A kontaktnak NINCS címkéje → hamis ág → nincs `else_step` → BEFEJEZÉS levél nélkül.
    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    expect(Message::query()->count())->toBe(0);
    expect($run->fresh()->status)->toBe('completed');
    expect($run->fresh()->exit_reason)->toBe('else_branch_end');
    expect(JourneyRunStep::query()->firstOrFail()->outcome)->toBe('false');

    // CÍMKÉVEL viszont az igaz ág fut.
    $contact->tags()->attach($tag);

    $second = app(JourneyTrigger::class)->enter($journey->fresh(), 'bela@pelda.hu');
    $belaContact = confirmedContact('bela@pelda.hu');
    $belaContact->tags()->attach($tag);
    $second->forceFill(['contact_id' => $belaContact->getKey()])->save();

    app(JourneyEngine::class)->tick($second->fresh());

    expect(Message::query()->count())->toBe(1);
});

it('az ELGÉPELT FELTÉTEL a MENTÉSNÉL elakad — és a motorban is fail-closed', function () {
    /*
     | KÉT VÉDELMI VONAL, és mindkettőt itt mérjük.
     |
     | 1. A MENTÉS elutasítja: a marketinges megnevezett hibát kap a szerkesztőben, nem egy
     |    csendben megálló journey-t három nappal később. Ez az, ami a review-ban mért
     |    „poison futás" osztályt megelőzi: a típusos `uuid` oszlopra írt szemét futáskor
     |    `SQLSTATE[22P02]`-t ad, ami a tranzakción belül visszagördíti a claimet, és a
     |    futás ÖRÖKKÉ esedékes marad.
     |
     | 2. A MOTOR akkor is fail-closed, ha a konfiguráció MÁSHOGY került be (nyers
     |    `DB::table()` írás, vagy egy régi sor a validáció bevezetése előttről): az
     |    ismeretlen feltétel HAMIS, tehát az `else` ágra visz, nem levélküldésre. A
     |    fordított alapérték azt jelentené, hogy egy hibás konfiguráció TÖBB levelet küld.
     */
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'condition', 'config' => ['type' => 'has_tag', 'tag' => (string) Str::uuid7()]],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    // 1. VONAL: a mentés elutasítja az elgépelt típust.
    expect(fn () => JourneyStep::create([
        'journey_id' => $journey->getKey(),
        'type' => 'condition',
        'config' => ['type' => 'elgepelt_feltetel'],
    ]))->toThrow(InvalidArgumentException::class, 'Ismeretlen feltétel-típus');

    // 2. VONAL: a validátort MEGKERÜLVE beírt konfiguráció a motorban fail-closed.
    DB::table('journey_steps')
        ->where('id', $journey->first_step_id)
        ->update(['config' => json_encode(['type' => 'elgepelt_feltetel'])]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    expect(Message::query()->count())->toBe(0)
        ->and(JourneyRunStep::query()->firstOrFail()->outcome)->toBe('false');
});

it('a KÖRKÖRÖS lánc a lépés-korlátnál MEGÁLL, és HIBÁT jelez', function () {
    // Egy condition, ami magára mutat, örökké futna, és a worker-sort tömné meg. A korlát
    // elérése HIBA, nem csendes megállás — a tenantnak tudnia kell, hogy a journey-je hibás.
    confirmedContact();

    $journey = Journey::create(['name' => 'Körkörös', 'trigger_type' => TriggerType::FormSignup->value]);
    $journey->forceFill(['status' => 'active'])->save();

    $step = JourneyStep::create([
        'journey_id' => $journey->getKey(),
        'type' => 'condition',
        'config' => ['type' => 'context_equals', 'key' => 'x', 'value' => 'x'],
    ]);

    /*
     | ÖNMAGÁRA mutat — a validátort MEGKERÜLVE.
     |
     | A `JourneyValidator` a MENTÉSNÉL elutasítja a várakozás nélküli kört (ezt külön
     | teszt méri), és ez a helyes első védelmi vonal: mérve egy ilyen kör 26 percig futott
     | és 500 `journey_run_steps` sort írt EGY futásra.
     |
     | Amit ITT bizonyítunk, az a MÁSODIK vonal: ha a kör mégis bekerült (régi adat, nyers
     | írás), a motor a lépés-korlátnál MEGÁLL és HIBÁT jelez — nem csendben áll meg, és
     | nem fut örökké.
     */
    DB::table('journey_steps')->where('id', $step->getKey())->update(['next_step_id' => $step->getKey()]);
    $journey->forceFill(['first_step_id' => $step->getKey()])->save();

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu', ['x' => 'x'])[0];

    // Sok tick — a motor tickenként legfeljebb 20 lépést tesz, a futás összesen 500-at.
    for ($i = 0; $i < 40; $i++) {
        app(JourneyEngine::class)->tick($run->fresh());
    }

    expect($run->fresh()->status)->toBe('failed');
    expect($run->fresh()->exit_reason)->toBe('step_limit');
});

it('a JOURNEY-FUTÁS PERZISZTENS: a motor a tárolt állapotból folytat', function () {
    // A motor nem tart semmit memóriában: minden tick megkérdezi, hol áll a futás. Ez az,
    // ami lehetővé teszi, hogy a worker újrainduljon egy hetes várakozás közepén.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'add_tag', 'config' => ['tag' => (string) ContactTag::create(['name' => 'belepett'])->uuid]],
        ['type' => 'wait', 'config' => ['minutes' => 60]],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    // A tárolt állapot: a címke megvan, a futás VÁR, és a következő lépésre mutat.
    expect(Contact::query()->firstOrFail()->tags()->count())->toBe(1);
    expect($run->fresh()->status)->toBe('waiting');

    $stored = $run->fresh();

    expect($stored->current_step_id)->toBe($journey->steps()->where('position', 2)->firstOrFail()->getKey());

    // EGY ÚJ motor-példány (mint egy újraindult worker) ugyanonnan folytatja.
    $this->travel(2)->hours();

    app(JourneyEngine::class)->tick(JourneyRun::query()->findOrFail($stored->getKey()));

    expect(Message::query()->count())->toBe(1);
});

/*
 | 69. TRIGGER-FORRÁSOK
 */

it('mind az ÖT trigger-forrás létezik, és a szűrő illeszkedik', function () {
    confirmedContact();

    foreach (TriggerType::cases() as $type) {
        $journey = journeyWith(
            [['type' => 'exit', 'config' => []]],
            ['trigger_type' => $type->value, 'name' => 'J-'.$type->value],
        );

        // A HAMISÍTHATÓ trigger megerősített consentet kér — a kontaktunknak van.
        $runs = app(JourneyTrigger::class)->fire($type, 'anna@pelda.hu');

        expect($runs)->toHaveCount(1, $type->value);

        $journey->forceFill(['status' => 'archived'])->save();
    }
});

it('a TRIGGER-SZŰRŐ illeszkedik — és a hiányzó szűrő MINDENT elfogad', function () {
    confirmedContact();

    $celzott = journeyWith(
        [['type' => 'exit', 'config' => []]],
        ['name' => 'Célzott', 'trigger_config' => ['form' => 'urlap-A']],
    );

    // MÁS űrlap: nem indul.
    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu', ['form' => 'urlap-B']))
        ->toHaveCount(0);

    // A CÉLZOTT űrlap: indul.
    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu', ['form' => 'urlap-A']))
        ->toHaveCount(1);
});

it('az ŰRLAP-BEKÜLDÉS tényleg tüzel — a B1 útján', function () {
    // A trigger a `SubmitForm` action része, a tranzakció UTÁN: a beléptetés levelet
    // küldhet, és egy még nem commitolt kontaktra hivatkozó levél a worker oldalán „nem
    // találom" hibába futna.
    $form = Form::create(['name' => 'Feliratkozás']);

    journeyWith(
        [['type' => 'exit', 'config' => []]],
        ['name' => 'Űrlapos', 'trigger_config' => ['form' => (string) $form->uuid]],
    );

    app(SubmitForm::class)($form, new FormSubmission(
        email: 'uj@pelda.hu',
        elapsedSeconds: 12,
    ));

    expect(JourneyRun::query()->where('email', 'uj@pelda.hu')->count())->toBe(1);
});

it('az INBOUND LEVÉL is tüzel — a route `journey()` akcióján', function () {
    // Egy „info@" címre írt kérdés elindíthat egy támogatási sorozatot (A4 → B3).
    confirmedContact('kuldo@pelda.hu');

    $journey = journeyWith(
        [['type' => 'exit', 'config' => []]],
        ['name' => 'Inbound', 'trigger_type' => TriggerType::InboundEmail->value],
    );

    expect(InboundRoute::ACTION_TYPES)->toContain('journey');

    $runs = app(JourneyTrigger::class)->fire(TriggerType::InboundEmail, 'kuldo@pelda.hu', [
        'route' => 'valamelyik-route',
    ]);

    expect($runs)->toHaveCount(1);
    expect($runs[0]->context)->toHaveKey('route');
});

/*
 | 70. KILÉPÉS: cél / leiratkozás / manuális + ENTITLEMENT
 */

it('a LEIRATKOZÁS azonnal KILÉPTET — a következő lépés nem fut le', function () {
    // Nem csak a levélküldést állítjuk le: a címkézés és a szegmens-mozgatás is
    // profilalkotás, amit egy leiratkozott embernél nem folytatunk.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 60]],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    // A VÁRAKOZÁS közben leiratkozik.
    Suppression::create(['email' => 'anna@pelda.hu', 'reason' => 'unsubscribe', 'applies_to' => 'marketing']);

    $this->travel(2)->hours();

    app(JourneyEngine::class)->tick($run->fresh());

    expect(Message::query()->count())->toBe(0);
    expect($run->fresh()->status)->toBe('exited');
    expect($run->fresh()->exit_reason)->toBe('unsubscribed');
});

it('a CÉL elérése BEFEJEZ — ez a siker', function () {
    $contact = confirmedContact();
    $tag = ContactTag::create(['name' => 'vasarolt']);

    $journey = journeyWith(
        [
            ['type' => 'wait', 'config' => ['minutes' => 60]],
            ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
        ],
        ['name' => 'Céllal', 'goal_type' => 'has_tag', 'goal_config' => ['tag' => (string) $tag->uuid]],
    );

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];
    $run->forceFill(['contact_id' => $contact->getKey()])->save();

    app(JourneyEngine::class)->tick($run->fresh());

    // A VÁRAKOZÁS közben eléri a célt: az emlékeztető már nem kell.
    $contact->tags()->attach($tag);

    $this->travel(2)->hours();

    app(JourneyEngine::class)->tick($run->fresh());

    expect(Message::query()->count())->toBe(0);
    expect($run->fresh()->status)->toBe('completed');
    expect($run->fresh()->exit_reason)->toBe('goal_reached');
});

it('az ENTITLEMENT hiánya megakadályozza a belépést ÉS kilépteti a futót', function () {
    // Futás KÖZBEN is ellenőrizzük, nem csak belépéskor: egy lefokozott csomag nem
    // futtathat tovább automatizálást hetekig.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 60]],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    // A CSOMAGBÓL kikerül a `journeys` funkció.
    config(['sending.fake_entitlement.features' => ['custom_domain']]);

    $this->travel(2)->hours();

    app(JourneyEngine::class)->tick($run->fresh());

    expect($run->fresh()->status)->toBe('exited');
    expect($run->fresh()->exit_reason)->toBe('entitlement_lost');

    // …és ÚJ futás sem indulhat.
    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'masik@pelda.hu'))->toHaveCount(0);
});

it('az ARCHIVÁLT journey kilépteti a futóit', function () {
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 60]],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    $journey->forceFill(['status' => 'archived'])->save();

    $this->travel(2)->hours();

    app(JourneyEngine::class)->tick($run->fresh());

    expect($run->fresh()->exit_reason)->toBe('journey_archived');
    expect(Message::query()->count())->toBe(0);
});

it('EGY KONTAKT egy journey-ben EGYSZER fut — és az újrabelépés alapból TILOS', function () {
    // Egy „kosárelhagyás" journey-be a címzett naponta újra beléphetne, és minden belépés
    // új levélsorozatot indítana. A részleges egyedi index az adatbázisban dönt, nem egy
    // alkalmazás-szintű „megnéztem, nincs" ellenőrzés, ami két kérés között befér.
    confirmedContact();

    $journey = journeyWith([['type' => 'wait', 'config' => ['minutes' => 60]]]);

    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu'))->toHaveCount(1);
    // MÁSODIK trigger: nincs új futás.
    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu'))->toHaveCount(0);
    expect(JourneyRun::query()->count())->toBe(1);

    // A BEFEJEZETT futás után sem, ha az újrabelépés nincs engedélyezve.
    JourneyRun::query()->firstOrFail()->forceFill([
        'status' => 'completed',
        'finished_at' => now(),
    ])->save();

    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu'))->toHaveCount(0);

    // ENGEDÉLYEZETT újrabelépéssel, a COOLDOWN letelte után igen.
    $journey->forceFill(['allow_reentry' => true, 'reentry_cooldown_hours' => 24])->save();

    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu'))->toHaveCount(0);

    $this->travel(25)->hours();

    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu'))->toHaveCount(1);
});

it('a SUPPRESSION megakadályozza a belépést', function () {
    confirmedContact();

    Suppression::create(['email' => 'anna@pelda.hu', 'reason' => 'complaint', 'applies_to' => 'all']);

    journeyWith([['type' => 'exit', 'config' => []]]);

    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu'))->toHaveCount(0);
});

/*
 | 71. AZ INBOUND TRIGGER CSAK MEGERŐSÍTETT KONTAKTNÁL
 */

it('az INBOUND trigger MEGERŐSÍTETT consent nélkül NEM indít journey-t', function () {
    /*
     | Ez a kritérium lelke, és a legkonkrétabb visszaélés, amit meg kell akadályozni.
     |
     | Bárki írhat levelet a világon `anna@pelda.hu` feladóval: az SMTP `MAIL FROM` és a
     | `From:` fejléc is a KÜLDŐ szava. Ha egy ilyen levél marketing-ágat indíthatna, egy
     | idegen bármikor levelezést kezdhetne Anna nevében — Anna hozzájárulása nélkül, a MI
     | domainünkről és a MI reputációnkkal. Spam-trap címre küldve ez klasszikus
     | lista-mérgezés, és a kárt a közös IP-n minden ügyfél fizeti.
     */
    $journey = journeyWith(
        [['type' => 'exit', 'config' => []]],
        ['name' => 'Inbound', 'trigger_type' => TriggerType::InboundEmail->value],
    );

    // 1. NINCS semmilyen consent → nem indul.
    expect(app(JourneyTrigger::class)->fire(TriggerType::InboundEmail, 'idegen@pelda.hu'))->toHaveCount(0);

    // 2. `pending` consent SEM elég: az azt jelenti, hogy valaki (esetleg épp a támadó)
    //    kérte a feliratkozást, de a címzett még nem hagyta jóvá.
    app(ConsentRecorder::class)->request('varakozo@pelda.hu');

    expect(app(JourneyTrigger::class)->fire(TriggerType::InboundEmail, 'varakozo@pelda.hu'))->toHaveCount(0);

    // 3. MEGERŐSÍTETT consenttel viszont indul.
    confirmedContact('igazi@pelda.hu');

    expect(app(JourneyTrigger::class)->fire(TriggerType::InboundEmail, 'igazi@pelda.hu'))->toHaveCount(1);
});

it('a NEM HAMISÍTHATÓ triggerek megerősítés nélkül is indíthatnak — de a levél elakad a kapun', function () {
    /*
     | A különbség a FORRÁS bizonyítottsága. Az űrlap-beküldés a MI rendszerünkből jön,
     | tehát a beléptetés legitim: a kontakt `pending` állapotban belép a journey-be.
     |
     | A MARKETING LEVÉL viszont NEM megy ki: a PreSendGate 3. kapuja (consent) elutasítja.
     | Így a journey „vár" a megerősítésre anélkül, hogy külön logikát kellene írni rá — és
     | a védelem ott van, ahol minden más küldésnél is.
     */
    $journey = journeyWith([
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    // `pending` consent, megerősítés NÉLKÜL.
    app(ConsentRecorder::class)->request('varakozo@pelda.hu');
    Contact::create(['email' => 'varakozo@pelda.hu', 'status' => 'pending']);

    $runs = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'varakozo@pelda.hu');

    expect($runs)->toHaveCount(1);

    app(JourneyEngine::class)->tick($runs[0]);

    // A LEVÉL a kapun elakadt — a `journey` csatorna is consent-köteles.
    expect(FakeDriver::$sent)->toHaveCount(0);
    expect(JourneyRunStep::query()->firstOrFail()->outcome)->toBe('skipped');
});

it('a TICK-PARANCS lépteti az esedékes futásokat, és egy hibás futás nem állítja meg a többit', function () {
    confirmedContact('egy@pelda.hu');
    confirmedContact('ketto@pelda.hu');

    $journey = journeyWith([
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'egy@pelda.hu');
    app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'ketto@pelda.hu');

    /*
     | …és egy HIBÁS futás: a sablon uuid ÉRVÉNYES formátumú, de nem létező sorra mutat.
     |
     | Szándékosan nem `'nem-letezik'`: azt a `JourneyValidator` már a MENTÉSNÉL elutasítja
     | (és ezt külön teszt méri). Amit itt bizonyítani kell, az a motor viselkedése egy
     | formailag helyes, de feloldhatatlan hivatkozásra — az a `template_missing`, nem egy
     | nyers adatbázis-kivétel.
     */
    $broken = journeyWith(
        [['type' => 'send_email', 'config' => ['template' => (string) Str::uuid7(), 'from_email' => 'no-reply@pelda.hu']]],
        ['name' => 'Hibás'],
    );

    confirmedContact('harmadik@pelda.hu');
    app(JourneyTrigger::class)->enter($broken, 'harmadik@pelda.hu');

    $this->artisan('journeys:tick')->assertSuccessful();

    // A KÉT jó futás levele kiment, a hibás `failed` — de nem állította meg a többit.
    expect(Message::query()->count())->toBe(2);
    expect(JourneyRun::query()->where('exit_reason', 'template_missing')->count())->toBe(1);
});

/*
 | A REVIEW-BAN MÉRT HIBÁK regressziós tesztjei (reviewer + security).
 */

it('a CIKLIKUS journey MINDEN körben ÚJ levelet küld — nem replayel', function () {
    /*
     | A MÉRT HIBA: az idempotencia-kulcs `journey:<futás>:<lépés>` volt, ISMÉTLÉS nélkül,
     | a `SendMessage` pedig az `messages.idempotency_key`-re nézve ÖRÖKRE replayel. Mérve
     | egy `send_email → wait → vissza` cikluson: a `journey_run_steps` ÖT sort írt
     | (`iteration=1..5`), mind `outcome='sent'`, mind UGYANAZZAL a `message_uuid`-dal,
     | miközben `messages = 1` és a FakeDriver 1 küldést látott.
     |
     | Vagyis egy heti emlékeztető EGYETLEN levelet küldött, és a lépés-napló ötször azt
     | hazudta, hogy elküldte. Ez rosszabb, mint a nem-küldés, mert ELREJTI.
     */
    confirmedContact();

    $journey = Journey::create(['name' => 'Ismétlődő', 'trigger_type' => TriggerType::FormSignup->value]);
    $journey->forceFill(['status' => 'active'])->save();

    // A LÁNC: send → wait 1 perc → vissza a send-re. A `wait` miatt ez LEGITIM ciklus (a
    // validátor csak a várakozás nélküli kört tiltja).
    $send = JourneyStep::create([
        'journey_id' => $journey->getKey(),
        'type' => 'send_email',
        'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu'],
    ]);

    $wait = JourneyStep::create([
        'journey_id' => $journey->getKey(),
        'type' => 'wait',
        'config' => ['minutes' => 1],
        'next_step_id' => $send->getKey(),
    ]);

    $send->forceFill(['next_step_id' => $wait->getKey()])->save();
    $journey->forceFill(['first_step_id' => $send->getKey()])->save();

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    // HÁROM KÖR a fake órán.
    for ($kor = 0; $kor < 3; $kor++) {
        app(JourneyEngine::class)->tick($run->fresh());
        $this->travel(2)->minutes();
    }

    $steps = JourneyRunStep::query()->where('journey_step_id', $send->getKey())->get();

    expect($steps)->toHaveCount(3)
        // MINDEN körnek SAJÁT üzenete van — nem ugyanaz replayelve.
        ->and($steps->pluck('message_uuid')->unique())->toHaveCount(3)
        ->and(Message::query()->count())->toBe(3)
        ->and(FakeDriver::$sent)->toHaveCount(3);
});

it('a VISSZAVONT consent kilépteti a futást — a CÍMKÉZÉS sem folytatódik', function () {
    /*
     | A MÉRT HIBA: a `shouldExit()` csak suppressiont nézett. `ConsentRecorder::revoke()`
     | után (suppression-sor NÉLKÜL) a futás továbbment, `chain_end`-del `completed` lett,
     | és A CÍMKE FELKERÜLT a kontaktra — profilalkotás visszavont jogalap mellett.
     |
     | A levél a ConsentGate-en elakadt volna, de a `shouldExit()` docblockja nem csak a
     | küldésről beszél: „a címkézés és a szegmens-mozgatás is profilalkotás, amit egy
     | leiratkozott embernél nem folytatunk".
     */
    confirmedContact();

    $tag = ContactTag::create(['name' => 'VIP']);

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 5]],
        ['type' => 'add_tag', 'config' => ['tag' => (string) $tag->uuid]],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    // A VISSZAVONÁS — suppression-sor nélkül, ahogy a `revoke()` önmagában teszi.
    $recorder = app(ConsentRecorder::class);
    $recorder->revoke(Consent::query()
        ->where('email', 'anna@pelda.hu')
        ->where('channel', 'marketing')
        ->firstOrFail());

    expect(Suppression::query()->count())->toBe(0);

    $this->travel(10)->minutes();

    app(JourneyEngine::class)->tick($run->fresh());

    expect($run->fresh()->status)->toBe('exited')
        ->and($run->fresh()->exit_reason)->toBe('consent_revoked')
        // A CÍMKE NEM került fel: a profilalkotás megállt.
        ->and(Contact::query()->where('email', 'anna@pelda.hu')->firstOrFail()->tags()->count())->toBe(0);
});

it('az OLVASHATATLAN journey KÉSLELTET, majd LEZÁR — nem marad örökké esedékes', function () {
    /*
     | A MÉRT HIBA KÉT FELE.
     |
     | (a) Egy hibás lépés kivétele KISZÖKÖTT a `tick()`-ből, és a futás `active` +
     |     `resume_at = NULL` + `steps_taken = 0` állapotban maradt — vagyis TOVÁBBRA IS
     |     esedékes. A percenkénti ütemező örökké újradobta: futásonként napi 1440 hiba, a
     |     futás soha nem lett `failed`, és a `MAX_STEPS_TOTAL` sem ütött be.
     |
     | (b) Ha a journey NEM OLVASHATÓ (elveszett workspace-kontextus, közben törölt
     |     journey), a `shouldExit()` ezt `journey_archived`-nak minősítette, és
     |     VÉGLEGESEN lezárta a futást: mérve egy háromlevelesnél az első levél után
     |     `status = exited`, és a 2–3. levél SOHA nem ment ki. A fail-closed azt jelenti,
     |     hogy NE CSELEKEDJ — nem azt, hogy ÖLJ MEG.
     |
     | A kettő együtt: az olvashatatlan journey KÉSLELTETI a futást (visszatér, ha a hiba
     | átmeneti), és `MAX_FAILURES` után HANGOSAN lezárja.
     */
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 5]],
        ['type' => 'exit', 'config' => []],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    // A JOURNEY eltűnik a futás alól (soft delete) — a futás viszont él.
    $journey->delete();

    $this->travel(10)->minutes();

    // ELSŐ HIBA: késleltetés, nem beragadás és nem azonnali halál.
    app(JourneyEngine::class)->tick($run->fresh());

    expect($run->fresh()->failure_count)->toBe(1)
        ->and($run->fresh()->status)->toBe('waiting')
        ->and($run->fresh()->resume_at)->not->toBeNull();

    // TOVÁBBI HIBÁK: a negyedik után a futás VÉGLEGESEN lezárul.
    for ($i = 0; $i < 4; $i++) {
        $this->travel(30)->minutes();
        app(JourneyEngine::class)->tick($run->fresh());
    }

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->exit_reason)->toBe('engine_error')
        // ÉS MÁR NEM ESEDÉKES: a percenkénti ütemező nem szedi fel újra.
        ->and(JourneyRun::query()->due()->count())->toBe(0);
});

it('a HIBÁS KONFIGURÁCIÓ megnevezett okkal zárja a futást — nem nyers SQL-kivétellel', function () {
    // A validátort MEGKERÜLVE beírt, nem-uuid hivatkozás a típusos `uuid` oszlopon
    // `SQLSTATE[22P02]`-t adna. A motor guardja ezt `config_invalid`-ra fordítja: a
    // felületen megnevezhető ok, nem egy nyers adatbázis-hiba a naplóban.
    confirmedContact();

    $tag = ContactTag::create(['name' => 'VIP']);
    $journey = journeyWith([['type' => 'add_tag', 'config' => ['tag' => (string) $tag->uuid]]]);

    DB::table('journey_steps')
        ->where('id', $journey->first_step_id)
        ->update(['config' => json_encode(['tag' => 'nem-uuid'])]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->exit_reason)->toBe('config_invalid');
});

it('a WAIT lépés felső korlátos — a túlcsordulás nem változtatja AZONNALI lépéssé', function () {
    /*
     | A MÉRT HIBA: `minutes = 9999999999` a `Carbon`-t `InvalidFormatException`-nel
     | buktatta el, és a futás orvosolhatatlanul, percenként újra elhasalt. A `PHP_INT_MAX`
     | még rosszabb: túlcsordulás után `resume_at = now + 1 másodperc`, tehát a „végtelen
     | várakozás" AZONNALI lépéssé vált.
     */
    // A trigger SZÁNDÉKOSAN `api_event`: a `fire(FormSignup)` alább MINDEN aktív
    // form-signup journey-t elindítana, és a teszt a rossz futást vizsgálná.
    $segito = journeyWith([['type' => 'exit', 'config' => []]], ['trigger_type' => TriggerType::ApiEvent->value]);

    expect(fn () => JourneyStep::create([
        'journey_id' => $segito->getKey(),
        'type' => 'wait',
        'config' => ['minutes' => PHP_INT_MAX],
    ]))->toThrow(InvalidArgumentException::class, 'egy év');

    // A validátort MEGKERÜLVE beírt érték is korlátozódik a motorban.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 5]],
        ['type' => 'exit', 'config' => []],
    ]);

    DB::table('journey_steps')
        ->where('id', $journey->first_step_id)
        ->update(['config' => json_encode(['minutes' => PHP_INT_MAX])]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    // EGY ÉV, nem egy másodperc és nem kivétel.
    expect($run->fresh()->status)->toBe('waiting')
        ->and($run->fresh()->resume_at->greaterThan(now()->addDays(300)))->toBeTrue();
});

it('a VÁRAKOZÁS NÉLKÜLI kör a MENTÉSNÉL elakad — a `wait`-es kör legitim', function () {
    /*
     | A `MAX_STEPS_TOTAL` FOG egy önmagára mutató láncot (`failed` / `step_limit`), de
     | mérve addig 26 percet fut és 500 `journey_run_steps` sort ír EGY futásra. 100 000
     | futásnál ez 50 millió sor. A lánc bejárása mentéskor ezt nullára viszi, és a hibát
     | oda teszi, ahol javítható.
     |
     | De a KÖR NEM MINDIG HIBA: egy `wait` lépést tartalmazó ciklus legitim ismétlődő
     | automatizálás — a `journey_run_steps.iteration` kifejezetten erre van.
     */
    $journey = Journey::create(['name' => 'Kör', 'trigger_type' => TriggerType::FormSignup->value]);

    $tag = ContactTag::create(['name' => 'VIP']);

    $step = JourneyStep::create([
        'journey_id' => $journey->getKey(),
        'type' => 'add_tag',
        'config' => ['tag' => (string) $tag->uuid],
    ]);

    // ÖNMAGÁRA mutat, `wait` nélkül.
    expect(fn () => $step->forceFill(['next_step_id' => $step->getKey()])->save())
        ->toThrow(InvalidArgumentException::class, 'VÁRAKOZÁS NÉLKÜLI kört');
});

it('a JOURNEY-LÁNC nem léphet át IDEGEN journey-re — az adatbázis sem engedi', function () {
    /*
     | A MÉRT HIBA: a `next_step_id` FK-ja csak a `journey_steps` táblára mutat, nem
     | UGYANARRA a journey-re. A C journey `wait` lépését a B journey `add_tag` lépésére
     | láncolva a C futása LEFUTTATTA B lépését: felkerült B címkéje, és a
     | `completed_count` a C-nél nőtt. A workspace-határ zárva volt, a JOURNEY-határ nyitva.
     */
    $tag = ContactTag::create(['name' => 'VIP']);

    $egyik = journeyWith([['type' => 'add_tag', 'config' => ['tag' => (string) $tag->uuid]]], ['name' => 'Egyik']);
    $masik = journeyWith([['type' => 'exit', 'config' => []]], ['name' => 'Másik']);

    // A NYERS írás sem: a trigger a lánc-hivatkozást az adatbázisban ellenőrzi.
    expect(fn () => DB::transaction(fn () => DB::table('journey_steps')
        ->where('id', $masik->first_step_id)
        ->update(['next_step_id' => $egyik->first_step_id])))
        ->toThrow(QueryException::class, 'IDEGEN journey');
});

it('a HAMISÍTOTT DMARC-verdikt nem indít journey-t, és a TÁRGY nem kerül a kontextusba', function () {
    /*
     | A MÉRT HIBA: az `InboundMessage` hordozza az `spf_result`/`dkim_result`/`dmarc_result`
     | oszlopokat, és a `journey` akció egyszerűen nem nézett rájuk. Egy BIZONYÍTOTTAN
     | hamisított (`dmarc_result = 'fail'`) levél így is elindított marketing ágat egy valódi
     | feliratkozó nevében, akinek a levéllel semmi köze nem volt. Mérve 24 szimulált óra
     | alatt 72 levél az áldozat postafiókjába, a tenant DKIM-jével aláírva.
     |
     | A TÁRGY sem kerülhet a `journey_runs.context`-be: mérve egy
     | „A HITELKARTYAM SZAMA 4111 1111 1111 1111" tárgy szó szerint landolt ott, retenció
     | nélkül — miközben az `inbound_messages` maga 3 napos.
     */
    confirmedContact('igazi@pelda.hu');

    $journey = journeyWith(
        [['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']]],
        ['trigger_type' => TriggerType::InboundEmail->value],
    );

    InboundRoute::create([
        'match_type' => 'match_recipient',
        'expression' => '.*@pelda.hu',
        'actions' => [['type' => 'journey', 'target' => (string) $journey->uuid]],
        'priority' => 10,
    ]);

    $hamis = InboundMessage::create([
        'message_id' => 'hamis-'.uniqid().'@evil.example',
        'sender' => 'igazi@pelda.hu',
        'recipient' => 'info@pelda.hu',
        'subject' => 'A HITELKARTYAM SZAMA 4111 1111 1111 1111',
        'dmarc_result' => 'fail',
        'spf_result' => 'fail',
        'dkim_result' => 'fail',
    ]);

    app(RouteEngine::class)->run($hamis);

    // NULLA futás: a fogadó oldal már megmondta, hogy a feladó hamis.
    expect(JourneyRun::query()->count())->toBe(0);

    // …és egy TISZTA levél átmegy — a szabály nem tiltja le a legitim esetet.
    $tiszta = InboundMessage::create([
        'message_id' => 'tiszta-'.uniqid().'@pelda.hu',
        'sender' => 'igazi@pelda.hu',
        'recipient' => 'info@pelda.hu',
        'subject' => 'A HITELKARTYAM SZAMA 4111 1111 1111 1111',
        'dmarc_result' => 'pass',
    ]);

    app(RouteEngine::class)->run($tiszta);

    $run = JourneyRun::query()->firstOrFail();

    expect($run->email)->toBe('igazi@pelda.hu')
        // A TÁRGY NEM került a kontextusba.
        ->and(json_encode($run->context))->not->toContain('4111')
        ->and(array_keys((array) $run->context))->not->toContain('subject');
});

it('a ROUTE CÉLJA szűkít — egy levél nem indítja el az ÖSSZES inbound journey-t', function () {
    /*
     | A MÉRT HIBA: a route `target`-je (a cél journey uuid-ja) átadódott a payloadban, de
     | SENKI nem olvasta. Egy „info@"-ra írt levél így a workspace ÖSSZES `inbound_email`
     | triggerű journey-jét elindította ugyanannak a címzettnek — mérve két futás egyetlen
     | levélből, egy egymással semmilyen kapcsolatban nem lévő journey-párral. Vagyis több
     | párhuzamos marketing-sorozat, egyetlen beérkező levélből.
     */
    confirmedContact('igazi@pelda.hu');

    $cel = journeyWith(
        [['type' => 'exit', 'config' => []]],
        ['name' => 'Célzott', 'trigger_type' => TriggerType::InboundEmail->value],
    );

    $masik = journeyWith(
        [['type' => 'exit', 'config' => []]],
        ['name' => 'Nem célzott', 'trigger_type' => TriggerType::InboundEmail->value],
    );

    InboundRoute::create([
        'match_type' => 'match_recipient',
        'expression' => '.*@pelda.hu',
        'actions' => [['type' => 'journey', 'target' => (string) $cel->uuid]],
        'priority' => 10,
    ]);

    $message = InboundMessage::create([
        'message_id' => 'egy-'.uniqid().'@pelda.hu',
        'sender' => 'igazi@pelda.hu',
        'recipient' => 'info@pelda.hu',
        'subject' => 'Szia',
        'dmarc_result' => 'pass',
    ]);

    app(RouteEngine::class)->run($message);

    expect(JourneyRun::query()->count())->toBe(1)
        ->and((int) JourneyRun::query()->firstOrFail()->journey_id)->toBe($cel->getKey())
        ->and(JourneyRun::query()->where('journey_id', $masik->getKey())->count())->toBe(0);
});

it('a GDPR-TÖRLÉS a journey-futásból is kiszedi a címet ÉS a kontextust', function () {
    /*
     | A MÉRT HIBA: `ContactGdpr::erase()` után a `contacts.email` már
     | `torolt-…@erased.invalid` volt, a `journey_runs.email` viszont `anna@pelda.hu`, a
     | `context` pedig `{"phone":"+36301234567","shipping_address":"Budapest, Fő utca 1."}`
     | — érintetlenül, `expires_at`/`redacted_at` oszlop nélkül.
     |
     | Betűre ugyanaz a hiba, amit a B2 a `campaign_recipients`-szel elkövetett, és amire
     | ugyanaz az ág megírta a `campaigns:purge-recipients`-t.
     */
    $contact = confirmedContact();

    $journey = journeyWith(
        [['type' => 'wait', 'config' => ['minutes' => 60]], ['type' => 'exit', 'config' => []]],
        ['trigger_type' => TriggerType::ApiEvent->value],
    );

    $run = app(JourneyTrigger::class)->enter($journey, 'anna@pelda.hu', [
        'event' => 'checkout_abandoned',
        'phone' => '+36301234567',
    ]);

    expect($run)->not->toBeNull()
        ->and(json_encode($run->context))->toContain('36301234567');

    app(ContactGdpr::class)->erase($contact);

    $run = $run->fresh();

    expect($run->email)->toBe('(redaktált)')
        ->and((array) $run->context)->toBe([]);
});

it('az API-ESEMÉNY VÉGPONTJA létezik, és a payloadja korlátos', function () {
    /*
     | A MÉRT HIBA: a `TriggerType::ApiEvent` és a `FireJourneyEvent::apiEvent()` megvolt,
     | de a `Journey/routes/` ÜRES volt — az `api_event` triggerű journey élesben SOHA nem
     | tüzelt. Egy trigger, amit csak a teszt hív, nem trigger.
     */
    expect(Route::has('v1.journeys.events.store'))->toBeTrue();

    confirmedContact();

    $journey = journeyWith(
        [['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']]],
        ['trigger_type' => TriggerType::ApiEvent->value],
    );

    $runs = app(FireJourneyEvent::class)
        ->apiEvent('anna@pelda.hu', 'checkout_abandoned', ['cart' => '149000']);

    expect($runs)->toHaveCount(1)
        ->and((int) $runs[0]->journey_id)->toBe($journey->getKey());
});

it('a SZEGMENSBE LÉPÉS ütemezett PARANCSA létezik, és beléptet', function () {
    /*
     | A MÉRT HIBA: a `FireJourneyEvent::segmentEntered()` megvolt, a docblock egy
     | `journeys:detect-segment-entries` parancsra hivatkozott — de a parancs NEM LÉTEZETT
     | (`artisan list` → csak `journeys:tick`). A `segment_entered` triggerű journey élesben
     | soha nem tüzelt. Ugyanaz a hibaosztály, mint a B2-ben a `VariantWinner`.
     */
    confirmedContact();

    $szegmens = Segment::create([
        'name' => 'Mind',
        'definition' => ['match' => 'all', 'rules' => []],
    ]);

    journeyWith(
        [['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']]],
        [
            'trigger_type' => TriggerType::SegmentEntered->value,
            'trigger_config' => ['segment' => (string) $szegmens->uuid],
        ],
    );

    $this->artisan('journeys:detect-segment-entries')->assertSuccessful();

    expect(JourneyRun::query()->count())->toBe(1)
        ->and(JourneyRun::query()->firstOrFail()->email)->toBe('anna@pelda.hu');
});

it('a HAMISÍTHATÓ trigger NAPI ÜTEMKORLÁT alatt van', function () {
    /*
     | A megerősített consent azt bizonyítja, hogy a CÍM feliratkozott — nem azt, hogy EZ a
     | levél tőle jött. Mérve: `allow_reentry` + 1 órás cooldown mellett 24 szimulált óra
     | alatt 120 hamisított levél 24 belépést és 72 kiküldött levelet adott az áldozat
     | postafiókjába, a tenant hitelesített domainjéről, a tenant kvótájából.
     */
    confirmedContact('igazi@pelda.hu');

    $journey = journeyWith(
        [['type' => 'exit', 'config' => []]],
        [
            'trigger_type' => TriggerType::InboundEmail->value,
            'allow_reentry' => true,
            'reentry_cooldown_hours' => 1,
        ],
    );

    $belepesek = 0;

    // TÍZ próbálkozás, óránként — a napi korlát HÁROM.
    for ($ora = 0; $ora < 10; $ora++) {
        $runs = app(JourneyTrigger::class)->fire(TriggerType::InboundEmail, 'igazi@pelda.hu', [
            'journey' => (string) $journey->uuid,
        ]);

        $belepesek += count($runs);

        // A FUTÁST LEZÁRJUK: a `journey_runs` részleges egyedi indexe egy AKTÍV futás
        // mellett amúgy is blokkolná az újrabelépést — itt az ÜTEMKORLÁTOT mérjük, nem azt.
        foreach ($runs as $run) {
            app(JourneyEngine::class)->tick($run);
        }

        $this->travel(2)->hours();
    }

    expect($belepesek)->toBe(3);
});

it('a LÉPÉS NÉLKÜLI journey nem léptet be, és nem tárol címet', function () {
    // Egy `first_step_id = null` journey aktív lehet (a szerkesztő még nem tett bele
    // lépést). A beléptetés korábban ilyenkor is létrejött: egy futás, aminek nincs mit
    // végrehajtania, viszont PERZISZTÁLJA a címzett CÍMÉT.
    confirmedContact();

    $ures = Journey::create(['name' => 'Üres', 'trigger_type' => TriggerType::FormSignup->value]);
    $ures->forceFill(['status' => 'active'])->save();

    expect(app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu'))->toBe([])
        ->and(JourneyRun::query()->count())->toBe(0);
});

it('a BELÉPÉS-SZÁMLÁLÓ nő — a riport nem oszt nullával', function () {
    // A `journeys.entered_count` oszlop megvolt, de SENKI nem növelte: mérve
    // `entered_count = 0` egy `completed_count = 1` mellett, vagyis minden konverziós ráta
    // (befejezés/belépés) nullával osztott.
    confirmedContact();

    $journey = journeyWith([['type' => 'exit', 'config' => []]]);

    app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu');

    expect((int) $journey->fresh()->entered_count)->toBe(1);
});

it('a TICK a VÉGREHAJTOTT lépéseket számolja, nem a folytathatóságot', function () {
    /*
     | A MÉRT HIBA: a `tick()` a `false` visszatérésnél nem növelte a számlálót, tehát
     | MINDEN felfüggesztő vagy záró lépés 0-nak számított. 200 futáson a parancs
     | `Futás: 200, lépés: 0`-t írt ki, miközben 200 `wait` lépés valóban lefutott — egy
     | csak `wait`-ekből álló journey-nél örökké nulla, és az üzemeltető egyetlen üzemi
     | jelzőszáma szisztematikusan hamis.
     */
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 60]],
        ['type' => 'exit', 'config' => []],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    // EGY `wait` lépés hajtódott végre — a tick 1-et ad vissza, nem 0-t.
    expect(app(JourneyEngine::class)->tick($run))->toBe(1)
        ->and(JourneyRunStep::query()->count())->toBe(1);
});

it('a TÖRÖLT LÉPÉS nem SIKERKÉNT zárja a futást', function () {
    // A `current_step_id` FK-ja `nullOnDelete`: egy lépés törlése a félúton lévő futások
    // `current_step_id`-jét `NULL`-ra állítja. Ez korábban `exit_reason = null`-lal
    // `completed`-re zárt, vagyis a riportban SIKERKÉNT jelent meg egy félbeszakadt
    // automatizálás.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'wait', 'config' => ['minutes' => 5]],
        ['type' => 'exit', 'config' => []],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    // A MARKETINGES törli azt a lépést, amin a futás áll.
    JourneyStep::query()->whereKey($run->fresh()->current_step_id)->delete();

    $this->travel(10)->minutes();

    app(JourneyEngine::class)->tick($run->fresh());

    expect($run->fresh()->status)->toBe('exited')
        ->and($run->fresh()->exit_reason)->toBe('step_deleted');
});
