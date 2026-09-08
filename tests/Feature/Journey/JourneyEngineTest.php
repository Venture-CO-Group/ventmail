<?php

declare(strict_types=1);

use App\Modules\Audience\Actions\SubmitForm;
use App\Modules\Audience\Data\FormSubmission;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Form;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Models\InboundRoute;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('az ISMERETLEN feltétel HAMIS, nem igaz', function () {
    // Fail-closed: egy elgépelt feltétel-típus az `else` ágra vezet, nem a levélküldésre. A
    // fordított alapérték azt jelentené, hogy egy hibás konfiguráció TÖBB levelet küld.
    confirmedContact();

    $journey = journeyWith([
        ['type' => 'condition', 'config' => ['type' => 'elgepelt_feltetel']],
        ['type' => 'send_email', 'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu']],
    ]);

    $run = app(JourneyTrigger::class)->fire(TriggerType::FormSignup, 'anna@pelda.hu')[0];

    app(JourneyEngine::class)->tick($run);

    expect(Message::query()->count())->toBe(0);
    expect(JourneyRunStep::query()->firstOrFail()->outcome)->toBe('false');
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

    // ÖNMAGÁRA mutat.
    $step->forceFill(['next_step_id' => $step->getKey()])->save();
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

    // …és egy HIBÁS futás: a sablon uuid nem létezik.
    $broken = journeyWith(
        [['type' => 'send_email', 'config' => ['template' => 'nem-letezik', 'from_email' => 'no-reply@pelda.hu']]],
        ['name' => 'Hibás'],
    );

    confirmedContact('harmadik@pelda.hu');
    app(JourneyTrigger::class)->enter($broken, 'harmadik@pelda.hu');

    $this->artisan('journeys:tick')->assertSuccessful();

    // A KÉT jó futás levele kiment, a hibás `failed` — de nem állította meg a többit.
    expect(Message::query()->count())->toBe(2);
    expect(JourneyRun::query()->where('exit_reason', 'template_missing')->count())->toBe(1);
});
