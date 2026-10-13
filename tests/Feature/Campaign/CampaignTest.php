<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Actions\StartCampaign;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Events\CampaignProgressed;
use App\Modules\Campaign\Jobs\SendCampaignChunk;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Campaign\Services\AudienceResolver;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\VariantAssigner;
use App\Modules\Campaign\Services\VariantWinner;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Data\SendQueue;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Models\Message;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | B2 · 62–66. kritérium — kampány-életciklus, célzás, A/B, ütemezés, sor-prioritás.
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->template = Template::create([
        'name' => 'Hírlevél',
        'blocks' => [[
            'type' => 'section',
            'children' => [['type' => 'column', 'children' => [
                ['type' => 'text', 'attrs' => ['content' => 'Szia {{ nev || Vásárló }}!']],
                ['type' => 'unsubscribe', 'attrs' => []],
            ]]],
        ]],
    ]);
});

/** Megerősített consenttel ellátott, listára tett kontakt. */
function subscriber(string $email, ?ContactList $list = null): Contact
{
    $recorder = app(ConsentRecorder::class);
    $recorder->confirm($recorder->request($email));

    $contact = Contact::create(['email' => $email, 'status' => 'subscribed', 'subscribed_at' => now()]);

    if ($list !== null) {
        $contact->lists()->attach($list, ['status' => 'subscribed', 'subscribed_at' => now()]);
    }

    return $contact;
}

function campaignFor(ContactList $list, array $extra = []): Campaign
{
    return Campaign::create(array_merge([
        'template_id' => test()->template->getKey(),
        'name' => 'Tavaszi kampány',
        'subject' => 'Tavaszi ajánlat',
        'from_email' => 'no-reply@pelda.hu',
        'target_lists' => [(string) $list->uuid],
    ], $extra));
}

/*
 | 62. ÉLETCIKLUS
 */

it('az ÁLLAPOTÁTMENETEK érvényesítve vannak — a tiltott KIVÉTEL', function () {
    // Egy állapotgép, ami csak dokumentációban létezik, néhány hónap alatt szétcsúszik:
    // valaki „csak most egyszer" átállítja a `sent`-et `sending`-re, és a kampány újra
    // kimegy mindenkinek.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    $campaign = campaignFor($list);
    $lifecycle = app(CampaignLifecycle::class);

    expect($campaign->status)->toBe('draft');

    // A metódus HELYI faliórát vár a megadott zónában — ezért a helyi „most + 1 óra".
    $campaign = $lifecycle->schedule(
        $campaign,
        now('Europe/Budapest')->addHour()->toDateTimeString(),
        'Europe/Budapest',
    );
    expect($campaign->status)->toBe('scheduled');

    $campaign = $lifecycle->unschedule($campaign);
    expect($campaign->status)->toBe('draft');
    expect($campaign->scheduled_at)->toBeNull();

    $campaign = $lifecycle->start($campaign);
    $campaign = $lifecycle->pause($campaign);
    expect($campaign->status)->toBe('paused');

    // A FOLYTATÁS, ha NINCS már kinek küldeni, BEFEJEZ — nem `sending`-be visz.
    //
    // Enélkül egy szüneteltetett, majd folytatott kampány örökre `sending` maradna, és a
    // felületen „küldés folyamatban"-t mutatna anélkül, hogy bármi történne. (Ez a kampány
    // nem materializált címzetteket, mert az állapotgépet önmagában járjuk végig.)
    $campaign = $lifecycle->resume($campaign);
    expect($campaign->status)->toBe('sent');

    // A KIKÜLDÖTT VÉGLEGES: a levél a címzettek postafiókjában van. Aki javítani akar, új
    // kampányt indít.
    expect(fn () => $lifecycle->start($campaign))->toThrow(RuntimeException::class);
    expect(fn () => $lifecycle->pause($campaign))->toThrow(RuntimeException::class);
});

it('a FOLYTATÁS folytat, ha van még kinek küldeni', function () {
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);
    subscriber('bela@pelda.hu', $list);

    Bus::fake();

    $lifecycle = app(CampaignLifecycle::class);

    $campaign = app(StartCampaign::class)(campaignFor($list));
    $campaign = $lifecycle->pause($campaign);

    // A `pending` sorok érintetlenek: a folytatás pontosan onnan veszi fel.
    expect($campaign->recipients()->where('status', 'pending')->count())->toBe(2);

    expect($lifecycle->resume($campaign)->status)->toBe('sending');
});

it('a VISSZAVONT kampány nem éled újra — a szüneteltetett igen', function () {
    // A kettő szándékosan KÜLÖN állapot: a „szüneteltetem, mert rossz a tárgy" és a
    // „leállítom, mert nem kell" két különböző döntés.
    expect(CampaignStatus::Paused->canTransitionTo(CampaignStatus::Sending))->toBeTrue();
    expect(CampaignStatus::Canceled->canTransitionTo(CampaignStatus::Sending))->toBeFalse();
    expect(CampaignStatus::Sent->isTerminal())->toBeTrue();
});

it('a HIÁNYOS kampány nem indítható, és a hiány MEGNEVEZETT', function () {
    // Az „elfelejtett feltétel" a legdrágább hiba egy kampánynál: egy tárgy nélküli levél a
    // TELJES közönségnek megy ki.
    $campaign = Campaign::create(['name' => 'Hiányos']);

    expect(fn () => app(CampaignLifecycle::class)->start($campaign))
        ->toThrow(InvalidArgumentException::class, 'sablon');
});

it('a KÜLDÉS KÖZBEN a célzás már nem szerkeszthető', function () {
    // Küldés közben a célzás átírása azt jelentené, hogy a kampány egyik fele az egyik
    // közönségnek ment, a másik fele a másiknak — és a riport egyikről sem mondana igazat.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    $campaign = app(CampaignLifecycle::class)->start(campaignFor($list));

    expect(fn () => $campaign->update(['target_lists' => []]))->toThrow(RuntimeException::class);

    // A NEM zárolt mezők viszont változhatnak (pl. az elnevezés). A `fresh()` azért kell,
    // mert az elutasított `update()` a memóriában „dirty"-n hagyta a célzást.
    expect($campaign->fresh()->update(['name' => 'Átnevezve']))->toBeTrue();
});

it('a VISSZAVONÁS a még nem kiküldött címzetteket MEGJELÖLI, nem törli', function () {
    // A riportnak meg kell tudnia mondani, hogy hányan NEM kapták meg, és miért.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);
    subscriber('bela@pelda.hu', $list);

    Bus::fake();

    $campaign = app(StartCampaign::class)(campaignFor($list));
    $campaign = app(CampaignLifecycle::class)->cancel($campaign);

    expect($campaign->status)->toBe('canceled');
    expect($campaign->recipients()->where('status', 'skipped')->count())->toBe(2);
    expect($campaign->recipients()->where('skip_reason', 'campaign_canceled')->count())->toBe(2);
});

/*
 | 63. CÉLZÁS: lista ∪ szegmens MÍNUSZ kizárás
 */

it('a célzás UNIÓ, majd KIVONÁS — és a becsült szám helyes', function () {
    // A sorrend nem mindegy: kizárás → unió esetén egy kizárt cím visszakerülne, ha egy
    // másik célzott listán is szerepel. A kizárás SZÁNDÉKA az, hogy az adott ember NE
    // kapja meg.
    $hirlevel = ContactList::create(['name' => 'Hírlevél']);
    $vip = ContactList::create(['name' => 'VIP']);
    $kizart = ContactList::create(['name' => 'Kizárt']);

    subscriber('a@pelda.hu', $hirlevel);
    subscriber('b@pelda.hu', $vip);

    // `c` MINDKÉT célzott listán szerepel — DEDUPLIKÁLNI kell.
    $c = subscriber('c@pelda.hu', $hirlevel);
    $c->lists()->attach($vip, ['status' => 'subscribed', 'subscribed_at' => now()]);

    // `d` célzott ÉS kizárt: a kizárás nyer.
    $d = subscriber('d@pelda.hu', $hirlevel);
    $d->lists()->attach($kizart, ['status' => 'subscribed', 'subscribed_at' => now()]);

    $campaign = campaignFor($hirlevel, [
        'target_lists' => [(string) $hirlevel->uuid, (string) $vip->uuid],
        'exclude_lists' => [(string) $kizart->uuid],
    ]);

    $emails = array_column(app(AudienceResolver::class)->resolve($campaign), 'email');

    expect($emails)->toBe(['a@pelda.hu', 'b@pelda.hu', 'c@pelda.hu']);
    expect(app(AudienceResolver::class)->estimate($campaign))->toBe(3);
});

it('a SZEGMENS is célozható, és a szegmens-motor UGYANAZ, mint a felületen', function () {
    // Egy külön kampány-oldali értelmezés azt jelentené, hogy a felületen mutatott
    // szegmens-tagság és a kampány címzettjei eltérnek.
    $list = ContactList::create(['name' => 'Lista']);

    subscriber('bp@pelda.hu', $list)->update(['attributes' => ['city' => 'Budapest']]);
    subscriber('db@pelda.hu', $list)->update(['attributes' => ['city' => 'Debrecen']]);

    $segment = Segment::create([
        'name' => 'Budapestiek',
        'definition' => ['rules' => [['field' => 'attr:city', 'op' => 'is', 'value' => 'Budapest']]],
    ]);

    $campaign = campaignFor($list, [
        'target_lists' => [],
        'target_segments' => [(string) $segment->uuid],
    ]);

    expect(array_column(app(AudienceResolver::class)->resolve($campaign), 'email'))->toBe(['bp@pelda.hu']);
});

it('a LEIRATKOZOTT tagság NEM tagság a célzásban', function () {
    $list = ContactList::create(['name' => 'Lista']);

    subscriber('bent@pelda.hu', $list);

    $kint = subscriber('kint@pelda.hu');
    $kint->lists()->attach($list, ['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

    expect(array_column(app(AudienceResolver::class)->resolve(campaignFor($list)), 'email'))
        ->toBe(['bent@pelda.hu']);
});

it('a CÍMZETT-HALMAZ materializálódik, és a DEDUPLIKÁCIÓ az egyedi kulcson áll', function () {
    // A halmaz azért tábla és nem lekérdezés, mert egy megszakadt küldés folytatható és
    // nem küld kétszer, a becsült szám és a valóság egyezik, és az A/B
    // variáns-hozzárendelés megismételhető.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    Bus::fake();

    $campaign = app(StartCampaign::class)(campaignFor($list));

    expect($campaign->recipient_count)->toBe(1);
    expect($campaign->estimated_recipients)->toBe(1);

    /*
     | ÚJRAINDÍTÁS: HANGOS elutasítás, nem csendes második köteg.
     |
     | Ez korábban átment: a `transition()` csendben visszatért, ha az állapot már a cél
     | volt, tehát a második indítás végigfutott és MÁSODIK batchet dobott ugyanazokra a
     | `pending` sorokra. Dupla levél nem lett belőle (az idempotencia-kulcs fogja), de a
     | számlálók duplán nőttek — mérve `sent_count = 2` `recipient_count = 1` mellett,
     | vagyis a riport hazudott. Két ütemező-futás vagy egy API + ütemező verseny pontosan
     | ide vezet, ezért a `sending` állapotot FELTÉTELES UPDATE igényli.
     */
    expect(fn () => app(StartCampaign::class)($campaign->fresh()))
        // A `sending → sending` átmenet MAGA tiltott, tehát már itt elhasal. A versenyt (két
        // egyidejű indítás ugyanabból az állapotból) a feltételes UPDATE fogja: az veszít,
        // aki nem kapta meg a sort, és „már elindította valaki más" hibát kap.
        ->toThrow(RuntimeException::class, 'sending → sending');

    expect($campaign->fresh()->recipients()->count())->toBe(1)
        ->and($campaign->fresh()->batch_id)->toBe($campaign->batch_id);

    // …ÉS a deduplikáció KÉT FORRÁSRA is áll: aki listán ÉS szegmensben is benne van, egy
    // levelet kap. Deduplikáció nélkül a címzett kétszer kapná meg ugyanazt a kampányt — ez
    // a legláthatóbb hiba, amit egy hírlevélküldő elkövethet, és azonnal spam-panasz.
    $szegmens = Segment::create([
        'name' => 'Mind',
        'definition' => ['match' => 'all', 'rules' => []],
    ]);

    $ketForras = app(StartCampaign::class)(campaignFor($list, [
        'name' => 'Két forrás',
        'target_segments' => [(string) $szegmens->uuid],
    ]));

    expect($ketForras->recipients()->count())->toBe(1)
        ->and($ketForras->recipient_count)->toBe(1);
});

/*
 | 64. A/B VARIÁNSOK
 */

it('a VARIÁNS-HOZZÁRENDELÉS determinisztikus, és a súlyok PONTOSAN teljesülnek', function () {
    // Sorsolásnál 100 címzettnél egy 50/50 könnyen ad 58/42-t — az A/B teszt eredményét a
    // mintavételi hiba döntené el, nem a tárgysor. És egy újrapróbálás MÁS variánst adna
    // ugyanannak az embernek.
    $list = ContactList::create(['name' => 'Lista']);
    $campaign = campaignFor($list);

    $a = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'A', 'weight' => 70]);
    $b = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'B', 'weight' => 30]);

    $plan = app(VariantAssigner::class)->plan($campaign->fresh(), 10);

    expect(count(array_filter($plan, fn ($id) => $id === $a->getKey())))->toBe(7);
    expect(count(array_filter($plan, fn ($id) => $id === $b->getKey())))->toBe(3);

    // UGYANAZ a bemenet ugyanazt a felosztást adja.
    expect(app(VariantAssigner::class)->plan($campaign->fresh(), 10))->toBe($plan);

    // A MARADÉK az utolsó variánshoz kerül — nem esik ki senki a felosztásból.
    $odd = app(VariantAssigner::class)->plan($campaign->fresh(), 7);
    expect(count($odd))->toBe(7);
});

it('a VARIÁNS-SÚLYOK összege 100 kell legyen', function () {
    // Egy 60/30-as beállításnál a hiányzó 10% vagy elveszne (tíz százalék nem kap levelet),
    // vagy valahova kerülne — és a felhasználó egyik esetben sem tudná, melyik történt.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    $campaign = campaignFor($list);
    CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'A', 'weight' => 60]);
    CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'B', 'weight' => 30]);

    expect(fn () => app(CampaignLifecycle::class)->start($campaign->fresh()))
        ->toThrow(InvalidArgumentException::class, '100');
});

it('a GYŐZTES kihirdetése MINIMÁLIS MINTÁT igényel, és a DÖNTETLEN nem győzelem', function () {
    // Tíz címzettnél a 60%-os nyitási arány hat embert jelent — a különbség mintavételi zaj.
    // Egy hamis eredmény alapján a tenant a következő tíz kampányát rossz irányba
    // optimalizálja.
    $list = ContactList::create(['name' => 'Lista']);
    $campaign = campaignFor($list);

    $a = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'A', 'weight' => 50]);
    $b = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'B', 'weight' => 50]);

    $a->forceFill(['sent_count' => 10])->save();
    $b->forceFill(['sent_count' => 10])->save();

    expect(fn () => app(VariantWinner::class)->declare($campaign->fresh()))
        ->toThrow(InvalidArgumentException::class, 'mintavételi zaj');

    // ELÉG minta, de DÖNTETLEN: nincs győztes. Az ábécésorrend nem eredmény.
    $a->forceFill(['sent_count' => 200])->save();
    $b->forceFill(['sent_count' => 200])->save();

    expect(app(VariantWinner::class)->declare($campaign->fresh()))->toBeNull();
});

it('a GYŐZTES az EGYEDI nyitók alapján dől el, és a döntés RÖGZÜL', function () {
    // Aki háromszor nyitja meg a levelet, EGY nyitás — enélkül egy kíváncsi címzett
    // egymaga „megnyerhetne" egy variánst.
    $list = ContactList::create(['name' => 'Lista']);
    $campaign = campaignFor($list);

    $a = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'A', 'weight' => 50]);
    $b = CampaignVariant::create(['campaign_id' => $campaign->getKey(), 'label' => 'B', 'weight' => 50]);

    $a->forceFill(['sent_count' => 200])->save();
    $b->forceFill(['sent_count' => 200])->save();

    // „A": egy címzett HÁROM nyitása = 1.
    foreach (range(1, 3) as $i) {
        MessageEvent::create([
            'type' => 'opened',
            'recipient' => 'egy@pelda.hu',
            'channel' => 'campaign',
            'tags' => ['campaign:'.$campaign->uuid, 'variant:A'],
            'occurred_at' => now()->subMinutes($i),
        ]);
    }

    // „B": KÉT különböző címzett = 2.
    foreach (['x@pelda.hu', 'y@pelda.hu'] as $email) {
        MessageEvent::create([
            'type' => 'opened',
            'recipient' => $email,
            'channel' => 'campaign',
            'tags' => ['campaign:'.$campaign->uuid, 'variant:B'],
            'occurred_at' => now(),
        ]);
    }

    $winner = app(VariantWinner::class)->declare($campaign->fresh(), 'open_rate');

    expect($winner?->label)->toBe('B');
    expect($winner?->is_winner)->toBeTrue();
    // A DÖNTÉS RÖGZÜL: egy fél évvel későbbi kérdésre a riportnak válaszolnia kell.
    expect($winner?->decided_by)->toBe('open_rate');
    expect($winner?->declared_at)->not->toBeNull();
    expect($a->fresh()->is_winner)->toBeFalse();
});

/*
 | 65. IDŐZÓNA-TUDATOS ÜTEMEZÉS + PROGRESSZ
 */

it('az ütemezés a TENANT IDŐZÓNÁJÁBAN értelmez, és UTC-ben tárol', function () {
    // „Kedd 9:00" egy budapesti ügyfélnek nem ugyanaz az UTC-pillanat nyáron és télen — ha
    // csak az UTC-t tároljuk, a nyári időszámítás váltása után a kampány elcsúszik.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    $campaign = app(CampaignLifecycle::class)->schedule(
        campaignFor($list),
        '2027-07-01 09:00:00',
        'Europe/Budapest',
    );

    // NYÁRI időszámítás: Budapest UTC+2 → 07:00 UTC.
    expect($campaign->scheduled_at->utc()->toDateTimeString())->toBe('2027-07-01 07:00:00');
    expect($campaign->timezone)->toBe('Europe/Budapest');
    // A felület a HELYI időt mutatja.
    expect($campaign->scheduledLocal()?->format('H:i'))->toBe('09:00');

    // TÉLI időszámítás: ugyanaz a helyi idő MÁS UTC-pillanat (UTC+1 → 08:00 UTC).
    $winter = app(CampaignLifecycle::class)->schedule(
        campaignFor($list, ['name' => 'Téli']),
        '2027-01-05 09:00:00',
        'Europe/Budapest',
    );

    expect($winter->scheduled_at->utc()->toDateTimeString())->toBe('2027-01-05 08:00:00');
});

it('a MÚLTRA ütemezés és az ISMERETLEN időzóna elutasítás', function () {
    // A múltra ütemezett kampányt az ütemező AZONNAL esedékesnek látná, és a felhasználó
    // azt hinné, hogy még van ideje szerkeszteni.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    $lifecycle = app(CampaignLifecycle::class);

    expect(fn () => $lifecycle->schedule(campaignFor($list), now()->subHour()->toDateTimeString(), 'UTC'))
        ->toThrow(InvalidArgumentException::class, 'jövőben');

    expect(fn () => $lifecycle->schedule(campaignFor($list, ['name' => 'B']), now()->addDay()->toDateTimeString(), 'Europe/Budapesst'))
        ->toThrow(InvalidArgumentException::class, 'időzóna');
});

it('az ESEDÉKES kampányt az ütemező elindítja', function () {
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    // v1.1 (review RS1) · az ütemező indítás előtt a szerver-ellenőrzéseket is lefuttatja
    // (szigorú lábléc: valódi postai cím) — a sablon ezért teljes láblécet kap.
    $this->template->forceFill(['blocks' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
        ['type' => 'text', 'attrs' => ['content' => 'Szia!']],
        ['type' => 'footer', 'attrs' => ['company' => 'Kft', 'address' => 'Nádor utca 1., 1051 Budapest', 'content' => 'x', 'unsubscribe_label' => 'Le', 'preferences_label' => 'Beá']],
    ]]]]], 'marketing' => true])->save();

    $campaign = app(CampaignLifecycle::class)->schedule(
        campaignFor($list),
        now()->addMinutes(5)->toDateTimeString(),
        'UTC',
    );

    expect($campaign->isDue())->toBeFalse();

    $this->travel(10)->minutes();

    expect($campaign->fresh()->isDue())->toBeTrue();

    Bus::fake();

    $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe('sending');
});

it('a HALADÁS a workspace PRIVÁT csatornáján megy, darabszámokkal', function () {
    // Egy közös csatorna azt jelentené, hogy minden tenant látja minden más tenant
    // kampányának a haladását — a kampány nevével és a címzettszámmal együtt.
    Event::fake([CampaignProgressed::class]);

    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    app(StartCampaign::class)(campaignFor($list));

    Event::assertDispatched(CampaignProgressed::class, function (CampaignProgressed $event) {
        expect($event->broadcastOn()[0]->name)->toBe('private-workspace.'.$this->workspace->getKey().'.campaigns');
        // DARABSZÁMOK, nem címzett-lista: a címek WebSocketen való szórása a legérzékenyebb
        // adatot tenné a legkevésbé kontrollált csatornára.
        expect($event->broadcastWith())->toHaveKeys(['campaign', 'total', 'sent', 'failed', 'percent']);
        expect(json_encode($event->broadcastWith()))->not->toContain('anna@pelda.hu');

        return true;
    });
});

/*
 | 66. A KAMPÁNY-SOR: legalacsonyabb prioritás
 */

it('a kampány a `campaign` SORRA megy, és a levél MIND A TÍZ kapun átment', function () {
    // A kampány UGYANAZON a küldőmotoron megy, mint az API-hívás (`CLAUDE.md`): a levél
    // mind a tíz PreSendGate-kapun átmegy. Egy külön „kampány-küldő" út megkerülné őket.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    app(StartCampaign::class)(campaignFor($list));

    $message = Message::query()->firstOrFail();

    // A CSATORNA `campaign`: a `SendQueue::forChannel` a legalacsonyabb prioritásra teszi.
    expect($message->channel)->toBe('campaign');
    expect(SendQueue::forChannel('campaign')->value)->toBe('campaign');
    // …és a tranzakciós MEGELŐZI (golden rule 4).
    expect(Channel::Transactional->queuePriority())
        ->toBeLessThan(Channel::Campaign->queuePriority());

    // A KAMPÁNY-CÍMKE: a tag-statisztika (A3) és a marketing analitika (B4) ebből dolgozik.
    expect($message->tags)->toContain('campaign:'.Campaign::query()->firstOrFail()->uuid);
    // A LEIRATKOZÓ FEJLÉC ott van: a `campaign` consent-köteles csatorna.
    expect($message->headers)->toHaveKey('List-Unsubscribe-Post');
});

it('a CONSENT és a SUPPRESSION a kampányban is érvényes — a kapu jelöli meg a kihagyást', function () {
    // A riportnak meg kell tudnia mondani, MIÉRT nem kapta meg valaki: „nincs consent" és
    // „suppression-listán van" két különböző beszélgetés az ügyféllel.
    $list = ContactList::create(['name' => 'Lista']);

    subscriber('jo@pelda.hu', $list);

    // Consent NÉLKÜL — a lista tagja, de nincs jogalap.
    $nincs = Contact::create(['email' => 'nincs@pelda.hu', 'status' => 'subscribed']);
    $nincs->lists()->attach($list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    // Leiratkozott (suppression), de a consentje még `confirmed`.
    subscriber('tiltott@pelda.hu', $list);
    Suppression::create(['email' => 'tiltott@pelda.hu', 'reason' => 'unsubscribe', 'applies_to' => 'marketing']);

    $campaign = app(StartCampaign::class)(campaignFor($list));

    $recipients = $campaign->fresh()->recipients()->get()->keyBy('email');

    expect($recipients['jo@pelda.hu']->status)->toBe('sent');
    expect($recipients['nincs@pelda.hu']->status)->toBe('skipped');
    expect($recipients['nincs@pelda.hu']->skip_reason)->toContain('CONSENT');
    expect($recipients['tiltott@pelda.hu']->status)->toBe('skipped');
    expect($recipients['tiltott@pelda.hu']->skip_reason)->toContain('SUPPRESS');

    // A SZÁMOK a valóságot mutatják.
    expect($campaign->fresh()->sent_count)->toBe(1);
    expect($campaign->fresh()->failed_count)->toBe(2);
});

it('az ÚJRAPRÓBÁLT küldés nem küld MÁSODSZOR', function () {
    // Az idempotencia két rétegű: a `pending` szűrő és a kampány+címzett
    // idempotencia-kulcs. A második akkor is véd, ha a `pending` szűrő valaha elromlana.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    $campaign = app(StartCampaign::class)(campaignFor($list));

    expect(Message::query()->count())->toBe(1);

    // A KÖTEG újrafuttatása: a címzett már `sent`, tehát kimarad.
    (new SendCampaignChunk(
        (string) $campaign->uuid,
        (int) $this->workspace->getKey(),
        $campaign->recipients()->pluck('id')->all(),
    ))->handle(app(WorkspaceContext::class), app(SendMessage::class));

    expect(Message::query()->count())->toBe(1);
});

it('az UTOLSÓ köteg BEFEJEZETTRE állítja a kampányt', function () {
    // Enélkül egy lefutott kampány örökre `sending` maradna, és a felületen „küldés
    // folyamatban"-t mutatna anélkül, hogy bármi történne.
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    $campaign = app(StartCampaign::class)(campaignFor($list));

    expect($campaign->fresh()->status)->toBe('sent');
    expect($campaign->fresh()->finished_at)->not->toBeNull();
});

/*
 | A REVIEW-BAN MÉRT HIBÁK regressziós tesztjei.
 */

it('a KAMPÁNY-CSATORNA jogosultsága működik — és NEM ad 500-at', function () {
    /*
     | Ez a csatorna korábban egy NEM LÉTEZŐ osztályra volt típusozva
     | (`App\Modules\Identity\Models\User`). Mérve: `TypeError: {closure}(): Argument #1
     | ($user) must be of type …\Identity\Models\User, App\Models\User given` — vagyis a
     | `/broadcasting/auth` MINDEN kérésre 500-at adott, és a 65. kritérium
     | Reverb-progresszére SENKI nem tudott feliratkozni. A `channels.php`-ra nulla teszt
     | volt, ezért csúszott át.
     */
    // A felhasználó a ventSSO-ból jön (nincs helyi jelszó — golden rule: az identitás
    // gazdája a ventSSO).
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'anna-'.uniqid(),
        'name' => 'Anna',
        'email' => 'anna@ventmail.test',
    ]);

    $workspace = app(WorkspaceContext::class)->workspace();

    WorkspaceMember::create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'roles' => ['owner'],
    ]);

    require base_path('routes/channels.php');

    $authorize = Broadcast::getChannels()['workspace.{workspaceId}.campaigns'] ?? null;

    expect($authorize)->not->toBeNull('A kampány-csatorna nincs regisztrálva.');

    // TAG → engedély.
    expect($authorize($user, (string) $workspace->getKey()))->toBeTrue();

    // MÁS workspace → elutasítás (fail-closed).
    $masik = Workspace::create(['name' => 'Másik', 'slug' => 'masik-'.uniqid()]);

    expect($authorize($user, (string) $masik->getKey()))->toBeFalse();

    // NEM-NUMERIKUS csatornanév → elutasítás, nem lekérdezés.
    expect($authorize($user, '1 or 1=1'))->toBeFalse();
});

it('az ÁTMENETI elutasítás NEM veszíti el a címzettet', function () {
    /*
     | A MÉRT HIBA: minden kapu-elutasítás `skipped` lett, tehát a `pending` sor eltűnt, és
     | a kampány `sent`-re zárt. Egy rate-limit-csúcs alatt futó köteg címzettjei így
     | VÉGLEGESEN kimaradtak, miközben a felület „elküldve" állapotot mutatott — adatvesztés
     | hangos hiba nélkül.
     |
     | A `RATE_LIMITED` és a `GATE_ERROR` ÁTMENETI: a sor `pending` marad, és a job
     | visszamegy a sorba. A VALÓDI kaput használjuk (a limitert kimerítjük), nem mockot: a
     | `SendMessage` `final`, és amit itt bizonyítani kell, az épp a kapu és a job
     | EGYÜTTMŰKÖDÉSE.
     */
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    Bus::fake();

    $campaign = app(StartCampaign::class)(campaignFor($list));

    // A PERCENKÉNTI LIMIT KIMERÍTVE — a következő küldés `RATE_LIMITED`-et kap.
    RateLimiter::increment(
        'sending:'.$campaign->workspace_id,
        60,
        (int) config('sending.rate_limit.per_minute'),
    );

    $job = new SendCampaignChunk(
        (string) $campaign->uuid,
        (int) $campaign->workspace_id,
        $campaign->recipients()->pluck('id')->all(),
    );

    app()->call([$job, 'handle']);

    // A CÍMZETT MEGMARADT `pending`-ben, a kampány NEM zárt le, és a számláló nem hazudik.
    expect($campaign->fresh()->recipients()->where('status', 'pending')->count())->toBe(1)
        ->and($campaign->fresh()->status)->toBe('sending')
        ->and((int) $campaign->fresh()->sent_count)->toBe(0)
        ->and((int) $campaign->fresh()->failed_count)->toBe(0);
});

it('a VÉGLEGES elutasítás okkal rögzül, és a kampány LEZÁR', function () {
    // A „nincs consent" VÉGLEGES: ez a címzett ebből a kampányból nem kap levelet, és a
    // riportnak ezt kell mondania — „nincs consent" és „suppression-listán van" két
    // különböző beszélgetés az ügyféllel.
    $list = ContactList::create(['name' => 'Lista']);

    // SZÁNDÉKOSAN consent NÉLKÜL: a kontakt listatag, de nincs megerősített hozzájárulása.
    $contact = Contact::create(['email' => 'nincs-consent@pelda.hu', 'status' => 'subscribed']);
    $contact->lists()->attach($list, ['status' => 'subscribed', 'subscribed_at' => now()]);

    Bus::fake();

    $campaign = app(StartCampaign::class)(campaignFor($list));

    $job = new SendCampaignChunk(
        (string) $campaign->uuid,
        (int) $campaign->workspace_id,
        $campaign->recipients()->pluck('id')->all(),
    );

    app()->call([$job, 'handle']);

    $recipient = $campaign->fresh()->recipients()->first();

    expect($recipient->status)->toBe('skipped')
        ->and($recipient->skip_reason)->toBe('NO_CONSENT')
        ->and($campaign->fresh()->status)->toBe('sent');
});

it('a FOLYTATÁS ÚJ KÖTEGEKET indít, nem csak a flaget írja át', function () {
    /*
     | A MÉRT HIBA: a `resume()` átírta az állapotot `sending`-re, a `batch_id` VÁLTOZATLAN
     | maradt, és egyetlen job sem került a sorba. A szüneteltetés alatt lefutott kötegek
     | addig a `SendCampaignChunk` korai `return`-jével elfogytak — azok a címzettek soha
     | nem kaptak volna levelet, a kampány pedig örökre „küldés folyamatban" maradt.
     |
     | A korábbi teszt csak azt állította, hogy `resume()->status === 'sending'`: egy flag,
     | nem viselkedés.
     */
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);
    subscriber('bela@pelda.hu', $list);

    Bus::fake();

    $campaign = app(StartCampaign::class)(campaignFor($list));
    $elsoBatch = (string) $campaign->batch_id;

    $campaign = app(CampaignLifecycle::class)->pause($campaign);
    $campaign = app(CampaignLifecycle::class)->resume($campaign);

    expect($campaign->status)->toBe('sending')
        // ÚJ batch, ÚJ jobok: a folytatás valóban folytat.
        ->and((string) $campaign->batch_id)->not->toBe($elsoBatch);

    Bus::assertBatchCount(2);
});

it('a KÜLDÉS ALATT lévő kampány célzása és tárgya az ADATBÁZISBAN sem írható át', function () {
    /*
     | A 62. szerkesztés-zár korábban CSAK az Eloquent `save()` úton élt. Mérve a `sending`
     | kampányon builder-`update()` és `saveQuietly()` egyaránt átírta a `subject`-et és a
     | `target_lists`-et. A levél egy része addig már kiment: a felület egy tárgyat mutatna,
     | a címzettek postafiókjában egy másik állna — és a riport egy harmadik célzásra
     | hivatkozna.
     */
    $list = ContactList::create(['name' => 'Lista']);
    subscriber('anna@pelda.hu', $list);

    Bus::fake();

    $campaign = app(StartCampaign::class)(campaignFor($list));

    expect($campaign->status)->toBe('sending');

    // SAVEPOINT: a trigger kivétele abortálja a tranzakciót, és a `RefreshDatabase` egy
    // tranzakcióban futtat — a következő állítás különben `25P02`-vel hasalna el.
    $savepoint = static fn (callable $write): callable => static fn () => DB::transaction($write);

    expect($savepoint(fn () => Campaign::query()->whereKey($campaign->getKey())->update(['subject' => 'Átírt tárgy'])))
        ->toThrow(QueryException::class, 'nem szerkeszthető');

    expect($savepoint(fn () => DB::table('campaigns')->where('id', $campaign->getKey())->update(['target_lists' => '[]'])))
        ->toThrow(QueryException::class, 'nem szerkeszthető');

    // …de az ÁLLAPOT és a SZÁMLÁLÓK írhatók: épp azokat írja a küldés.
    Campaign::query()->whereKey($campaign->getKey())->update(['sent_count' => 1]);

    expect((int) $campaign->fresh()->sent_count)->toBe(1)
        ->and($campaign->fresh()->subject)->toBe('Tavaszi ajánlat');
});

it('a CÉLZÁS nem gyűjti memóriába a közönséget', function () {
    /*
     | A MÉRT HIBA: a `resolve()` a TELJES közönséget Eloquent `Contact` modellként gyűjtötte,
     | a `estimate()` pedig ugyanezt még egyszer lefuttatta. 100 000 kontaktra +193 MB csúcs
     | (1,98 KB/címzett), a konténer valódi `memory_limit=128M`-jével pedig
     | `Allowed memory size … exhausted`.
     |
     | És a memória-fatal NEM `Throwable`: a `DispatchScheduledCampaignsCommand` `catch`-e
     | nem fogta meg, tehát EGYETLEN nagy kampány megölte a teljes ütemező-futást, és a
     | workspace-ciklusban utána következő MINDEN kampány kimaradt — percenként újra.
     |
     | Itt 2000 kontakttal mérünk (a 100 000 egy unit-tesztben túl lassú a magvetéshez), de
     | a HATÁR abszolút: a `estimate()` `COUNT`-ot futtat, tehát a fogyása nem a közönség
     | méretével nő. A régi implementáció ezen a méreten ~4 MB-ot vitt; a küszöb ennél
     | jóval szűkebb.
     */
    $list = ContactList::create(['name' => 'Nagy lista']);

    $rows = [];
    $now = now();

    for ($i = 0; $i < 2000; $i++) {
        $rows[] = [
            'uuid' => (string) Str::uuid7(),
            'workspace_id' => app(WorkspaceContext::class)->workspaceId(),
            'email' => 'tag'.$i.'@pelda.hu',
            'email_hash' => KeyedHash::of('email', 'tag'.$i.'@pelda.hu'),
            'status' => 'subscribed',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    Contact::query()->insert($rows);

    $ids = Contact::query()->where('email', 'like', 'tag%')->pluck('id');

    DB::table('contact_list_memberships')->insert($ids->map(fn (int $id) => [
        'workspace_id' => app(WorkspaceContext::class)->workspaceId(),
        'contact_id' => $id,
        'contact_list_id' => $list->getKey(),
        'status' => 'subscribed',
        'subscribed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ])->all());

    $resolver = app(AudienceResolver::class);
    $campaign = campaignFor($list, ['name' => 'Nagy kampány']);

    // BEMELEGÍTŐ HÍVÁS. Az első lekérdezés a builder-t, a grammar-t és a PDO-statementet is
    // felhúzza (~0,5 MB), ami FIX költség — nem a közönség méretével nő. A mérés a MÁSODIK
    // híváson megy: azt akarjuk látni, mennyit visz maga a 2000 címzett.
    expect($resolver->estimate($campaign))->toBe(2000);

    gc_collect_cycles();
    $before = memory_get_usage();

    expect($resolver->estimate($campaign))->toBe(2000);

    $used = memory_get_usage() - $before;

    /*
     | 64 KB — vagyis 33 bájt/címzett helyett NULLA a nagyságrend.
     |
     | A `COUNT` egyetlen sort ad vissza, tehát a fogyás a közönség méretétől FÜGGETLEN. A
     | régi, `count(resolve())`-os út ezen a méreten mérve ~4 MB-ot vitt (2 KB/címzett), és
     | 100 000-nél a folyamatot ütötte ki.
     */
    expect($used)->toBeLessThan(64 * 1024, sprintf('a becslés %d bájtot fogyasztott', $used));

    // …ÉS a kötegelt olvasás sem gyűjt: az `each()` egyszerre `$size` sort lát.
    gc_collect_cycles();
    $before = memory_get_usage();
    $count = 0;

    $resolver->each($campaign, 500, function (array $chunk) use (&$count): void {
        $count += count($chunk);
    });

    $streamed = memory_get_usage() - $before;

    expect($count)->toBe(2000)
        // 500 sor egyszerre, nem 2000: a küszöb a KÖTEG méretére van szabva.
        ->and($streamed)->toBeLessThan(512 * 1024, sprintf('a kötegelt olvasás %d bájtot fogyasztott', $streamed));
});
