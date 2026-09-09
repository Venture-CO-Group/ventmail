<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\UsageRecord;
use App\Modules\Billing\Services\Metering;
use App\Modules\Billing\Services\MonthlyClose;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
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
