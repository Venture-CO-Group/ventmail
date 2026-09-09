<?php

declare(strict_types=1);

use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Models\AuditLog;
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
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Services\InboundIngest;
use App\Modules\Inbound\Services\VerifiedInboundResolver;
use App\Modules\Inbound\Testing\FakeRawMailStore;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    // A `FakeDriver::$sent` STATIKUS: a korábbi tesztek küldései különben beszivárognak,
    // és egy „nulla levél" állítás a szomszéd teszt forgalmát látja.
    FakeDriver::reset();

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

    /*
     | A PERIÓDUS RENDEZETLEN — a ventPay szava, nem a mi óránk.
     |
     | Enélkül az állapotgép SEMMIT nem léptet, és ez a HELYES alapérték: a
     | `FakeVentPayClient` mindent rendezettnek jelent, amíg a ventPay nem él. A review
     | megmérte, hogy a fordított alapérték ~45 nappal az első zárás után MINDEN tenantot
     | felfüggesztett — fizetési információ nélkül, `markPaid()` hívó nélkül, vagyis a
     | platform önmagát állította volna le.
     */
    config(['sending.fake_entitlement.unsettled' => [$period]]);

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

    /*
     | A RENDEZÉS a ventPay szava — és a `advance()` az, ami továbbítja.
     |
     | A `markPaid()`-nek eredetileg NULLA produkciós hívója volt: se webhook, se parancs,
     | se route. Az állapotgépnek volt bemenete és nem volt kimenete. Most a `advance()`
     | kérdezi meg a ventPay-t, tehát a rendezés a NORMÁL napi futáson érkezik meg.
     */
    app(Dunning::class)->advance();

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

    $period = app(Metering::class)->period();
    $invoice = app(MonthlyClose::class)->close($this->workspace, $period);

    // RENDEZETLEN periódus: enélkül a `settle()` azonnal `paid`-re zárná (lásd a fenti
    // teszt indoklását).
    config(['sending.fake_entitlement.unsettled' => [$period]]);

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

/*
 | A REVIEW-BAN MÉRT HIBÁK regressziós tesztjei (reviewer + security).
 */

it('a TESTMODE NEM számlázódik — a levél nem is megy ki', function () {
    // A Mailgun (a paritás-cél) a testmode-ot nem számlázza, és az SMTP-n ez nem egyszeri
    // állapot: egy tartósan tesztmódban álló hitelesítő MINDEN beadott levél után fizetett
    // volna. Épp a CI-integrációt büntettük.
    confirmed();

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'testmode' => true,
        'idempotency_key' => 'testmode-nincs-fogyas',
    ]);

    expect(app(Metering::class)->current(UsageDimension::Sends))->toBe(0)
        ->and(FakeDriver::$sent)->toHaveCount(0);
});

it('az ÜTEMEZETT küldés a KIKÜLDÉSKOR fizet, és az elutasított NEM', function () {
    /*
     | A MÉRT HIBA: `deliverytime = +5 min` → `sends_counter` +1; utána a domain
     | deverifikálva → a dispatcher `DOMAIN_UNVERIFIED`-del ELUTASÍTOTTA a levelet, és az
     | inkrementum BENT MARADT. A packet fő garanciája („a kapu által elutasított küldés
     | nem számlázódik") pont azon az úton volt hamis, ahol a kapu-döntés VÁLTOZNI szokott.
     */
    confirmed();

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'deliverytime' => now()->addMinutes(5)->toIso8601String(),
        'idempotency_key' => 'utemezett-1',
    ]);

    expect($result->message->status)->toBe('scheduled')
        // AZ ELFOGADÁS MÉG NEM FOGYÁS.
        ->and(app(Metering::class)->current(UsageDimension::Sends))->toBe(0);

    // A DOMAIN közben elveszti a verifikációt.
    Domain::query()->where('name', 'pelda.hu')->update(['status' => 'pending', 'verified_at' => null]);

    $this->travel(10)->minutes();

    $this->artisan('messages:dispatch-scheduled')->assertSuccessful();

    expect($result->message->fresh()->status)->toBe('rejected')
        // ÉS NEM SZÁMLÁZÓDOTT: a rendszer maga állította meg a levelet.
        ->and(app(Metering::class)->current(UsageDimension::Sends))->toBe(0);
});

it('az ÜTEMEZETT küldés a SIKERES kiküldéskor MÉR', function () {
    // A másik irány: ami valóban kimegy, azt ki kell számlázni — különben az ütemezés
    // ingyen küldés lenne.
    confirmed();

    app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'deliverytime' => now()->addMinutes(5)->toIso8601String(),
        'idempotency_key' => 'utemezett-2',
    ]);

    $this->travel(10)->minutes();

    $this->artisan('messages:dispatch-scheduled')->assertSuccessful();

    expect(app(Metering::class)->current(UsageDimension::Sends))->toBe(1);
});

it('a BEÉRKEZŐ LEVÉL a valódi fogadási úton mér', function () {
    /*
     | A 75. kritérium kifejezetten HTTP/parancs-szintű bizonyítást kér. Az `inbound_counter`
     | korábban csak az enum-listázásban és elvárt nullaként szerepelt: ha valaki törli a
     | mérés sorát az `InboundIngest`-ből, egyetlen teszt sem lett volna piros.
     |
     | A teszt az `InboundIngest::accept()`-en megy át — azon a szolgáltatáson, ami a levél
     | ÁTVÉTELÉÉRT felel, és ami a `250 OK`-t adja a küldőnek. Az MX-protokoll részleteit a
     | `tests/Feature/Inbound` fedi; itt a MÉRÉS bekötése a kérdés.
     */
    Domain::query()->where('name', 'pelda.hu')->update(['inbound_mx_status' => 'verified']);

    // A NYERS MIME tárolója fake: az éles a Wasabin ír, és a fogadás a tárolás UTÁN mér —
    // egy elhasalt `put()` mellett a mérés sem futna le, és a teszt a rossz dolgot mérné.
    $this->app->instance(RawMailStore::class, new FakeRawMailStore);

    $recipient = app(VerifiedInboundResolver::class)->resolve('info@pelda.hu');

    expect($recipient)->not->toBeNull('a bejövő címzett nem oldódott fel');

    $reply = app(InboundIngest::class)->accept(
        [$recipient],
        new SmtpEnvelope('kuldo@kulso.example', ['info@pelda.hu']),
        "From: kuldo@kulso.example\r\nTo: info@pelda.hu\r\nSubject: Szia\r\n\r\nSzia.\r\n",
    );

    // A LEVÉL ÁTVÉVE — enélkül a fogyás-állítás a rossz dolgot mérné.
    expect($reply->code)->toBe(250)
        ->and(InboundMessage::query()->count())->toBe(1)
        ->and(app(Metering::class)->current(UsageDimension::Inbound))->toBe(1);
});

it('a CÍMELLENŐRZÉS a valódi API-úton mér', function () {
    ['plain' => $kulcs] = ApiKey::issue('Validate', ['validate:run']);

    $this->withHeader('Authorization', 'Bearer '.$kulcs)
        ->postJson('/v1/validate', ['address' => 'anna@pelda.hu'])
        ->assertOk();

    expect(app(Metering::class)->current(UsageDimension::Validations))->toBe(1);
});

it('a DEDIKÁLT IP-k a TENANT SAJÁT poolján számolódnak, nem platform-szinten', function () {
    /*
     | A MÉRT HIBA: az `IpAddress` SZÁNDÉKOSAN nem workspace-hez kötött (a küldő IP a MI
     | infrastruktúránk), tehát a puszta `IpAddress::count()` PLATFORM-SZINTŰ volt.
     | A review megmérte: A-tenant 1 dedikált IP, B-tenant 2, megosztott pool 4 →
     | MINDKETTŐ `dedicated_ips = 7`. Minden tenant kifizette a teljes IP-készletet,
     | beleértve a megosztott poolt, amiért senki nem fizet külön — és ez a hat dimenzió
     | közül a legdrágább tétel. Ráadásul a `usage_records` soron át minden tenant megtudta
     | a platform IP-darabszámát.
     */
    $sajat = IpPool::create(['name' => 'Saját', 'type' => 'dedicated']);
    IpAddress::create(['ip_pool_id' => $sajat->getKey(), 'address' => '203.0.113.10', 'hostname' => 'a.pelda.hu']);
    IpAddress::create(['ip_pool_id' => $sajat->getKey(), 'address' => '203.0.113.11', 'hostname' => 'b.pelda.hu']);

    /*
     | MEGOSZTOTT platform-pool (`workspace_id = null`): a csomag árában van, tehát nem
     | fizetendő tételként. A `workspace_id` szándékosan NEM `fillable` (a
     | `BelongsToWorkspace` a kontextusból tölti), ezért a platform-poolt nyers írással
     | hozzuk létre — pontosan ahogy egy platform-provisioning parancs tenné.
     */
    $megosztottId = DB::table('ip_pools')->insertGetId([
        'uuid' => (string) Str::uuid7(),
        'workspace_id' => null,
        'name' => 'Megosztott',
        'type' => 'shared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    IpAddress::create(['ip_pool_id' => $megosztottId, 'address' => '203.0.113.99', 'hostname' => 'shared.ventmail.app']);

    // EGY MÁS tenant dedikált IP-je.
    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->runFor($masik, function () {
        $pool = IpPool::create(['name' => 'Idegen', 'type' => 'dedicated']);
        IpAddress::create(['ip_pool_id' => $pool->getKey(), 'address' => '203.0.113.50', 'hostname' => 'idegen.hu']);
    });

    $this->artisan('billing:snapshot')->assertSuccessful();

    // CSAK a SAJÁT dedikált IP-k: kettő.
    expect(app(Metering::class)->current(UsageDimension::DedicatedIps))->toBe(2);
});

it('a FOLYÓ és a JÖVŐBELI periódus NEM zárható', function () {
    /*
     | A MÉRT HIBA: a folyó periódus lezárása CSENDES ingyen küldést adott — a zárás után
     | öt kiküldött levél, `sends_counter` VÁLTOZATLAN, nulla kivétel, nulla naplósor. A
     | `Metering` „csendben kihagyom" ága a késve érkező webhookra helyes, de nem szabad
     | ugyanígy elnyelnie egy ÉLŐ küldést.
     */
    $this->artisan('billing:close', ['--period' => now()->utc()->format('Y-m')])->assertFailed();
    $this->artisan('billing:close', ['--period' => '2030-01'])->assertFailed();

    expect(Invoice::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('a LEZÁRT periódus MINDEN dimenziója zárt — nincs árva fogyás-sor', function () {
    /*
     | A MÉRT HIBA: a zárás csak a LÉTEZŐ sorokat zárta, az `isClosed()` pedig dimenzióra
     | szűrt. Egy dimenzió, aminek a zárás pillanatában nem volt sora, ÚJ sort kapott
     | `closed_at = NULL`-lal a MÁR LEZÁRT periódusban (mérve `inbound_counter = 7`), a
     | kivonat pedig `0`-t mutatott — és mivel a `close()` idempotensen visszatér, az a 7
     | esemény SOHA nem került számlára.
     */
    $metering = app(Metering::class);
    $period = $metering->period();

    // CSAK a küldés-számláló létezik a zárás pillanatában.
    $metering->increment(UsageDimension::Sends, 6);

    app(MonthlyClose::class)->close($this->workspace, $period);

    // EGY MÁSIK dimenzió késve érkező fogyása.
    $metering->increment(UsageDimension::Inbound, 7);

    expect($metering->current(UsageDimension::Inbound))->toBe(0)
        ->and(UsageRecord::query()->whereNull('closed_at')->count())->toBe(0)
        // MIND A HAT sor létezik és zárt.
        ->and(UsageRecord::query()->where('period', $period)->count())->toBe(6);
});

it('a LEZÁRT fogyás nem NYITHATÓ ÚJRA és nem TÖRÖLHETŐ', function () {
    /*
     | A MÉRT HIBA: a régi trigger feltétele `new.closed_at is not null` volt, tehát a
     | `closed_at` egyidejű nullázása teljesen kikerülte — EGY `UPDATE` elég volt. A
     | `DELETE` pedig egyáltalán nem volt őrizve (`before update` only).
     */
    app(Metering::class)->increment(UsageDimension::Sends, 5);
    app(MonthlyClose::class)->close($this->workspace, app(Metering::class)->period());

    $savepoint = static fn (callable $write): callable => static fn () => DB::transaction($write);

    // EGY utasításban nullázni és átírni: nem megy.
    expect($savepoint(fn () => DB::table('usage_records')
        ->update(['value' => 0, 'closed_at' => null])))
        ->toThrow(QueryException::class, 'auditált művelet');

    // Csak a lezárást visszavonni: szintén nem.
    expect($savepoint(fn () => DB::table('usage_records')->update(['closed_at' => null])))
        ->toThrow(QueryException::class, 'auditált művelet');

    // Törölni sem.
    expect($savepoint(fn () => DB::table('usage_records')->delete()))
        ->toThrow(QueryException::class, 'nem törölhető');

    expect((int) DB::table('usage_records')->where('dimension', 'sends_counter')->value('value'))->toBe(5);
});

it('a KIVONAT fogyás-adata megváltoztathatatlan, és a kivonat nem törölhető', function () {
    /*
     | A MÉRT HIBA: az `invoices` táblán NULLA trigger volt. A `usage` átírható volt, a
     | `dunning_state` `paid`-re állítható (felfüggesztés feloldása FIZETÉS NÉLKÜL), a
     | kivonat törölhető, és egy nem létező állapot (`ingyen_kuldes`) VÉGLEG kivette a
     | kivonatot az állapotgépből — egy 90 napos tartozás három `advance()` után is ott
     | állt, mert egyetlen `where` sem illeszkedett rá.
     */
    app(Metering::class)->increment(UsageDimension::Sends, 3);

    $invoice = app(MonthlyClose::class)->close($this->workspace, app(Metering::class)->period());

    $savepoint = static fn (callable $write): callable => static fn () => DB::transaction($write);

    expect($savepoint(fn () => DB::table('invoices')->where('id', $invoice->getKey())
        ->update(['usage' => json_encode(['sends_counter' => 0])])))
        ->toThrow(QueryException::class, 'LEZÁRT dokumentum');

    expect($savepoint(fn () => DB::table('invoices')->where('id', $invoice->getKey())->delete()))
        ->toThrow(QueryException::class, 'nem törölhető');

    // A NEM LÉTEZŐ állapot sem: a CHECK constraint zár.
    expect($savepoint(fn () => DB::table('invoices')->where('id', $invoice->getKey())
        ->update(['dunning_state' => 'ingyen_kuldes'])))
        ->toThrow(QueryException::class, 'invoices_dunning_state_check');

    // …az ÁLLAPOTVÁLTÁS viszont megy: az az állapotgép munkája.
    DB::table('invoices')->where('id', $invoice->getKey())->update(['dunning_state' => 'due']);

    expect($invoice->fresh()->dunning_state)->toBe('due');
});

it('a FELFÜGGESZTÉS nem kapcsolható ki a PARENT-KAPCSOLAT átírásával', function () {
    /*
     | A MÉRT HIBA: az `isSuspended()` a parentet kérdezte, és ha volt parent, a workspace
     | SAJÁT kivonatát SOHA nem nézte meg. A review megmérte:
     |
     |     isSuspended(felfüggesztett) = true
     |     → parent-kapcsolat felvéve egy TISZTA parent alá
     |     → isSuspended = false, és a küldés `delivered` lett
     |
     | A packet kommentje csak az egyik irányt védte („alszámla létrehozásával
     | megkerülhető"); a felfüggesztett fiók BEKÖLTÖZÉSE egy tiszta parent alá nyitva volt.
     */
    app(Metering::class)->increment(UsageDimension::Sends, 1);

    $invoice = app(MonthlyClose::class)->close($this->workspace, app(Metering::class)->period());
    $invoice->forceFill(['dunning_state' => 'suspended', 'suspended_at' => now()])->save();

    expect(app(Dunning::class)->isSuspended($this->workspace))->toBeTrue();

    // BEKÖLTÖZÉS egy TISZTA parent alá.
    $tisztaParent = Workspace::factory()->create(['name' => 'Tiszta']);
    $tisztaParent->children()->attach($this->workspace, ['permissions' => '{}']);

    // A FELFÜGGESZTÉS MEGMARAD: a saját kivonata is számít.
    expect(app(Dunning::class)->isSuspended($this->workspace->fresh()))->toBeTrue();
});

it('a KONSZOLIDÁLÁS a PIVOTOT ellenőrzi, nem a `parent_workspace_id`-t hiszi el', function () {
    /*
     | A MÉRT HIBA: egy `UPDATE invoices SET parent_workspace_id = <parent>` egy IDEGEN
     | tenant kivonatán a parent számlájára tette az idegen fogyást
     | (`total.sends_counter` 35 → 1 000 034), és kiszivárogtatta az idegen workspace NEVÉT
     | és UUID-jét is — valós `workspace_parents` kapcsolat NÉLKÜL.
     */
    $parent = $this->workspace;
    $period = app(Metering::class)->period();

    app(Metering::class)->increment(UsageDimension::Sends, 10);

    $idegen = Workspace::factory()->create(['name' => 'Idegen-Titkos-Ceg']);

    app(WorkspaceContext::class)->runFor($idegen, function () use ($idegen, $period) {
        app(Metering::class)->increment(UsageDimension::Sends, 999999);
        app(MonthlyClose::class)->close($idegen, $period);
    });

    app(MonthlyClose::class)->closeConsolidated($parent, $period);

    // A TÁMADÁS: az idegen kivonat parent-jelölésének átírása.
    DB::table('invoices')->where('workspace_id', $idegen->getKey())
        ->update(['parent_workspace_id' => $parent->getKey()]);

    $consolidated = app(MonthlyClose::class)->consolidated($parent, $period);

    // A PIVOT dönt: az idegen fogyás NEM kerül a parent számlájára, és a neve sem szivárog.
    expect($consolidated['total']['sends_counter'])->toBe(10)
        ->and($consolidated['children'])->toBeEmpty()
        ->and(json_encode($consolidated))->not->toContain('Idegen-Titkos-Ceg');
});

it('a SOFT-DELETED workspace felfüggesztése FAIL-CLOSED', function () {
    /*
     | A MÉRT HIBA: a `Workspace` soft-deletes-es, a `belongsTo` reláció a trashed sort nem
     | hozza, és a null-ág CSENDBEN átengedte a küldést: felfüggesztett + soft-deleted
     | workspace → `status = delivered`. Pénzügyi kapunál a „nem tudom" nem lehet „engedem".
     */
    confirmed();

    app(Metering::class)->increment(UsageDimension::Sends, 1);

    $invoice = app(MonthlyClose::class)->close($this->workspace, app(Metering::class)->period());
    $invoice->forceFill(['dunning_state' => 'suspended', 'suspended_at' => now()])->save();

    $this->workspace->delete();

    $result = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@pelda.hu'],
        'subject' => 'Szia',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'torolt-workspace',
    ]);

    expect($result->decision->allowed)->toBeFalse();
});

it('a SUB-ACCOUNT a PARENT csomagját használja — workspace-re kulcsolt fake-kel mérve', function () {
    /*
     | A korábbi fake ELDOBTA a `$workspaceId`-t, tehát a `EntitlementChecker` egyetlen
     | létjogosultsága — „a sub-account a parent csomagját használja" — semmivel nem volt
     | bizonyítva: a teszt akkor is zöld lett volna, ha a `billableId()` bármilyen számot
     | ad vissza.
     */
    $parent = $this->workspace;
    $child = Workspace::factory()->create(['name' => 'Alszámla']);
    $parent->children()->attach($child, ['permissions' => '{}']);

    // A PARENT csomagjában van a journeys, a CHILD sajátjában NINCS.
    config(['sending.fake_entitlement.per_workspace' => [
        $parent->getKey() => ['journeys'],
        $child->getKey() => [],
    ]]);

    $checker = app(EntitlementChecker::class);

    expect($checker->allows($parent, Feature::Journeys))->toBeTrue()
        // A CHILD a PARENT csomagját kapja, nem a sajátját.
        ->and($checker->allows($child, Feature::Journeys))->toBeTrue();

    // …és ha a PARENT csomagjából kivesszük, a child is elveszíti.
    config(['sending.fake_entitlement.per_workspace' => [
        $parent->getKey() => [],
        $child->getKey() => ['journeys'],
    ]]);

    expect($checker->allows($child, Feature::Journeys))->toBeFalse();
});

it('a DUNNING-LÉPTETÉS AUDITÁLT, és az állapot LÁTSZIK a nyomban', function () {
    /*
     | KÉT mért hiba egy tesztben:
     |
     | 1. a `transition()` tömeges `update()`-et használ, ami NEM tüzel modell-eventet,
     |    tehát az `Auditable` nem futott: egy `open → due` léptetés után az audit-sorok
     |    száma VÁLTOZATLAN maradt;
     | 2. az `AuditLogger` REDACTED listájában szereplő `state` needle (az OAuth `state`-re
     |    szánva) BELETALÁLT a `dunning_state`-be, tehát a felfüggesztés ténye a VÁLTOZÁS
     |    ÉRTÉKE NÉLKÜL került be (`{"dunning_state": "[redacted]"}` előtte és utána is).
     |
     | A felfüggesztés a legdurvább eszköz — pont ennek kell nyomot hagynia (golden rule 11).
     */
    $period = app(Metering::class)->period();

    app(Metering::class)->increment(UsageDimension::Sends, 1);
    $invoice = app(MonthlyClose::class)->close($this->workspace, $period);

    config(['sending.fake_entitlement.unsettled' => [$period]]);

    $this->travelTo($invoice->due_at->copy()->addDay());

    $elotte = AuditLog::query()->count();

    app(Dunning::class)->advance();

    $auditok = AuditLog::query()->where('action', 'billing.dunning_transition')->get();

    expect(AuditLog::query()->count())->toBeGreaterThan($elotte)
        ->and($auditok)->not->toBeEmpty();

    // AZ ÁLLAPOT LÁTSZIK, nem `[redacted]`.
    expect(json_encode($auditok->first()->context))->toContain('due')
        ->and(json_encode($auditok->first()->context))->not->toContain('redacted');
});
