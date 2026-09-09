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
use App\Modules\Identity\Models\ApiKey;
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

    // KÜLÖN üzenetek: a kampány címzettenként külön `messages` sort küld, és a korlátnak
    // ezért KAMPÁNY-szinten kell fognia, nem üzenet-szinten.
    for ($i = 0; $i < ClickMap::MAX_LINKS_PER_SCOPE + 5; $i++) {
        $mapper->record((string) Str::uuid7(), (string) $campaign->uuid, null, "https://pelda.hu/x{$i}", "e{$i}@pelda.hu");
    }

    $links = $mapper->forCampaign($campaign);

    expect(count($links))->toBeLessThanOrEqual(ClickMap::MAX_LINKS_PER_SCOPE + 1)
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

it('az ANALITIKA-API VALÓDI HTTP-n működik, és a scope-ot ÉRVÉNYESÍTI', function () {
    /*
     | Ez a teszt korábban csak `expect(Route::has(...))->toBeTrue()` volt — egy állítás,
     | ami nem tud elhasalni. A review emiatt mérte meg, hogy a végpont VALÓDI kéréssel
     | 401-et ad érvényes `analytics:read` kulccsal is: a modul-provider nem állította a
     | `routesPrecedeAuthentication`-t, tehát a böngésző-session `auth` middleware került
     | az `api.auth` ELÉ. A 74. kritérium API-fele nem működött, miközben az OpenAPI
     | működőként dokumentálta.
     |
     | Egy route-jelenlét-állítás nem teszt. Ez az.
     */
    $campaign = campaignWith(['name' => 'Riportált']);

    trackEvent('delivered', 'api@pelda.hu', ['campaign:'.$campaign->uuid]);
    trackEvent('opened', 'api@pelda.hu', ['campaign:'.$campaign->uuid]);

    ['plain' => $olvaso] = ApiKey::issue('Riport', ['analytics:read']);

    $response = $this->withHeader('Authorization', 'Bearer '.$olvaso)
        ->getJson('/v1/analytics/campaigns/'.$campaign->uuid);

    $response->assertOk()
        ->assertJsonPath('summary.delivered', 1)
        ->assertJsonPath('summary.unique_opened', 1)
        ->assertJsonPath('summary.campaign', 'Riportált');

    // AZ AGGREGÁLT válasz egyetlen címzett-címet sem tartalmaz.
    expect($response->getContent())->not->toContain('api@pelda.hu');

    // MÁS SCOPE nem nyitja: a marketing-riport és a napló két különböző jogosultság.
    ['plain' => $naplo] = ApiKey::issue('Napló', ['logs:read', 'events:read']);

    $this->withHeader('Authorization', 'Bearer '.$naplo)
        ->getJson('/v1/analytics/campaigns/'.$campaign->uuid)
        ->assertForbidden();

    // KULCS NÉLKÜL sem: az `api.auth` a hiányzó kulcsot 403-cal utasítja el (nincs
    // `WWW-Authenticate` kihívás, mert nem böngésző-hitelesítés).
    $this->getJson('/v1/analytics/campaigns/'.$campaign->uuid)->assertForbidden();
});

it('az ANALITIKA-API más TENANT kampányára 404-et ad, nem adatot', function () {
    // 404, nem 403: egy „létezik, de nem a tiéd" válasz megmondaná, hogy az uuid valós — a
    // riport-végpont nem lehet létezés-orákulum más tenantok kampányaira.
    $other = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($other);

    Domain::create(['name' => 'masik.hu', 'status' => 'verified', 'verified_at' => now()]);

    $idegenSablon = Template::create([
        'name' => 'Idegen',
        'blocks' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'x']],
            ['type' => 'unsubscribe', 'attrs' => []],
        ]]]]],
    ]);

    $idegen = Campaign::create([
        'template_id' => $idegenSablon->getKey(),
        'name' => 'Idegen kampány',
        'subject' => 'Tárgy',
        'from_email' => 'no-reply@masik.hu',
        'target_lists' => [],
    ]);

    app(WorkspaceContext::class)->set($this->workspace);

    ['plain' => $kulcs] = ApiKey::issue('Riport', ['analytics:read']);

    $this->withHeader('Authorization', 'Bearer '.$kulcs)
        ->getJson('/v1/analytics/campaigns/'.$idegen->uuid)
        ->assertNotFound();
});

it('az ANALITIKA-API CSV-t ad, és a SZAKASZOK mind elérhetők', function () {
    $campaign = campaignWith();

    $variant = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'A', 'weight' => 100]);

    trackEvent('delivered', 'csv@pelda.hu', ['campaign:'.$campaign->uuid, 'variant:A']);

    app(ClickMap::class)->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/akcio', 'csv@pelda.hu');

    ['plain' => $kulcs] = ApiKey::issue('Riport', ['analytics:read']);

    foreach (['summary' => 'delivered', 'variants' => 'label', 'clicks' => 'unique_clicks'] as $section => $fejlec) {
        $response = $this->withHeader('Authorization', 'Bearer '.$kulcs)
            ->get('/v1/analytics/campaigns/'.$campaign->uuid.'?format=csv&section='.$section);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // A `toContain` a további argumentumokat KERESETT ÉRTÉKKÉNT érti, nem üzenetként
        // (ezt a B2 tesztfájlja már megtanulta) — ezért itt nincs üzenet-paraméter.
        expect($response->getContent())->toContain($fejlec);
    }

    // ISMERETLEN szakasz: 404, nem csendes visszaesés az összesítőre.
    $this->withHeader('Authorization', 'Bearer '.$kulcs)
        ->get('/v1/analytics/campaigns/'.$campaign->uuid.'?format=csv&section=talalgatas')
        ->assertNotFound();
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

/*
 | A REVIEW-BAN MÉRT HIBÁK regressziós tesztjei (reviewer + security).
 */

it('a RIPORT TÚLÉLI a nyers események RETENCIÓJÁT — a 73. kritérium nem szűnik meg', function () {
    /*
     | EZ a packet legfontosabb regressziós tesztje.
     |
     | A riport először a NYERS `message_events`-ből számolt, amin 90 napos retenció van
     | (`add_retention_policy`). A review ezt megmérte egy 100 napos kampányon, `drop_chunks`
     | után:
     |
     |     Studio-riport:   delivered 0,  opened 0,  open_rate 0%
     |     Platform-napló:  delivered 50, opened 20, open_rate 40%
     |
     | Ugyanaz a kampány, KÉT felület, KÉT szám — pontosan az, amit a 73. kritérium tilt, és
     | amit a `TagStatistics` docblockja előre kimondott: „aki ezt a szolgáltatást
     | megkerüli, az egy önmagát törlő statisztikát épít".
     |
     | A teszt a retenciót a nyers sorok TÖRLÉSÉVEL szimulálja (a `drop_chunks` ugyanezt
     | teszi, csak chunk-szinten): a ténytábla ettől érintetlen, tehát a riport áll.
     */
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    foreach (range(1, 5) as $i) {
        trackEvent('delivered', "r{$i}@pelda.hu", [$tag]);
    }

    trackEvent('opened', 'r1@pelda.hu', [$tag]);
    trackEvent('opened', 'r2@pelda.hu', [$tag]);

    $before = app(CampaignAnalytics::class)->summary($campaign);

    expect($before['delivered'])->toBe(5)->and($before['unique_opened'])->toBe(2);

    // A RETENCIÓ: a nyers események eltűnnek.
    DB::table('message_events')->delete();

    expect(MessageEvent::query()->count())->toBe(0);

    $after = app(CampaignAnalytics::class)->summary($campaign);

    // A RIPORT VÁLTOZATLAN — a ténytábla túlélte.
    expect($after['delivered'])->toBe(5)
        ->and($after['unique_opened'])->toBe(2)
        ->and($after['open_rate'])->toBe($before['open_rate']);
});

it('a CTOR nem megy 100% FÖLÉ kép-blokkoló kliensnél', function () {
    /*
     | MÉRVE 266,67%: 10 kézbesítve / 3 nyitva / 8 kattintva. Ez nem elméleti bemenet —
     | kép-blokkoló kliensben (Outlook desktop, céges gateway, plain-text) a nyomkövető
     | pixel NEM tölt be, a link viszont kattintható, tehát TÖBB kattintó van, mint nyitó.
     |
     | A kattintás BIZONYÍTJA a megnyitást, ezért a CTOR nevezője a két halmaz UNIÓJA.
     */
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    foreach (range(1, 10) as $i) {
        trackEvent('delivered', "c{$i}@pelda.hu", [$tag]);
    }

    // HÁROM nyitó…
    foreach (range(1, 3) as $i) {
        trackEvent('opened', "c{$i}@pelda.hu", [$tag]);
    }

    // …és NYOLC kattintó, köztük öt olyan, aki nem „nyitott".
    foreach (range(1, 8) as $i) {
        trackEvent('clicked', "c{$i}@pelda.hu", [$tag]);
    }

    $summary = app(CampaignAnalytics::class)->summary($campaign);

    expect($summary['unique_opened'])->toBe(3)
        ->and($summary['unique_clicked'])->toBe(8)
        // AZ ELÉRT közönség a két halmaz uniója: 8 (a három nyitó mind kattintott is).
        ->and($summary['engaged'])->toBe(8)
        ->and($summary['click_to_open_rate'])->toBe(100.0)
        ->and($summary['click_to_open_rate'])->toBeLessThanOrEqual(100.0);
});

it('a KÉTSZER írt `accepted` nem felezi a BOUNCE-ARÁNYT', function () {
    /*
     | A MÉRT HIBA: az éles driver-úton címzettenként KÉTSZER keletkezik `accepted` — a
     | `SendMessage` a kapun átjutáskor, a `MessageDispatcher` a driver válaszán (a
     | `PostalDriver` `DispatchResult::accepted()`-et ad, nem `delivered()`-et). A
     | `bounce_rate = bounced / accepted` így 1 küldés / 1 bounce esetén 50%-ot mutatott
     | 100% helyett.
     |
     | A bounce-arány a kézbesíthetőségi megfelelés száma (spam-ráta < 0,3%) — a `Rates`
     | docblockja szerint „nem lehet kozmetikázva". Ezért a riport CÍMZETTET számol, nem
     | eseményt: két `accepted` egy címzettre továbbra is EGY.
     |
     | A tesztkörnyezetben ez azért nem látszott, mert a `FakeDriver` `delivered()`-et ad.
     */
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    // UGYANARRA a címzettre KÉT `accepted` — ahogy az éles út teszi.
    trackEvent('accepted', 'egy@pelda.hu', [$tag]);
    trackEvent('accepted', 'egy@pelda.hu', [$tag]);
    trackEvent('bounced', 'egy@pelda.hu', [$tag]);

    $summary = app(CampaignAnalytics::class)->summary($campaign);

    expect($summary['accepted'])->toBe(1)
        ->and($summary['bounced'])->toBe(1)
        ->and($summary['bounce_rate'])->toBe(100.0);
});

it('a KAPU ELUTASÍTÁSA látszik a riportban', function () {
    // Korábban a `recipients: 100000` mellett `accepted: 60000` állt, MAGYARÁZAT NÉLKÜL: a
    // tenant nem látta, hogy a listája 40%-a ki sem ment. A listahigiénia romlása a
    // kézbesíthetőségi probléma ELSŐ jele.
    $campaign = campaignWith();
    $tag = 'campaign:'.$campaign->uuid;

    foreach (range(1, 6) as $i) {
        trackEvent('accepted', "ok{$i}@pelda.hu", [$tag]);
    }

    foreach (range(1, 4) as $i) {
        trackEvent('rejected', "nem{$i}@pelda.hu", [$tag]);
    }

    $summary = app(CampaignAnalytics::class)->summary($campaign);

    expect($summary['rejected'])->toBe(4)
        ->and($summary['accepted'])->toBe(6)
        // A NEVEZŐ a megkísérelt küldés (elutasított + elfogadott), nem a célzás mérete.
        ->and($summary['rejection_rate'])->toBe(40.0);
});

it('a SZEMÉLYRE SZABOTT LINK nem visz PII-t a klikk-térképbe', function () {
    /*
     | A MÉRT HIBA: a `link_clicks.url` NYERSEN tárolta a behelyettesített címet, tehát egy
     | merge-tagelt `href` személyes adatot vitt a táblába — amit sem az Art. 17 törlés, sem
     | a retenció nem szedett ki (mindkettő csak a `recipient_hash`-t vonja össze), és amit
     | a CSV-export meg az API-válasz kiadott. Az OpenAPI ugyanerről a végpontról azt
     | állítja: „a válasz aggregált: egyetlen címzett-címet sem tartalmaz."
     |
     | Ez EGYBEN a soklinkes hasadást is javítja: a személyre szabott linkek
     | címzettenként más `url_hash`-t adtak, tehát 300 címzett 300 soros térképet
     | eredményezett.
     */
    $campaign = campaignWith();
    $map = app(ClickMap::class);

    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null,
        'https://pelda.hu/r?email=titkos@pelda.hu&nev=Anna+Kiss&utm_source=hirlevel', 'titkos@pelda.hu');

    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null,
        'https://pelda.hu/r?email=masik@pelda.hu&nev=Bela+Nagy&utm_source=hirlevel', 'masik@pelda.hu');

    $links = $map->forCampaign($campaign);

    // EGY sor: a személyre szabott paraméterek kiestek, tehát a két kattintás UGYANARRA a
    // linkre esik — a térkép nem hasad szét címzettenként.
    expect($links)->toHaveCount(1)
        ->and($links[0]['unique_clicks'])->toBe(2)
        ->and($links[0]['url'])->not->toContain('titkos@pelda.hu')
        ->and($links[0]['url'])->not->toContain('Anna+Kiss')
        // …de a KAMPÁNY-MÉRŐ paraméter megmarad: az a térkép értelmes dimenziója.
        ->and($links[0]['url'])->toContain('utm_source=hirlevel');

    // A TÁBLÁBAN sem, és az EXPORTBAN sem.
    expect(json_encode(DB::table('link_clicks')->get()))->not->toContain('titkos@pelda.hu')
        ->and(app(AnalyticsExport::class)->clickMapCsv($campaign))
        ->not->toContain('titkos@pelda.hu');
});

it('a KLIKK-TÉRKÉP a HOSZTOT kisbetűsíti, és a záró `/`-t csak az ÚTVONALON vágja', function () {
    /*
     | MÉRVE, a `rtrim($url, '/')` a TELJES URL-re futott:
     |   KÉT KÜLÖNBÖZŐ cél EGY sorba: `?next=https://a.hu/` + `?next=https://a.hu`
     |   KÉT AZONOS cél KETTŐBE:      `https://PELDA.hu/akcio` + `https://pelda.hu/akcio`
     | Az elsőnél a tárolt cím olyan lett, ami sosem volt a levélben; a másodiknál a
     | rangsor hasadt — pontosan az, amit a docblock „a térkép hazudik a rangsorról"-ként ír le.
     */
    $campaign = campaignWith();
    $map = app(ClickMap::class);

    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://PELDA.hu/akcio', 'a@pelda.hu');
    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/akcio/', 'b@pelda.hu');
    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, 'https://pelda.hu/akcio#reszletek', 'c@pelda.hu');

    $links = $map->forCampaign($campaign);

    // MIND A HÁROM ugyanaz a cél: egy sor, három egyedi kattintó.
    expect($links)->toHaveCount(1)
        ->and($links[0]['url'])->toBe('https://pelda.hu/akcio')
        ->and($links[0]['unique_clicks'])->toBe(3);
});

it('a KLIKK-TÉRKÉP UPSERTJE nem lép át a TENANT-HATÁRON', function () {
    /*
     | A MÉRT HIBA: az egyedi index `(message_uuid, url_hash, recipient_hash)` volt,
     | `workspace_id` NÉLKÜL, és az `upsert` INSERT — tehát a global scope nem hat rá. A B
     | tenant kattintása az A tenant sorának számlálóját növelte (`clicks` 2 → 3), miközben
     | B-nél nem jelent meg semmi.
     */
    $campaign = campaignWith();
    $message = (string) Str::uuid7();

    app(ClickMap::class)->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/x', 'ugyanaz@pelda.hu');
    app(ClickMap::class)->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/x', 'ugyanaz@pelda.hu');

    expect(app(ClickMap::class)->forCampaign($campaign)[0]['clicks'])->toBe(2);

    // UGYANAZ az (üzenet, link, címzett) hármas EGY MÁS tenantból.
    $other = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($other);

    app(ClickMap::class)->record($message, (string) $campaign->uuid, null, 'https://pelda.hu/x', 'ugyanaz@pelda.hu');

    app(WorkspaceContext::class)->set($this->workspace);

    // AZ A TENANT SORA ÉRINTETLEN: a másik tenant SAJÁT sort kapott.
    expect(app(ClickMap::class)->forCampaign($campaign)[0]['clicks'])->toBe(2)
        ->and(DB::table('link_clicks')->count())->toBe(2);
});

it('a CSV-EXPORT nem ad FORMULÁT a táblázatkezelőnek', function () {
    /*
     | A MÉRT HIBA: az `fputcsv` idézőjelezése CSV-SZINTŰ — a táblázatkezelő a beolvasáskor
     | leszedi, és a `=`-kezdetű cellát KÉPLETKÉNT értékeli. A DDE-változat
     | parancsvégrehajtás az EXPORTÁLÓ gépén.
     |
     | A repóban ugyanez a védelem már létezett (`ValidationController::csvSafe`) saját
     | docblockkal — ez a modul nem hívta. Sub-account felállásban a child adja a kampány
     | nevét, a parent nyitja meg a riportot.
     */
    $campaign = campaignWith(['name' => "=cmd|'/c calc'!A0"]);

    trackEvent('delivered', 'egy@pelda.hu', ['campaign:'.$campaign->uuid]);

    $csv = app(AnalyticsExport::class)->campaignCsv($campaign);

    // A NÉV megmarad OLVASHATÓNAK, de aposztróf-prefixszel: a táblázatkezelőnek ez „szöveg".
    expect($csv)->toContain("'=cmd")
        ->and($csv)->not->toMatch('/(^|,)"?=cmd/');
});
