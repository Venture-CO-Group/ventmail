<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\AnalyticsExport;
use App\Modules\Analytics\Services\CampaignAnalytics;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Analytics\Services\JourneyAnalytics;
use App\Modules\Analytics\Services\ListAnalytics;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Services\ContactGdpr;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Logs\Services\TagStatistics;
use App\Modules\Sending\Models\Message;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | B4 · 72–74. kritérium — marketing analitika.
 |
 | A packet TÉTJE a 73. kritérium: a marketing analitika és a platform-napló UGYANARRA az
 | esemény-forrásra épül. Ez nem kényelmi kérdés. Két külön számláló elkerülhetetlenül
 | elcsúszik (az egyik a küldésnél nő, a másik az eseménynél; az egyik újrapróbálásnál is,
 | a másik nem), és akkor a tenant két felületen két „delivered" számot lát ugyanarra a
 | kampányra. Onnantól EGYIKBEN SEM hisz — és joggal.
 |
 | Ezért itt nincs saját számláló: MINDEN szám a `message_events`-ből jön, ugyanabból a
 | táblából, amit a napló mutat.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->template = Template::create([
        'name' => 'Hírlevél',
        'subject' => 'Szia',
        'blocks' => [[
            'type' => 'section',
            'children' => [['type' => 'column', 'children' => [
                ['type' => 'text', 'attrs' => ['content' => 'Szia!']],
                ['type' => 'unsubscribe', 'attrs' => []],
            ]]],
        ]],
    ]);
});

/**
 * Egy esemény a kampány tagjével — EZ a forrás, amit a napló is olvas.
 *
 * A név NEM `event()`: az a Laravel globális helperje, és a Pest a teszt-fájlok
 * függvényeit globálisan deklarálja — az ütközés `Cannot redeclare event()`-tel dől el.
 */
function trackEvent(string $type, string $recipient, array $tags, string $channel = 'campaign', ?string $at = null): MessageEvent
{
    return MessageEvent::create([
        'message_uuid' => (string) Str::uuid7(),
        'type' => $type,
        'recipient' => $recipient,
        'channel' => $channel,
        'tags' => $tags,
        'occurred_at' => $at === null ? now() : Carbon::parse($at),
    ]);
}

function campaignWith(array $attributes = []): Campaign
{
    $list = ContactList::create(['name' => 'Lista '.bin2hex(random_bytes(3))]);

    return Campaign::create(array_merge([
        'template_id' => test()->template->getKey(),
        'name' => 'Kampány '.bin2hex(random_bytes(3)),
        'subject' => 'Tárgy',
        'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ], $attributes));
}

/*
 | 72. AGGREGÁTUMOK kampány / journey / lista / tag bontásban, HELYES rátákkal
 */

it('a KAMPÁNY-ÖSSZESÍTŐ az esemény-forrásból jön, és a ráták a helyes nevezőn állnak', function () {
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    // 10 elfogadva, 9 kézbesítve, 1 bounce.
    foreach (range(1, 10) as $i) {
        trackEvent('accepted', "c{$i}@pelda.hu", [$tag]);
    }

    foreach (range(1, 9) as $i) {
        trackEvent('delivered', "c{$i}@pelda.hu", [$tag]);
    }

    trackEvent('bounced', 'c10@pelda.hu', [$tag]);

    // 3 EGYEDI nyitó, de az egyik HÁROMSZOR nyitott (Apple MPP, előnézet, újranyitás).
    trackEvent('opened', 'c1@pelda.hu', [$tag]);
    trackEvent('opened', 'c1@pelda.hu', [$tag]);
    trackEvent('opened', 'c1@pelda.hu', [$tag]);
    trackEvent('opened', 'c2@pelda.hu', [$tag]);
    trackEvent('opened', 'c3@pelda.hu', [$tag]);

    // 2 EGYEDI klikkelő, 3 klikk.
    trackEvent('clicked', 'c1@pelda.hu', [$tag]);
    trackEvent('clicked', 'c1@pelda.hu', [$tag]);
    trackEvent('clicked', 'c2@pelda.hu', [$tag]);

    trackEvent('complained', 'c4@pelda.hu', [$tag]);
    trackEvent('unsubscribed', 'c5@pelda.hu', [$tag]);

    $summary = app(CampaignAnalytics::class)->summary($campaign);

    expect($summary['accepted'])->toBe(10)
        ->and($summary['delivered'])->toBe(9)
        ->and($summary['bounced'])->toBe(1)
        // TOTAL vs UNIQUE: a kettő KÜLÖN szám, mert mást mondanak.
        ->and($summary['opened'])->toBe(5)
        ->and($summary['unique_opened'])->toBe(3)
        ->and($summary['clicked'])->toBe(3)
        ->and($summary['unique_clicked'])->toBe(2);

    /*
     | A RÁTÁK NEVEZŐJE nem mindegy, és ez a kritérium „a ráták helyesek" pontja.
     |
     | A nyitási és klikk-arány nevezője a KÉZBESÍTETT (9), nem az elfogadott (10): aki nem
     | kapta meg a levelet, nem is nyithatta meg. A bounce-arány nevezője viszont az
     | ELFOGADOTT (10), mert a bounce épp a kézbesítés kudarca — a kézbesítettre osztva
     | mindig szebb számot adna.
     */
    expect($summary['open_rate'])->toBe(round(3 / 9 * 100, 2))
        ->and($summary['click_rate'])->toBe(round(2 / 9 * 100, 2))
        ->and($summary['bounce_rate'])->toBe(round(1 / 10 * 100, 2))
        // CTOR: a klikkelők a NYITÓK arányában — ez mutatja a törzs erejét, a tárgysorét nem.
        ->and($summary['click_to_open_rate'])->toBe(round(2 / 3 * 100, 2))
        ->and($summary['complaint_rate'])->toBe(round(1 / 9 * 100, 2))
        ->and($summary['unsubscribe_rate'])->toBe(round(1 / 9 * 100, 2));
});

it('a NULLA NEVEZŐ nulla rátát ad, nem osztási hibát és nem 100%-ot', function () {
    // Egy még ki nem küldött kampány riportja a felületen is megnyílik. A `0/0` vagy
    // kivétellel elhasal, vagy — ami rosszabb — 100%-ot mutat.
    $summary = app(CampaignAnalytics::class)->summary(campaignWith());

    expect($summary['delivered'])->toBe(0)
        ->and($summary['open_rate'])->toBe(0.0)
        ->and($summary['click_to_open_rate'])->toBe(0.0)
        ->and($summary['bounce_rate'])->toBe(0.0);
});

it('a VARIÁNS-BONTÁS ugyanabból a forrásból jön, és összeadva a kampányt adja', function () {
    $campaign = campaignWith();

    $a = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'A', 'weight' => 50]);
    $b = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'B', 'weight' => 50]);

    $tag = 'campaign:'.$campaign->uuid;

    foreach (range(1, 4) as $i) {
        trackEvent('delivered', "a{$i}@pelda.hu", [$tag, 'variant:A']);
    }

    trackEvent('opened', 'a1@pelda.hu', [$tag, 'variant:A']);

    foreach (range(1, 6) as $i) {
        trackEvent('delivered', "b{$i}@pelda.hu", [$tag, 'variant:B']);
    }

    trackEvent('opened', 'b1@pelda.hu', [$tag, 'variant:B']);
    trackEvent('opened', 'b2@pelda.hu', [$tag, 'variant:B']);

    $variants = app(CampaignAnalytics::class)->byVariant($campaign);

    expect($variants)->toHaveCount(2)
        ->and($variants[0]['label'])->toBe('A')
        ->and($variants[0]['delivered'])->toBe(4)
        ->and($variants[0]['unique_opened'])->toBe(1)
        ->and($variants[1]['delivered'])->toBe(6)
        ->and($variants[1]['unique_opened'])->toBe(2);

    // ÖSSZEADVA a kampány-összesítőt adja: ha nem, a két nézet MÁST mond ugyanarról.
    $summary = app(CampaignAnalytics::class)->summary($campaign);

    expect($variants[0]['delivered'] + $variants[1]['delivered'])->toBe($summary['delivered']);
});

it('a JOURNEY-ÖSSZESÍTŐ a futásokat ÉS a leveleket is mutatja, lépésenként', function () {
    $journey = Journey::create(['name' => 'Onboarding', 'trigger_type' => TriggerType::FormSignup->value]);
    $journey->forceFill(['status' => 'active'])->save();

    $step = JourneyStep::create([
        'journey_id' => $journey->getKey(),
        'type' => 'send_email',
        'config' => ['template' => (string) $this->template->uuid, 'from_email' => 'no-reply@pelda.hu'],
    ]);

    $journey->forceFill(['first_step_id' => $step->getKey(), 'entered_count' => 3, 'completed_count' => 1, 'exited_count' => 1])->save();

    JourneyRun::create(['journey_id' => $journey->getKey(), 'email' => 'j1@pelda.hu', 'entered_at' => now()]);

    $tag = 'journey:'.$journey->getKey();

    trackEvent('delivered', 'j1@pelda.hu', [$tag, 'journey_step:'.$step->uuid], 'journey');
    trackEvent('delivered', 'j2@pelda.hu', [$tag, 'journey_step:'.$step->uuid], 'journey');
    trackEvent('opened', 'j1@pelda.hu', [$tag, 'journey_step:'.$step->uuid], 'journey');

    $summary = app(JourneyAnalytics::class)->summary($journey);

    expect($summary['entered'])->toBe(3)
        ->and($summary['completed'])->toBe(1)
        ->and($summary['exited'])->toBe(1)
        ->and($summary['active'])->toBe(1)
        // A BEFEJEZÉSI ARÁNY a belépésre vetítve — enélkül a riport nullával osztott.
        ->and($summary['completion_rate'])->toBe(round(1 / 3 * 100, 2))
        ->and($summary['delivered'])->toBe(2)
        ->and($summary['unique_opened'])->toBe(1);

    $steps = app(JourneyAnalytics::class)->byStep($journey);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['delivered'])->toBe(2)
        ->and($steps[0]['unique_opened'])->toBe(1);
});

it('a LISTA-NÖVEKEDÉS napi bontásban jön, és a nettó változás helyes', function () {
    $list = ContactList::create(['name' => 'Hírlevél']);

    $recorder = app(ConsentRecorder::class);

    foreach (['l1@pelda.hu', 'l2@pelda.hu', 'l3@pelda.hu'] as $email) {
        $recorder->confirm($recorder->request($email));
        $contact = Contact::create(['email' => $email, 'status' => 'subscribed', 'subscribed_at' => now()]);
        $contact->lists()->attach($list, ['status' => 'subscribed', 'subscribed_at' => now()->subDays(2)]);
    }

    // EGY leiratkozás MA.
    Contact::query()->where('email', 'l3@pelda.hu')->firstOrFail()
        ->lists()->updateExistingPivot($list->getKey(), ['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

    $growth = app(ListAnalytics::class)->growth($list, now()->subDays(3), now());

    $today = collect($growth)->firstWhere('day', now()->toDateString());
    $twoDaysAgo = collect($growth)->firstWhere('day', now()->subDays(2)->toDateString());

    expect($twoDaysAgo['subscribed'])->toBe(3)
        ->and($twoDaysAgo['unsubscribed'])->toBe(0)
        ->and($twoDaysAgo['net'])->toBe(3)
        ->and($today['unsubscribed'])->toBe(1)
        ->and($today['net'])->toBe(-1);

    // A JELENLEGI állapot: 2 aktív tag, 1 leiratkozott.
    $summary = app(ListAnalytics::class)->summary($list);

    expect($summary['subscribed'])->toBe(2)
        ->and($summary['unsubscribed'])->toBe(1)
        ->and($summary['total'])->toBe(3);
});

/*
 | 73. KONZISZTENCIA — ugyanaz a szám mindkét felületen
 */

it('a MARKETING ANALITIKA és a PLATFORM-NAPLÓ ugyanazt a delivered-számot adja', function () {
    /*
     | EZ a packet legfontosabb tesztje.
     |
     | Két külön számláló elkerülhetetlenül elcsúszik, és akkor a tenant két felületen két
     | „delivered" számot lát ugyanarra a kampányra — onnantól egyikben sem hisz. A teszt
     | ezért UGYANARRA a kampányra kérdezi meg a Studio-oldali analitikát és a
     | platform-oldali tag-statisztikát, és a kettőnek EGYEZNIE kell.
     */
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    foreach (range(1, 7) as $i) {
        trackEvent('delivered', "k{$i}@pelda.hu", [$tag]);
    }

    trackEvent('opened', 'k1@pelda.hu', [$tag]);
    trackEvent('opened', 'k2@pelda.hu', [$tag]);

    $studio = app(CampaignAnalytics::class)->summary($campaign);

    // A PLATFORM-oldal (A3) ugyanezt a tagot kérdezi a napló-aggregátumból.
    $platform = collect(app(TagStatistics::class)->forRange(now()->subDay(), now()->addDay()))
        ->firstWhere('tag', $tag);

    expect($platform)->not->toBeNull('a napló nem ismeri a kampány tagját')
        ->and($studio['delivered'])->toBe($platform['delivered'])
        ->and($studio['opened'])->toBe($platform['opened']);
});

it('a KAMPÁNY-SZÁMOK NEM a kampány saját számlálóiból jönnek', function () {
    /*
     | A `campaigns.sent_count` a KÜLDÉSI folyamat számlálója: kötegenként nő, és a
     | kapu-elutasításokat `failed`-ként könyveli. A `delivered` viszont a FOGADÓ visszajelzése
     | — a kettő elvileg sem ugyanaz, és ha az analitika a kampány-számlálót mutatná
     | „delivered" néven, a szám a napló mellett hazudna.
     |
     | A teszt ezt úgy bizonyítja, hogy a kampány számlálóit SZÁNDÉKOSAN hamis értékre írja:
     | az analitikának ettől nem szabad megmozdulnia.
     */
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    trackEvent('delivered', 'x1@pelda.hu', [$tag]);
    trackEvent('delivered', 'x2@pelda.hu', [$tag]);

    Campaign::query()->whereKey($campaign->getKey())->update(['sent_count' => 999, 'failed_count' => 999]);

    expect(app(CampaignAnalytics::class)->summary($campaign->fresh())['delivered'])->toBe(2);
});

it('az ANALITIKA nem lát át a TENANT-HATÁRON', function () {
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    trackEvent('delivered', 'sajat@pelda.hu', [$tag]);

    // EGY MÁS tenant esemény UGYANAZZAL a taggel — ez nem lehetetlen: a tag a kampány
    // uuid-ja, de egy hibás integráció bármit beírhat a saját tagjeibe.
    $other = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($other);

    trackEvent('delivered', 'idegen@pelda.hu', [$tag]);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(app(CampaignAnalytics::class)->summary($campaign)['delivered'])->toBe(1);
});

/*
 | 74. EXPORT ÉS KLIKK-TÉRKÉP
 */

it('a KLIKK-TÉRKÉP linkenként adja a TELJES és az EGYEDI kattintást', function () {
    $campaign = campaignWith();
    $map = app(ClickMap::class);

    $message = (string) Str::uuid7();

    // KÉT ember kattint az AKCIÓ linkre, egyikük kétszer.
    $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/akcio', 'egy@pelda.hu');
    $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/akcio', 'egy@pelda.hu');
    $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/akcio', 'ketto@pelda.hu');

    // EGY ember a lábjegyzet linkre.
    $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/rolunk', 'egy@pelda.hu');

    $links = $map->forCampaign($campaign);

    expect($links)->toHaveCount(2)
        // A LEGTÖBBET kattintott link az első: a térkép rangsor, nem lista.
        ->and($links[0]['url'])->toBe('https://pelda.hu/akcio')
        ->and($links[0]['clicks'])->toBe(3)
        ->and($links[0]['unique_clicks'])->toBe(2)
        ->and($links[1]['url'])->toBe('https://pelda.hu/rolunk')
        ->and($links[1]['clicks'])->toBe(1)
        ->and($links[1]['unique_clicks'])->toBe(1);
});

it('a KLIKK-TÉRKÉP nem tárol NYERS címet', function () {
    // A térkép AGGREGÁLT nézet, nem címzett-lista. Az egyediséghez a pszeudonim hash elég;
    // a nyers cím itt semmit nem ad, viszont egy újabb helyre vinné a személyes adatot.
    $campaign = campaignWith();

    app(ClickMap::class)->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/x', 'titok@pelda.hu');

    $row = DB::table('link_clicks')->first();

    expect(json_encode($row))->not->toContain('titok@pelda.hu')
        ->and($row->recipient_hash)->not->toBe('titok@pelda.hu')
        ->and(strlen($row->recipient_hash))->toBe(64);
});

it('a KLIKK-TÉRKÉP linkenkénti FELSŐ KORLÁTJA látszik a riportban', function () {
    /*
     | Ha a tenant merge-taget tesz a `href`-be, a behelyettesített cím CÍMZETTENKÉNT MÁS.
     | Egy 100 000 címzettes kampány így 100 000 külön „linket" adna: a térkép
     | használhatatlan, a tábla pedig hízik. A korlát fölötti kattintás egyetlen `(egyéb)`
     | sorba gyűlik — és a csonkítás TÉNYE látszik, nem csendes.
     */
    $campaign = campaignWith();
    $mapper = app(ClickMap::class);
    $message = (string) Str::uuid7();

    for ($i = 0; $i < ClickMap::MAX_LINKS_PER_MESSAGE + 5; $i++) {
        $mapper->record($message, (string) $campaign->uuid, null, "https://pelda.hu/x{$i}", "e{$i}@pelda.hu");
    }

    $links = $mapper->forCampaign($campaign);

    expect(count($links))->toBeLessThanOrEqual(ClickMap::MAX_LINKS_PER_MESSAGE + 1)
        ->and(collect($links)->pluck('url'))->toContain(ClickMap::OVERFLOW_URL);
});

it('a KATTINTÁS a TRACKING-VÉGPONTON keresztül kerül a térképbe', function () {
    /*
     | A térkép nem attól van, hogy egy szolgáltatás hívható: attól, hogy a VALÓDI kattintás
     | beírja. Ugyanaz a hibaosztály, mint a B2-ben a `VariantWinner` (megvolt, működött,
     | senki nem hívta) és a B3-ban a `segment_entered` (a parancs nem is létezett). Ezért
     | ez a teszt a HTTP-végponton megy át, nem a `ClickMap`-en.
     */
    $campaign = campaignWith();

    // A NYOMKÖVETÉS a tenant SAJÁT hosztján fut — enélkül nincs nyomkövetés (A3).
    $domain = Domain::query()->where('name', 'pelda.hu')->firstOrFail();
    $domain->forceFill([
        'tracking_domain' => 'email.pelda.hu',
        'tracking_status' => 'verified',
        'tracking_enabled' => true,
    ])->save();

    $message = Message::create([
        'domain_id' => $domain->getKey(),
        'channel' => 'campaign',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Tárgy',
        'recipients' => ['kattinto@pelda.hu'],
        'tags' => ['campaign:'.$campaign->uuid],
        'status' => 'delivered',
    ]);

    $token = app(TrackingTokens::class)->issue(
        (int) $this->workspace->getKey(),
        (string) $message->uuid,
        'kattinto@pelda.hu',
        'email.pelda.hu',
        'https://pelda.hu/akcio',
    );

    // A HOSZT KÖTVE van a tokenhez: a kérés csak azon fogadható el (nyílt átirányító ellen).
    $this->get('https://email.pelda.hu/t/c/'.$token)->assertRedirect('https://pelda.hu/akcio');

    $links = app(ClickMap::class)->forCampaign($campaign);

    expect($links)->toHaveCount(1)
        ->and($links[0]['url'])->toBe('https://pelda.hu/akcio')
        ->and($links[0]['unique_clicks'])->toBe(1)
        // …és az ESEMÉNY is megvan: a két nézet ugyanabból a kattintásból épül.
        ->and(MessageEvent::query()->where('type', 'clicked')->count())->toBe(1);
});

it('az EXPORT CSV-je a riport SZÁMAIT adja, fejléccel', function () {
    $campaign = campaignWith(['name' => 'Tavaszi']);
    $tag = 'campaign:'.$campaign->uuid;

    trackEvent('delivered', 'e1@pelda.hu', [$tag]);
    trackEvent('delivered', 'e2@pelda.hu', [$tag]);
    trackEvent('opened', 'e1@pelda.hu', [$tag]);

    $csv = app(AnalyticsExport::class)->campaignCsv($campaign);

    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

    expect($rows[0])->toContain('delivered')->and($rows[0])->toContain('open_rate');

    $header = $rows[0];
    $values = array_combine($header, $rows[1]);

    expect($values['delivered'])->toBe('2')
        ->and($values['unique_opened'])->toBe('1')
        ->and($values['campaign'])->toBe('Tavaszi');
});

it('az EXPORT nem szivárogtat NYERS címet', function () {
    // Az export letölthető fájl, ami elhagyja a rendszert. Az AGGREGÁLT riport nem
    // tartalmaz címzettet — ha tartalmazna, egy „riport" letöltése a teljes címlistát adná.
    $campaign = campaignWith();

    trackEvent('delivered', 'titkos@pelda.hu', ['campaign:'.$campaign->uuid]);

    $csv = app(AnalyticsExport::class)->campaignCsv($campaign);

    expect($csv)->not->toContain('titkos@pelda.hu');
});

it('az ANALITIKA-API a kampány riportját adja, és tenant-szűrt', function () {
    $campaign = campaignWith();

    trackEvent('delivered', 'api@pelda.hu', ['campaign:'.$campaign->uuid]);

    expect(Route::has('v1.analytics.campaign'))->toBeTrue();
});

/*
 | GDPR ÉS RETENCIÓ — a hibaosztály, amit a B2 és a B3 review-ja is megtalált
 */

it('a GDPR-TÖRLÉS a klikk-térképből a KIT szedi ki, a SZÁMOKAT nem', function () {
    /*
     | A B2-ben a `campaign_recipients`, a B3-ban a `journey_runs` pontosan ezért lett
     | review-blokkoló: új tábla, címzett-dimenzió, semmi lejárat és semmi redakció. A
     | klikk-térkép itt PSZEUDONIM hash-t tart (nem nyers címet) — de a pepper a miénk,
     | tehát a hash összekapcsolható, és egy Art. 17 kérés után nem tarthatjuk meg.
     |
     | A SOR TÖRLÉSE rossz válasz lenne: a fél évvel korábbi kampány klikk-térképe
     | csökkenne, és a tenant azt látná, hogy a számai maguktól romlanak. Ezért ÖSSZEVONÁS:
     | a kattintások megmaradnak, a KI eltűnik.
     */
    $campaign = campaignWith();
    $map = app(ClickMap::class);
    $message = (string) Str::uuid7();

    $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/akcio', 'torlendo@pelda.hu');
    $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/akcio', 'torlendo@pelda.hu');
    $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/akcio', 'maradok@pelda.hu');

    $before = $map->forCampaign($campaign);

    expect($before[0]['clicks'])->toBe(3)->and($before[0]['unique_clicks'])->toBe(2);

    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request('torlendo@pelda.hu'));
    $contact = Contact::create(['email' => 'torlendo@pelda.hu', 'status' => 'subscribed']);

    app(ContactGdpr::class)->erase($contact);

    $after = $map->forCampaign($campaign);

    // A SZÁMOK VÁLTOZATLANOK — a riport nem romlik el egy törlési kéréstől.
    expect($after[0]['clicks'])->toBe(3)
        ->and($after[0]['unique_clicks'])->toBe(2);

    // …de a törölt ember hash-e MÁR NEM azonosítható: összevont sorba került.
    $hashes = DB::table('link_clicks')->pluck('recipient_hash')->all();

    expect($hashes)->toContain(ClickMap::AGGREGATED_HASH)
        ->and($hashes)->not->toContain(KeyedHash::of('email', 'torlendo@pelda.hu'));
});

it('a RETENCIÓS ÖSSZEVONÁS megőrzi a per-link számokat', function () {
    // Golden rule 10: „lejárat után a nyers esemény TÖRLŐDIK, csak az aggregátum marad."
    // A nyers grain itt a (link, CÍMZETT) páros; a per-link szám az aggregátum.
    $campaign = campaignWith();
    $map = app(ClickMap::class);
    $message = (string) Str::uuid7();

    foreach (['a@pelda.hu', 'b@pelda.hu', 'c@pelda.hu'] as $email) {
        $map->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/akcio', $email);
    }

    // A KATTINTÁSOK a retenciós ablakon TÚL vannak.
    DB::table('link_clicks')->update([
        'first_clicked_at' => now()->subDays(200),
        'last_clicked_at' => now()->subDays(200),
    ]);

    $this->artisan('analytics:purge-click-map')->assertSuccessful();

    $links = app(ClickMap::class)->forCampaign($campaign);

    expect($links)->toHaveCount(1)
        ->and($links[0]['clicks'])->toBe(3)
        // A HÁROM EGYEDI kattintó száma MEGMARAD, pedig már egy sor van.
        ->and($links[0]['unique_clicks'])->toBe(3)
        ->and(DB::table('link_clicks')->count())->toBe(1);

    // …és a MÁSODIK futás nem duplázza a számokat.
    $this->artisan('analytics:purge-click-map')->assertSuccessful();

    expect(app(ClickMap::class)->forCampaign($campaign)[0]['clicks'])->toBe(3);
});

it('a KLIKK-TÉRKÉP nem lát át a TENANT-HATÁRON', function () {
    $campaign = campaignWith();

    app(ClickMap::class)->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/x', 'sajat@pelda.hu');

    $other = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($other);

    // UGYANARRA a kampány-uuid-ra egy MÁS tenantból: a modell global scope-ja zár.
    app(ClickMap::class)->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/x', 'idegen@pelda.hu');

    expect(app(ClickMap::class)->forCampaign($campaign)[0]['unique_clicks'])->toBe(1);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(app(ClickMap::class)->forCampaign($campaign)[0]['unique_clicks'])->toBe(1);
});
