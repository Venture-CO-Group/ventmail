<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\UsageRecord;
use App\Modules\Billing\Services\Dunning;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Billing\Services\Metering;
use App\Modules\Billing\Services\MonthlyClose;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | C1 · 75–80. kritérium — metering és számlázás.
 |
 | A packet TÉTJE: a fogyás-szám a SZÁMLA alapja. Egy elveszett vagy duplikált inkrementum
 | itt nem „kicsit rossz riport", hanem HAMIS SZÁMLA — vagy a tenantnak, vagy nekünk. Ezért
 | minden dimenzió a VALÓDI úton mérődik (nem attól, hogy egy szolgáltatás hívható), és az
 | idempotencia az adatbázisban áll, nem alkalmazás-logikában.
 |
 | Amit a ventMail SOHA nem tud: árat, adót, fizetési módot, kártyaadatot. Ez a ventPay
 | dolga; itt csak FOGYÁS van.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);
});

function confirmed(string $email = 'anna@pelda.hu'): void
{
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request($email));
}

/*
 | 75. METERING MIND A HAT DIMENZIÓBAN, a VALÓDI úton
 */

it('a KÜLDÉS növeli a `sends_counter`-t — a valódi küldési úton', function () {
    // Nem a `Metering`-et hívjuk: azt bizonyítjuk, hogy a KÜLDÉS köti be. A B2
    // `VariantWinner`-je és a B3 `segment_entered`-je pontosan attól volt halott, hogy a
    // képesség megvolt, és az éles úton senki nem hívta.
    confirmed();

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'metering-1',
    ]);

    expect(app(Metering::class)->current(UsageDimension::Sends))->toBe(1);
});

it('a KÖTEGES küldés CÍMZETTENKÉNT mér, nem hívásonként', function () {
    // A kvóta-kapu is címzettben mér („különben 300 hívás × 1000 címzett »limiten belül«
    // 300 000 levelet jelentene"); a SZÁMLÁZÁS sem lehet másképp.
    foreach (['a@pelda.hu', 'b@pelda.hu', 'c@pelda.hu'] as $email) {
        confirmed($email);
    }

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['a@pelda.hu', 'b@pelda.hu', 'c@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'metering-batch',
    ]);

    expect(app(Metering::class)->current(UsageDimension::Sends))->toBe(3);
});

it('a KAPU ÁLTAL ELUTASÍTOTT küldés NEM számlázódik', function () {
    /*
     | Ez nem apróság: a suppression-listán lévő címre nem megy ki levél, tehát nem is
     | fogyás. Ha a mérés a kapu ELŐTT lenne, a tenant azért fizetne, amit a rendszer
     | (helyesen) meg sem próbált elküldeni — és épp a listahigiéniát büntetnénk.
     */
    // Consent NÉLKÜL: a 3. kapu elutasítja.
    app(SendMessage::class)([
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['nincs-consent@pelda.hu'],
        'subject' => 'Hírlevél',
        'html' => '<p>Szia</p><a href="{{ unsubscribe_url }}">Leiratkozás</a>',
        'idempotency_key' => 'metering-rejected',
    ]);

    expect(app(Metering::class)->current(UsageDimension::Sends))->toBe(0);
});

it('a `contacts_peak` a periódus MAXIMUMÁT tartja, nem a záró állapotot', function () {
    /*
     | Egy importált, majd kitisztított listánál a záró szám kevesebb — a rendszer terhelését
     | viszont a CSÚCS adta. A Mailchimp is a csúcsot számlázza, és ez a helyes: a tárolás,
     | az indexelés és a szegmens-lekérdezések költsége a maximumnál keletkezett.
     */
    $metering = app(Metering::class);

    $metering->observePeak(UsageDimension::ContactsPeak, 1000);
    $metering->observePeak(UsageDimension::ContactsPeak, 2500);
    // A CSÖKKENÉS nem írja felül a csúcsot.
    $metering->observePeak(UsageDimension::ContactsPeak, 800);

    expect($metering->current(UsageDimension::ContactsPeak))->toBe(2500);
});

it('a PILLANATKÉP-dimenziók a jelenlegi állapotot írják, nem összegeznek', function () {
    // A `dedicated_ips` és a `log_retention_tier` NEM számláló: a hónap közepén két
    // dedikált IP-ből három lesz, és a periódus végén három van — nem öt.
    $metering = app(Metering::class);

    $metering->snapshot(UsageDimension::DedicatedIps, 2);
    $metering->snapshot(UsageDimension::DedicatedIps, 3);

    expect($metering->current(UsageDimension::DedicatedIps))->toBe(3);

    $metering->snapshot(UsageDimension::LogRetentionTier, 90);

    expect($metering->current(UsageDimension::LogRetentionTier))->toBe(90);
});

it('MIND A HAT dimenzió szerepel a fogyás-kivonatban', function () {
    // A 6 dimenzió a ventPay szerződése. Ha egy kimarad, a számla hiányos — és a hiányt
    // nem a rendszer, hanem a könyvelés veszi észre, hónapokkal később.
    expect(UsageDimension::values())->toHaveCount(6)
        ->toContain('sends_counter', 'contacts_peak', 'validations_counter',
            'inbound_counter', 'dedicated_ips', 'log_retention_tier');
});

/*
 | 80. AZ IDEMPOTENCIA az adatbázisban áll
 */

it('a PÁRHUZAMOS inkrementum nem veszít el fogyást', function () {
    /*
     | A számláló `upsert`-tel nő, és az ütközési ág `value + n`-t ír — nem „beolvasom,
     | hozzáadom, visszaírom", ami két párhuzamos küldés között elveszít egy levelet.
     | Egy elveszett inkrementum itt bevétel-kiesés; egy duplikált pedig hamis számla.
     */
    $metering = app(Metering::class);

    foreach (range(1, 50) as $i) {
        $metering->increment(UsageDimension::Sends, 2);
    }

    expect($metering->current(UsageDimension::Sends))->toBe(100)
        // EGY sor, nem ötven: az aggregált grain a periódus + dimenzió.
        ->and(UsageRecord::query()->where('dimension', 'sends_counter')->count())->toBe(1);
});

it('a LEZÁRT periódus fogyása nem írható — az ADATBÁZIS sem engedi', function () {
    /*
     | 78. kritérium. Egy késve beérkező webhook-esemény bármikor megpróbálhatja, és egy
     | már kiszámlázott hónap összege utólag nem változhat: a tenant számlája nem egyeznék
     | a rendszerben látható számmal.
     |
     | A precedens ugyanebben a repóban a B2 sablon-zára és a B3 lánc-zára: ami a fejlesztő
     | emlékezetén múlik minden hívási helyen, azt valahol elfelejtjük.
     */
    $metering = app(Metering::class);
    $metering->increment(UsageDimension::Sends, 5);

    app(MonthlyClose::class)->close($this->workspace, $metering->period());

    // A SZOLGÁLTATÁS-ÚT csendben nem ír (a lezárt periódust kihagyja).
    $metering->increment(UsageDimension::Sends, 100);

    expect($metering->current(UsageDimension::Sends))->toBe(5);

    // …és a NYERS írás sem: a trigger a végső határ.
    expect(fn () => DB::transaction(fn () => DB::table('usage_records')
        ->where('workspace_id', $this->workspace->getKey())
        ->where('dimension', 'sends_counter')
        ->update(['value' => 999])))
        ->toThrow(QueryException::class, 'LEZÁRVA');
});

/*
 | 78. HAVI ZÁRÁS — idempotens
 */

it('a HAVI ZÁRÁS idempotens: kétszeri futás EGY kivonatot ad', function () {
    $metering = app(Metering::class);
    $metering->increment(UsageDimension::Sends, 7);
    $metering->observePeak(UsageDimension::ContactsPeak, 120);

    $close = app(MonthlyClose::class);
    $period = $metering->period();

    $first = $close->close($this->workspace, $period);
    $second = $close->close($this->workspace, $period);

    expect(Invoice::query()->count())->toBe(1)
        ->and($second->getKey())->toBe($first->getKey())
        // A KIVONAT a fogyást a zárás pillanatában rögzíti — történelmi dokumentum.
        ->and($first->usage['sends_counter'])->toBe(7)
        ->and($first->usage['contacts_peak'])->toBe(120);
});

it('a KIVONAT MIND A HAT dimenziót tartalmazza, a nem használtat is NULLÁVAL', function () {
    // A hiányzó kulcs és a nulla KÜLÖNBÖZŐ: a hiányzó azt jelenti, hogy nem tudjuk, a
    // nulla azt, hogy nem volt fogyás. A ventPay oldalán ez a különbség számla-tétel.
    app(Metering::class)->increment(UsageDimension::Sends, 3);

    $invoice = app(MonthlyClose::class)->close($this->workspace, app(Metering::class)->period());

    foreach (UsageDimension::values() as $dimension) {
        expect($invoice->usage)->toHaveKey($dimension);
    }

    expect($invoice->usage['inbound_counter'])->toBe(0);
});

/*
 | 76. ENTITLEMENT-GATE az öt funkcióra
 */

it('az ENTITLEMENT-GATE a PreSendGate 4. kapujában van, és a hiányzó jogosultság STRUKTURÁLT okot ad', function () {
    // A kapu-sorrend nem mindegy: a jogosultság-ellenőrzés a küldés ELŐTT áll, és a
    // döntés STRUKTURÁLT `reason`-nal jön (a nyers szöveg csendben törné az integrációkat).
    confirmed();

    config(['sending.fake_entitlement.remaining' => 0]);

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'entitlement-quota',
    ]);

    expect($result->decision->allowed)->toBeFalse()
        ->and($result->decision->reason)->toBe('QUOTA_EXCEEDED');
});

it('az ÖT FUNKCIÓ mindegyike nevesített, és a hiányzó jogosultság ZÁR', function () {
    /*
     | A spec öt funkciót nevez meg: `journeys`, `custom_domain`, `dedicated_ip`,
     | `inbound_routes`, `validation_bulk`. A `validation_bulk` a `config`-ban NEM volt
     | benne, tehát a bulk-címellenőrzés jogosultsága sosem teljesült volna — egy
     | „engedélyezett" funkció, amit senki nem tud bekapcsolni.
     */
    expect(Feature::values())->toHaveCount(5)
        ->toContain('journeys', 'custom_domain', 'dedicated_ip', 'inbound_routes', 'validation_bulk');

    $gate = app(EntitlementChecker::class);

    // A FAKE configból dolgozik, amíg a ventPay nem él.
    config(['sending.fake_entitlement.features' => ['journeys']]);

    expect($gate->allows($this->workspace, Feature::Journeys))->toBeTrue()
        ->and($gate->allows($this->workspace, Feature::ValidationBulk))->toBeFalse();
});

it('az `EnsureEntitlement` MIDDLEWARE route-szinten is véd', function () {
    /*
     | A B3 review megállapította, hogy a spec által nevesített `EnsureEntitlement`
     | middleware NEM LÉTEZIK — az ellenőrzés inline volt a journey-triggerben és a
     | motorban. Funkcionálisan fogott, de egy route-szintű védelem nélkül minden új
     | belépési pontnak újra meg kell írnia.
     */
    config(['sending.fake_entitlement.features' => []]);

    ['plain' => $kulcs] = ApiKey::issue('Bulk', ['validate:run']);

    // A BULK-VÉGPONT a `validation_bulk` jogosultságot kéri.
    $this->withHeader('Authorization', 'Bearer '.$kulcs)
        ->postJson('/v1/validate/bulk', ['addresses' => ['a@pelda.hu']])
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'ENTITLEMENT_REQUIRED');

    // …és a jogosultsággal átmegy.
    config(['sending.fake_entitlement.features' => ['validation_bulk']]);

    $this->withHeader('Authorization', 'Bearer '.$kulcs)
        ->postJson('/v1/validate/bulk', ['addresses' => ['a@pelda.hu']])
        ->assertAccepted();
});

/*
 | 77. SUB-ACCOUNT KONSZOLIDÁLT SZÁMLA per-child bontással
 */

it('a SUB-ACCOUNT fogyása a PARENT számláján van, TÉTELESEN', function () {
    /*
     | A parent fizet, de a bontásnak visszakereshetőnek kell lennie: „miből jött ez az
     | összeg?" egy konszolidált számlánál az első kérdés. A child fogyása NEM duplikálódik:
     | a parent összesítője a child SORAIRA hivatkozik, nem másolja őket egy harmadik helyre.
     */
    $context = app(WorkspaceContext::class);
    $parent = $this->workspace;

    $child = Workspace::factory()->create(['name' => 'Alszámla']);
    $parent->children()->attach($child, ['permissions' => '{}']);

    // A PARENT fogyása.
    app(Metering::class)->increment(UsageDimension::Sends, 10);

    // A CHILD fogyása — a saját kontextusában.
    $context->runFor($child, fn () => app(Metering::class)->increment(UsageDimension::Sends, 25));

    $period = app(Metering::class)->period();

    app(MonthlyClose::class)->closeConsolidated($parent, $period);

    $consolidated = app(MonthlyClose::class)->consolidated($parent, $period);

    expect($consolidated['total']['sends_counter'])->toBe(35)
        ->and($consolidated['children'])->toHaveCount(1)
        ->and($consolidated['children'][0]['workspace'])->toBe('Alszámla')
        ->and($consolidated['children'][0]['usage']['sends_counter'])->toBe(25);

    // A CHILD SAJÁT kivonata is létezik, `parent_workspace_id`-vel megjelölve: nem ő fizeti.
    $childInvoice = Invoice::query()->withoutGlobalScopes()
        ->where('workspace_id', $child->getKey())->firstOrFail();

    expect((int) $childInvoice->parent_workspace_id)->toBe($parent->getKey());
});

it('a KONSZOLIDÁLÁS nem lát át IDEGEN parenthez', function () {
    // A konszolidált olvasás az egyetlen hely, ahol a global scope kikapcsol — a szűrő
    // ezért kézi és PONTOS: idegen tenant sora egyik ágon sem férhet bele.
    $parent = $this->workspace;
    $idegen = Workspace::factory()->create(['name' => 'Idegen']);

    app(WorkspaceContext::class)->runFor($idegen, fn () => app(Metering::class)->increment(UsageDimension::Sends, 500));

    $period = app(Metering::class)->period();

    app(MonthlyClose::class)->closeConsolidated($parent, $period);
    app(WorkspaceContext::class)->runFor($idegen, fn () => app(MonthlyClose::class)->close($idegen, $period));

    $consolidated = app(MonthlyClose::class)->consolidated($parent, $period);

    expect($consolidated['children'])->toBeEmpty()
        ->and($consolidated['total']['sends_counter'] ?? 0)->toBe(0);
});

/*
 | 79. DUNNING-SZIMULÁCIÓ
 */

it('a DUNNING-ÁLLAPOTGÉP végigmegy, és a `suspended` a PRESENDGATE-en FOG', function () {
    /*
     | A `suspended` nem felületi jelzés: ha csak a riport-oldalon látszana, a küldés
     | tovább menne — és a nem fizető tenant a mi IP-nkről, a mi reputációnkkal küldene
     | tovább. A dunning a ventPay döntésének TÜKRE; a ventMail annyit tud, hogy emiatt
     | küldhet-e.
     */
    confirmed();

    $dunning = app(Dunning::class);
    $period = app(Metering::class)->period();

    app(Metering::class)->increment(UsageDimension::Sends, 1);

    $invoice = app(MonthlyClose::class)->close($this->workspace, $period);

    expect($invoice->dunning_state)->toBe('open');

    // A HATÁRIDŐ lejár → `due`.
    $this->travelTo($invoice->due_at->copy()->addDay());
    $dunning->advance();

    expect($invoice->fresh()->dunning_state)->toBe('due');

    // TOVÁBBI türelmi idő után → `overdue`.
    $this->travelTo($invoice->due_at->copy()->addDays(Dunning::OVERDUE_AFTER_DAYS + 1));
    $dunning->advance();

    expect($invoice->fresh()->dunning_state)->toBe('overdue');

    // …majd `suspended`.
    $this->travelTo($invoice->due_at->copy()->addDays(Dunning::SUSPEND_AFTER_DAYS + 1));
    $dunning->advance();

    expect($invoice->fresh()->dunning_state)->toBe('suspended');

    // ÉS A KÜLDÉS ELAKAD — a kapun, nem a felületen.
    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'dunning-suspended',
    ]);

    expect($result->decision->allowed)->toBeFalse()
        ->and($result->decision->reason)->toBe('QUOTA_EXCEEDED');
});

it('a RENDEZÉS visszaadja a küldés jogát', function () {
    // A felfüggesztés nem büntetés, hanem védelem: amint a ventPay azt mondja, hogy
    // rendezve, a küldésnek AZONNAL mennie kell — nem a következő zárási ciklusban.
    confirmed();

    $period = app(Metering::class)->period();
    app(Metering::class)->increment(UsageDimension::Sends, 1);

    $invoice = app(MonthlyClose::class)->close($this->workspace, $period);
    $invoice->forceFill(['dunning_state' => 'suspended', 'suspended_at' => now()])->save();

    expect(app(Dunning::class)->isSuspended($this->workspace))->toBeTrue();

    app(Dunning::class)->markPaid($invoice);

    expect($invoice->fresh()->dunning_state)->toBe('paid')
        ->and($invoice->fresh()->paid_at)->not->toBeNull()
        ->and(app(Dunning::class)->isSuspended($this->workspace))->toBeFalse();

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'dunning-paid',
    ]);

    expect($result->decision->allowed)->toBeTrue();
});

/*
 | AZ ÜTEMEZETT PARANCSOK — a mérés és a zárás valódi belépési pontjai
 */

it('a PILLANATKÉP-PARANCS méri a kontaktokat, az IP-ket és a retenciós tiert', function () {
    // A parancs a valódi belépési pont: ha csak a szolgáltatás tudná mérni, a csúcs- és
    // állapot-dimenziók élesben SOHA nem keletkeznének.
    Contact::create(['email' => 'egy@pelda.hu', 'status' => 'subscribed']);
    Contact::create(['email' => 'ketto@pelda.hu', 'status' => 'subscribed']);

    $torolt = Contact::create(['email' => 'torolt@pelda.hu', 'status' => 'subscribed']);
    $torolt->delete();

    $this->artisan('billing:snapshot')->assertSuccessful();

    $metering = app(Metering::class);

    // A TÖRÖLT kontakt NEM fizetendő: nem küldhető cím. A B4 `ListAnalytics`-ában ugyanez
    // a döntés mérve 3-at mondott 2 helyett — ott a riport hazudott, itt a SZÁMLA hazudna.
    expect($metering->current(UsageDimension::ContactsPeak))->toBe(2)
        ->and($metering->current(UsageDimension::LogRetentionTier))
        ->toBe((int) config('sending.delivery_attempt_retention_days'));
});

it('a ZÁRÓ PARANCS az ELŐZŐ periódust zárja, és a KONSZOLIDÁLÁST végzi', function () {
    /*
     | Az előző periódust, nem a folyót: egy futó hónap lezárása azt jelentené, hogy a hónap
     | hátralévő fogyása elveszik (a `Metering` a lezárt periódust kihagyja).
     |
     | És a CHILDOKAT a `closeConsolidated()` zárja — ha a parancs minden workspace-t külön
     | zárna, a child kivonata parent-jelölés NÉLKÜL készülne el, és a konszolidált
     | számlából kimaradna: a parent kevesebbet fizetne, a child pedig egy számlát kapna,
     | amit nem ő fizet.
     */
    $child = Workspace::factory()->create(['name' => 'Alszámla']);
    $this->workspace->children()->attach($child, ['permissions' => '{}']);

    $elozo = now()->utc()->subMonth()->format('Y-m');

    // FOGYÁS az ELŐZŐ periódusban — a `Metering` az aktuálisat írja, ezért oda utazunk.
    $this->travelTo(now()->subMonth());

    app(Metering::class)->increment(UsageDimension::Sends, 4);
    app(WorkspaceContext::class)->runFor($child, fn () => app(Metering::class)->increment(UsageDimension::Sends, 6));

    $this->travelBack();

    $this->artisan('billing:close')->assertSuccessful();

    $consolidated = app(MonthlyClose::class)->consolidated($this->workspace, $elozo);

    expect($consolidated['total']['sends_counter'])->toBe(10)
        ->and($consolidated['children'])->toHaveCount(1)
        ->and($consolidated['children'][0]['usage']['sends_counter'])->toBe(6);

    // A CHILD kivonata parent-jelölést kapott: nem ő fizeti.
    expect((int) Invoice::query()->withoutGlobalScopes()
        ->where('workspace_id', $child->getKey())->firstOrFail()->parent_workspace_id)
        ->toBe($this->workspace->getKey());
});

it('a DUNNING-PARANCS legfeljebb EGY állapotot léptet futásonként', function () {
    /*
     | A léptetés hátulról előre megy. Előrefelé haladva egy 40 napos késésnél a kivonat
     | EGY futásban `open → due → overdue → suspended`-ig jutna (mindegyik feltétel igaz),
     | és a figyelmeztetési szakaszok kimaradnának — a tenant a `due` értesítést meg sem
     | kapná, csak a felfüggesztést.
     */
    app(Metering::class)->increment(UsageDimension::Sends, 1);

    $invoice = app(MonthlyClose::class)->close($this->workspace, app(Metering::class)->period());

    // NAGYON KÉSETT: minden határidő lejárt.
    $this->travelTo($invoice->due_at->copy()->addDays(60));

    $this->artisan('billing:dunning')->assertSuccessful();

    expect($invoice->fresh()->dunning_state)->toBe('due');

    $this->artisan('billing:dunning')->assertSuccessful();

    expect($invoice->fresh()->dunning_state)->toBe('overdue');

    $this->artisan('billing:dunning')->assertSuccessful();

    expect($invoice->fresh()->dunning_state)->toBe('suspended');
});

it('a FELFÜGGESZTÉS a SUB-ACCOUNTOT is megállítja', function () {
    /*
     | Ha a child a saját állapotát vinné, a felfüggesztés egy alszámla létrehozásával
     | megkerülhető lenne: a parent felfüggesztve, a friss child pedig „tiszta lappal"
     | küldhetne — a MI IP-nkről, a MI reputációnkkal.
     */
    $child = Workspace::factory()->create(['name' => 'Alszámla']);
    $this->workspace->children()->attach($child, ['permissions' => '{}']);

    app(Metering::class)->increment(UsageDimension::Sends, 1);

    $invoice = app(MonthlyClose::class)->close($this->workspace, app(Metering::class)->period());
    $invoice->forceFill(['dunning_state' => 'suspended', 'suspended_at' => now()])->save();

    expect(app(Dunning::class)->isSuspended($child))->toBeTrue();

    // …és a child küldése is elakad a kapun.
    app(WorkspaceContext::class)->runFor($child, function () {
        Domain::create(['name' => 'alszamla.hu', 'status' => 'verified', 'verified_at' => now()]);

        $recorder = app(ConsentRecorder::class);
        $recorder->confirm($recorder->request('child@alszamla.hu'));

        $result = app(SendMessage::class)([
            'channel' => 'transactional',
            'from_email' => 'no-reply@alszamla.hu',
            'to' => ['child@alszamla.hu'],
            'subject' => 'Szia',
            'html' => '<p>Szia</p>',
            'idempotency_key' => 'child-suspended',
        ]);

        expect($result->decision->allowed)->toBeFalse()
            ->and($result->decision->reason)->toBe('QUOTA_EXCEEDED');
    });
});
