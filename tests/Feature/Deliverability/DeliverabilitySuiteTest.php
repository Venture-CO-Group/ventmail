<?php

declare(strict_types=1);

use App\Modules\Consent\Models\Consent;
use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Deliverability\Data\SeedAddress;
use App\Modules\Deliverability\Data\SeedPlacement;
use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\Deliverability\Models\DmarcReport;
use App\Modules\Deliverability\Models\DmarcReportRow;
use App\Modules\Deliverability\Models\SeedTest;
use App\Modules\Deliverability\Services\BlocklistMonitor;
use App\Modules\Deliverability\Services\DmarcReportIngestor;
use App\Modules\Deliverability\Services\DmarcReportParser;
use App\Modules\Deliverability\Services\DnsBlocklistClient;
use App\Modules\Deliverability\Services\MtaStsPolicy;
use App\Modules\Deliverability\Services\SeedTestRunner;
use App\Modules\Deliverability\Services\SpamScorer;
use App\Modules\Deliverability\Testing\FakeBlocklistClient;
use App\Modules\Deliverability\Testing\FakeSeedListProvider;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;
use App\Modules\Domains\Jobs\VerifyDomainDns;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Inbound\Actions\VerifyInboundMx;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Gate\Gates\UnsubscribeHeaderGate;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\OutboundMimeBuilder;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A6 · 43–48. kritérium — kézbesíthetőségi suite.
 */

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->app->instance(DnsResolver::class, $this->dns);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);
});

/*
 | 43. BLOCKLIST-MONITOR
 */

function sendingIp(string $address = '203.0.113.20'): IpAddress
{
    $pool = IpPool::create(['name' => 'p-'.bin2hex(random_bytes(3)), 'type' => 'shared']);

    return IpAddress::create([
        'ip_pool_id' => $pool->getKey(),
        'address' => $address,
        'daily_limit' => 1000,
        'status' => 'active',
    ]);
}

it('a TISZTA állapot nem riaszt', function () {
    // A riasztás akkor ér valamit, ha ritka. Egy „minden rendben" üzenet óránként megtanítja
    // az ügyeletet, hogy ne olvassa el a következőt.
    sendingIp();

    $client = new FakeBlocklistClient;
    $this->app->instance(BlocklistClient::class, $client);

    $summary = app(BlocklistMonitor::class)->run();

    expect($summary['alerts'])->toBe([]);
    expect($summary['listed'])->toBe(0);
    expect($summary['checked'])->toBeGreaterThan(0);
});

it('a TALÁLAT riaszt, és rögzül', function () {
    $ip = sendingIp();

    $client = (new FakeBlocklistClient)->listOn('203.0.113.20', 'zen.spamhaus.org', '127.0.0.2');
    $this->app->instance(BlocklistClient::class, $client);

    $summary = app(BlocklistMonitor::class)->run();

    expect($summary['listed'])->toBe(1);
    expect($summary['alerts'])->toHaveCount(1);
    expect($summary['alerts'][0])->toContain('FELKERÜLT');
    expect($summary['alerts'][0])->toContain('203.0.113.20');

    $check = BlocklistCheck::query()->where('listed', true)->firstOrFail();

    expect($check->zone)->toBe('zen.spamhaus.org');
    expect($check->ip_address_id)->toBe($ip->getKey());
});

it('CSAK ÁLLAPOTVÁLTÁSKOR riaszt', function () {
    // Egy óránként ismételt „még mindig listán vagyunk" két nap alatt megtanítja az
    // ügyeletet, hogy figyelmen kívül hagyja a riasztásainkat — és akkor a következő,
    // VALÓDI is elvész.
    sendingIp();

    $client = (new FakeBlocklistClient)->listOn('203.0.113.20', 'zen.spamhaus.org');
    $this->app->instance(BlocklistClient::class, $client);

    expect(app(BlocklistMonitor::class)->run()['alerts'])->toHaveCount(1);
    expect(app(BlocklistMonitor::class)->run()['alerts'])->toBe([]);
});

it('a LEKERÜLÉS is riaszt', function () {
    sendingIp();

    $listed = (new FakeBlocklistClient)->listOn('203.0.113.20', 'zen.spamhaus.org');
    $this->app->instance(BlocklistClient::class, $listed);
    app(BlocklistMonitor::class)->run();

    $this->app->instance(BlocklistClient::class, new FakeBlocklistClient);

    $alerts = app(BlocklistMonitor::class)->run()['alerts'];

    expect($alerts)->toHaveCount(1);
    expect($alerts[0])->toContain('LEKERÜLT');
});

it('az ELDÖNTHETETLEN válasz NEM riaszt és nem is számít tisztának', function () {
    // A DNSBL-szolgáltatók a lekérdezés-keret túllépését is válasszal jelzik. Aki ezt
    // „listán van"-nak veszi, hamis riasztást ad; aki „tisztának", az elhallgat egy valódi
    // problémát.
    sendingIp();

    $client = (new FakeBlocklistClient)->inconclusive('203.0.113.20', 'zen.spamhaus.org');
    $this->app->instance(BlocklistClient::class, $client);

    $summary = app(BlocklistMonitor::class)->run();

    expect($summary['inconclusive'])->toBe(1);
    expect($summary['alerts'])->toBe([]);
    // …és NEM írtunk „tiszta" sort sem.
    expect(BlocklistCheck::query()->where('zone', 'zen.spamhaus.org')->count())->toBe(0);
});

/*
 | 44. DMARC RUA
 */

function dmarcXml(array $rows = []): string
{
    $records = '';

    foreach ($rows as $row) {
        $records .= sprintf(
            '<record><row><source_ip>%s</source_ip><count>%d</count>'
            .'<policy_evaluated><disposition>%s</disposition><dkim>%s</dkim><spf>%s</spf></policy_evaluated>'
            .'</row><identifiers><header_from>%s</header_from></identifiers></record>',
            $row['ip'],
            $row['count'],
            $row['disposition'] ?? 'none',
            $row['dkim'] ?? 'fail',
            $row['spf'] ?? 'fail',
            $row['from'] ?? 'pelda.hu',
        );
    }

    // A jelentés IDŐSZAKA a közeli múlt: a forrásonkénti aggregáció ablakos (alap 30 nap),
    // és egy beégetett, régi időbélyeg attól kezdve adna üres listát, hogy a naptár elhalad
    // mellette — a teszt pedig „idővel" romlana el, nem kódváltozásra.
    $begin = now()->subDay()->getTimestamp();

    return '<?xml version="1.0" encoding="UTF-8"?><feedback>'
        .'<report_metadata><org_name>google.com</org_name><report_id>12345</report_id>'
        .'<date_range><begin>'.$begin.'</begin><end>'.($begin + 86400).'</end></date_range></report_metadata>'
        .'<policy_published><domain>pelda.hu</domain><p>quarantine</p></policy_published>'
        .$records.'</feedback>';
}

it('értelmezi a DMARC-jelentést', function () {
    $report = app(DmarcReportParser::class)->parse(dmarcXml([
        ['ip' => '203.0.113.20', 'count' => 100, 'dkim' => 'pass', 'spf' => 'pass'],
        ['ip' => '198.51.100.7', 'count' => 5, 'dkim' => 'fail', 'spf' => 'fail'],
    ]));

    expect($report->orgName)->toBe('google.com');
    expect($report->domain)->toBe('pelda.hu');
    expect($report->policy)->toBe('quarantine');
    expect($report->total())->toBe(105);
    expect($report->passed())->toBe(100);
    expect($report->failed())->toBe(5);
});

it('a GZIP-ELT jelentést is értelmezi', function () {
    // A fogadók így küldik: `.xml.gz` csatolmányként.
    $report = app(DmarcReportParser::class)->parse((string) gzencode(dmarcXml([
        ['ip' => '203.0.113.20', 'count' => 10, 'dkim' => 'pass', 'spf' => 'pass'],
    ])));

    expect($report->total())->toBe(10);
});

it('a GZIP-BOMBÁT elutasítja', function () {
    // Ugyanaz a veszély, mint a bejövő csatolmánynál: a jelentés IDEGEN tartalom.
    config(['deliverability.dmarc.max_report_bytes' => 1024]);

    expect(fn () => app(DmarcReportParser::class)->parse((string) gzencode(str_repeat('A', 5_000_000))))
        ->toThrow(RuntimeException::class);
});

it('az SPF VAGY DKIM elég a DMARC-hoz', function () {
    // RFC 7489 §6.6.2: az igazodáshoz az egyik elég. Aki mindkettőt megköveteli, a saját,
    // teljesen szabályos továbbított leveleit is bukottnak könyveli.
    $report = app(DmarcReportParser::class)->parse(dmarcXml([
        ['ip' => '203.0.113.20', 'count' => 7, 'dkim' => 'pass', 'spf' => 'fail'],
        ['ip' => '203.0.113.21', 'count' => 3, 'dkim' => 'fail', 'spf' => 'pass'],
    ]));

    expect($report->passed())->toBe(10);
});

it('a malformált XML nem dönti el a feldolgozást', function () {
    expect(fn () => app(DmarcReportParser::class)->parse('<nem xml'))->toThrow(RuntimeException::class);
});

it('az XXE-t nem hajtja végre', function () {
    // A harmadik féltől érkező XML klasszikus vektora: külső entitással fájlt olvastatna fel.
    $xxe = '<?xml version="1.0"?><!DOCTYPE feedback [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<feedback><report_metadata><org_name>&xxe;</org_name><report_id>1</report_id>'
        .'<date_range><begin>1</begin><end>2</end></date_range></report_metadata>'
        .'<policy_published><domain>pelda.hu</domain><p>none</p></policy_published></feedback>';

    $report = app(DmarcReportParser::class)->parse($xxe);

    expect($report->orgName)->not->toContain('root:');
});

/*
 | 45. SEED / INBOX PLACEMENT
 */

it('a seed-mérés szolgáltatónként bontja az eredményt', function () {
    // Az „összesített 82% beérkező" elrejti, hogy a Gmailen 40% — pedig a legtöbb listán a
    // címek fele Gmail.
    $provider = (new FakeSeedListProvider)
        ->place('seed1@gmail.example', 'Gmail', SeedPlacement::SPAM)
        ->place('seed2@outlook.example', 'Outlook', SeedPlacement::INBOX)
        ->place('seed3@yahoo.example', 'Yahoo', SeedPlacement::PROMOTIONS);

    $this->app->instance(SeedListProvider::class, $provider);

    $test = app(SeedTestRunner::class)->evaluate(SeedTest::query()->create(['name' => 'kampány']));

    expect($test->inbox)->toBe(1);
    expect($test->spam)->toBe(1);
    expect($test->promotions)->toBe(1);
    expect($test->providers['Gmail']['spam'])->toBe(1);
    expect($test->providers['Outlook']['inbox'])->toBe(1);
    expect($test->inboxRate())->toBe(33.33);
});

it('a MEG NEM ÉRKEZETT levél is beleszámít', function () {
    // Ez a legrosszabb eset, és a leggyakrabban elhallgatott: ha kihagynánk, a beérkező arány
    // pont attól lenne szép, amitől rossznak kellene lennie.
    $provider = (new FakeSeedListProvider)
        ->place('seed1@gmail.example', 'Gmail', SeedPlacement::INBOX);

    $this->app->instance(SeedListProvider::class, $provider);

    $test = app(SeedTestRunner::class)->evaluate(SeedTest::query()->create());

    expect($test->missing)->toBe(2);
    // Egy beérkező háromból: 33.33%, NEM 100%.
    expect($test->inboxRate())->toBe(33.33);
});

/*
 | 46. KÜLDÉS ELŐTTI SPAM-PONTSZÁM
 */

it('a tiszta levél alacsony pontot kap', function () {
    $score = app(SpamScorer::class)->score(
        'Számla — 2026. szeptember',
        '<p>Kedves Anna, mellékelten küldjük a számlát.</p>',
        'Kedves Anna, mellékelten küldjük a számlát.',
    );

    expect($score->score)->toBeLessThan(1.0);
    expect($score->warns())->toBeFalse();
});

it('a RÖVIDÍTŐ-linket jelzi, KONKRÉT javaslattal', function () {
    // A szűrők nem tudják ellenőrizni, hova visznek — ezért régóta gyanúsnak veszik őket.
    $score = app(SpamScorer::class)->score(
        'Ajánlat',
        '<p>Nézd meg</p>',
        'Nézd meg',
        ['https://bit.ly/abc', 'https://pelda.hu/rendes'],
    );

    $rules = array_column($score->findings, 'rule');

    expect($rules)->toContain('URL_SHORTENER');

    $finding = collect($score->findings)->firstWhere('rule', 'URL_SHORTENER');

    // A JAVASLAT konkrét: megmondja, melyik domain a baj, és mit tegyen vele.
    expect($finding['advice'])->toContain('bit.ly');
    expect($finding['points'])->toBeGreaterThan(0);
});

it('a csupa nagybetűs tárgyat és a hiányzó szöveges változatot jelzi', function () {
    $score = app(SpamScorer::class)->score(
        'AKCIÓ MOST AZONNAL!!!',
        '<p>Vásárolj</p>',
        null,
    );

    $rules = array_column($score->findings, 'rule');

    expect($rules)->toContain('SHOUTING_SUBJECT');
    expect($rules)->toContain('EXCESSIVE_PUNCTUATION');
    expect($rules)->toContain('NO_PLAIN_TEXT');
});

it('MINDEN ponthoz tartozik javaslat', function () {
    // Egy fekete doboz „0.7"-e semmit nem ér: az ügyfél nem tudja, mit rontott el.
    $score = app(SpamScorer::class)->score('', null, null, ['https://bit.ly/a']);

    expect($score->findings)->not->toBeEmpty();

    foreach ($score->findings as $finding) {
        expect($finding['advice'])->toBeString()->not->toBe('');
        expect($finding['points'])->toBeGreaterThan(0);
    }
});

it('a pontszám NEM blokkol', function () {
    // A küldés joga a tenanté; a mi dolgunk megmondani, mire számíthat.
    $score = app(SpamScorer::class)->score('AKCIÓ!!!', null, null, ['https://bit.ly/a']);

    expect($score->warns())->toBeTrue();
    expect($score->toArray())->toHaveKey('threshold');
});

/*
 | 48. MTA-STS / TLS-RPT
 */

it('az MTA-STS policy a valódi MX-eket sorolja', function () {
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->dns->publish('pelda.hu', 'MX', '10 mx1.ventmail.app.');
    $this->dns->publish('pelda.hu', 'MX', '20 mx2.ventmail.app.');

    $policy = app(MtaStsPolicy::class)->policyFor($domain);

    expect($policy)->toContain('version: STSv1');
    expect($policy)->toContain('mx: mx1.ventmail.app');
    expect($policy)->toContain('mx: mx2.ventmail.app');
    expect($policy)->toContain('max_age:');
});

it('az MTA-STS alapból `testing` módban van', function () {
    // `enforce`-ban egy elrontott MX-lista MINDEN bejövő levelet blokkolna, és a hibát csak
    // akkor vennénk észre, amikor már senki nem ír nekünk.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->dns->publish('pelda.hu', 'MX', '10 mx1.ventmail.app.');

    expect(app(MtaStsPolicy::class)->policyFor($domain))->toContain('mode: testing');
});

it('a DNS-kimaradás nem „nincs beállítva"', function () {
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function records(string $name, string $type): array
        {
            throw new DnsUnavailable('SERVFAIL');
        }
    });

    expect(app(MtaStsPolicy::class)->status($domain))->toBe('unknown');
});

it('a wizard kiírja az MTA-STS és a TLS-RPT rekordot', function () {
    $records = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()])->dnsRecords();

    expect($records)->toHaveKey('mta_sts');
    expect($records)->toHaveKey('tls_rpt');
    expect($records['mta_sts']['value'])->toContain('v=STSv1');
    expect($records['tls_rpt']['value'])->toContain('v=TLSRPTv1');
});

/*
 | 47. ONE-CLICK UNSUBSCRIBE (RFC 8058) — marketing levélben KÖTELEZŐ.
 |
 | Ez nem formaság: a Gmail és a Yahoo 2024 februárja óta MEGKÖVETELI a tömeges küldőktől, és
 | a hiánya nem „rosszabb kézbesítés", hanem elutasítás.
 */

it('a marketing levél MEGKAPJA a leiratkozó fejléceket', function () {
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    Consent::create([
        'email' => 'anna@example.test',
        'channel' => 'marketing',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDay(),
    ]);

    $message = app(SendMessage::class)([
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@example.test'],
        'subject' => 'Hírlevél',
        'html' => '<p>Szia</p>',
        'text' => 'Szia',
        'idempotency_key' => 'u-'.bin2hex(random_bytes(5)),
    ])->message;

    expect($message->headers['List-Unsubscribe'])->toContain('/u/');
    // A `-Post` fejléc NÉLKÜL a `List-Unsubscribe` csak egy link: a levélkliens nem tudja,
    // hogy egy kattintással elvégezheti.
    expect($message->headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
    expect($message->status)->not->toBe('rejected');
});

it('a TRANZAKCIÓS levél NEM kap leiratkozó fejlécet', function () {
    // Egy jelszó-visszaállítóról nem lehet leiratkozni, és a fejléc ott félrevezető lenne.
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $message = app(SendMessage::class)([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'to' => ['anna@example.test'],
        'subject' => 'Jelszó',
        'html' => '<p>Szia</p>',
        'idempotency_key' => 'u-'.bin2hex(random_bytes(5)),
    ])->message;

    expect($message->headers)->not->toHaveKey('List-Unsubscribe');
});

it('a fejléc NÉLKÜLI marketing levelet a kapu elutasítja', function () {
    // A kapu azt zárja ki, hogy BÁRMELY út (REST, SMTP relay, kampány) megkerülje a
    // követelményt — akkor is, ha a fejléc-injektálás valaha elromlana.
    $gate = new UnsubscribeHeaderGate;

    $message = new Message(['channel' => 'marketing', 'headers' => []]);

    $result = $gate->check($message);

    expect($result->passed)->toBeFalse();
    expect($result->detail)->toContain('List-Unsubscribe');
});

it('a `-Post` fejléc hiánya is elutasítás', function () {
    $gate = new UnsubscribeHeaderGate;

    $message = new Message([
        'channel' => 'marketing',
        'headers' => ['List-Unsubscribe' => '<https://pelda.hu/u/abc>'],
    ]);

    expect($gate->check($message)->passed)->toBeFalse();
});

it('a ONE-CLICK POST leiratkoztat — megerősítés nélkül', function () {
    // Ha megerősítést kérnénk, a kattintás fele elveszne, és az olvasó a spam-gombot nyomná
    // helyette — a panasz pedig a küldő reputációját viszi, nem a levélét.
    $token = app(UnsubscribeTokens::class)
        ->issue((int) $this->workspace->getKey(), 'msg-uuid', 'anna@example.test');

    $this->post('/u/'.$token)->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    $suppression = Suppression::query()->firstOrFail();

    expect($suppression->email)->toBe('anna@example.test');
    // A leiratkozás a MARKETINGRE szól: a számla és a jelszó-visszaállítás ettől még mehet.
    expect($suppression->applies_to)->toBe('marketing');
    expect($suppression->reason)->toBe('unsubscribe');
});

it('a HAMIS token ugyanazt a választ adja, mint a jó', function () {
    // A különbség orákulum lenne arra, hogy egy cím szerepel-e a rendszerben.
    $this->post('/u/teljesen-hamis')->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(Suppression::query()->count())->toBe(0);
});

it('a MALLEÁLT token érvénytelen', function () {
    // A végpont ÍR (suppression-listára tesz): a token-variánsokkal a rate-limit is
    // megkerülhető lenne.
    $token = app(UnsubscribeTokens::class)
        ->issue((int) $this->workspace->getKey(), 'msg-uuid', 'anna@example.test');

    $elfogadott = [];

    for ($i = 1; $i < strlen($token); $i++) {
        if (app(UnsubscribeTokens::class)
            ->parse(substr($token, 0, $i).'~'.substr($token, $i)) !== null) {
            $elfogadott[] = $i;
        }
    }

    expect($elfogadott)->toBe([]);
});

it('a GET-es leiratkozás oldalt mutat, és NEM igényel bejelentkezést', function () {
    $token = app(UnsubscribeTokens::class)
        ->issue((int) $this->workspace->getKey(), 'msg-uuid', 'anna@example.test');

    $response = $this->get('/u/'.$token);

    $response->assertOk();
    $response->assertSee('Leiratkozás');
    // A tranzakciós levelekről KIMONDJUK, hogy nem érinti — különben az olvasó azt hiszi,
    // hogy a számlát sem kapja meg többé.
    $response->assertSee('számlákat', false);
});

/*
 | A REVIEW-BAN TALÁLT HIBÁK MÉRŐ TESZTJEI.
 |
 | Mindegyik olyan hiba, amit a FAKE takart el: a teszt zöld volt, az éles út nem működött.
 */

it('a KAMPÁNY és a JOURNEY is megkapja a leiratkozó fejlécet', function () {
    // A `Channel` enumban NÉGY érték van, és a Studio TELJES tömegforgalma a `campaign` és a
    // `journey` soron megy. Nyers `!== 'marketing'` összehasonlítással pont ez a forgalom
    // ment volna ki `List-Unsubscribe` nélkül — amit a Gmail és a Yahoo 2024 óta elutasít.
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    foreach (['campaign', 'journey', 'marketing'] as $channel) {
        $message = Message::create([
            'channel' => $channel,
            'from_email' => 'no-reply@pelda.hu',
            'recipients' => ['anna@example.test'],
            'subject' => 'Hírlevél',
            'headers' => [],
        ]);

        expect(array_keys($message->headers))->toContain('List-Unsubscribe');
        expect((new UnsubscribeHeaderGate)->check($message)->passed)->toBeTrue($channel);
    }
});

it('a kapu a KAMPÁNY levelet is elutasítja fejléc nélkül', function () {
    $gate = new UnsubscribeHeaderGate;

    foreach (['campaign', 'journey'] as $channel) {
        expect($gate->check(new Message(['channel' => $channel, 'headers' => []]))->passed)
            ->toBeFalse($channel);
    }
});

it('a `mailto:` tárgyába rejtett https NEM one-click URL', function () {
    // Részsztring-vizsgálattal a `<mailto:le@pelda.hu?subject=https://>` átment volna: a
    // fejléc tartalmazza a `https://`-t, de a fogadó one-click POST-ja sehova nem menne.
    $gate = new UnsubscribeHeaderGate;

    $message = new Message([
        'channel' => 'marketing',
        'headers' => [
            'List-Unsubscribe' => '<mailto:le@pelda.hu?subject=https://>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ],
    ]);

    expect($gate->check($message)->passed)->toBeFalse();
});

it('BATCH-ben MINDEN címzett a SAJÁT leiratkozó tokenjét kapja', function () {
    // Ha a batch mindhárom levele az ELSŐ címzett tokenjét vinné, Béla leiratkozása ANNÁT
    // tenné suppression-listára — Béla pedig továbbra is kapná a levelet. Consent-sértés
    // (golden rule 4) és rossz irányú GDPR-hatás, csendben.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $message = Message::create([
        'domain_id' => $domain->getKey(),
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['anna@example.test', 'bela@example.test', 'cili@example.test'],
        'subject' => 'Hírlevél',
        'headers' => [],
    ]);

    $tokens = app(UnsubscribeTokens::class);

    foreach (['anna@example.test', 'bela@example.test', 'cili@example.test'] as $recipient) {
        $header = (string) $message->headersFor($recipient)['List-Unsubscribe'];
        $token = (string) preg_replace('#^.*/u/|>$#', '', $header);

        expect($tokens->parse($token)['recipient'])->toBe($recipient);
    }
});

it('a leiratkozó fejléc BEKERÜL a kimenő MIME-ba', function () {
    // A modell beírta, a kapu látta — a MIME viszont csak az `x-` fejléceket engedte át,
    // tehát a fogadóhoz SOHA nem jutott el. Az eredmény ugyanaz, mintha meg sem született
    // volna.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $message = Message::create([
        'domain_id' => $domain->getKey(),
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['bela@example.test'],
        'subject' => 'Hírlevél',
        'headers' => [],
    ]);

    $mime = app(OutboundMimeBuilder::class)
        ->build($message->withRenderedBodies(['bela@example.test' => ['html' => '<p>Szia</p>', 'text' => 'Szia']]), 'bela@example.test')
        ->toString();

    expect($mime)->toContain('List-Unsubscribe:');
    expect($mime)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click');
});

it('az ÜGYFÉL saját leiratkozó fejlécéhez nem nyúlunk', function () {
    // Egy migrált rendszer a saját preference centerére mutathat; a token nélküli, idegen
    // URL-t nem tudnánk és nem is akarnánk átírni.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $message = Message::create([
        'domain_id' => $domain->getKey(),
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['anna@example.test', 'bela@example.test'],
        'subject' => 'Hírlevél',
        'headers' => [
            'List-Unsubscribe' => '<https://sajat.example/leiratkozas>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ],
    ]);

    expect($message->headersFor('bela@example.test')['List-Unsubscribe'])
        ->toBe('<https://sajat.example/leiratkozas>');
});

it('a `http://` leiratkozó hoszt NEM ad fejlécet — és a kapu elutasít', function () {
    // A token a leiratkozó személyét hordozza, és a levélkliensen, proxyn és a Gmail
    // szerverén megy át. Titkosítatlan csatornán nem mehet ki.
    config(['sending.tracking_base_url' => 'http://nem-biztonsagos.example', 'app.url' => 'http://nem-biztonsagos.example']);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $message = Message::create([
        'channel' => 'marketing',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['anna@example.test'],
        'subject' => 'Hírlevél',
        'headers' => [],
    ]);

    expect($message->headers)->not->toHaveKey('List-Unsubscribe');
    expect((new UnsubscribeHeaderGate)->check($message)->passed)->toBeFalse();
});

it('a leiratkozás VEGYES kis/nagybetűs címre és KÉTSZER is működik', function () {
    // A Gmail ÚJRAPRÓBÁLJA a one-click POST-ot, és a felhasználó a POST után a linkre is
    // rákattint. A nyers címmel kereső, normalizálva író `firstOrCreate` a MÁSODIK híváson
    // unique-violationnel szállt el — és a leiratkozás „sikertelen"-ként ment vissza.
    $token = app(UnsubscribeTokens::class)
        ->issue((int) $this->workspace->getKey(), 'msg-uuid', 'Anna@Pelda.HU');

    $this->post('/u/'.$token)->assertOk();
    $this->post('/u/'.$token)->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(Suppression::query()->count())->toBe(1);
    expect(Suppression::query()->firstOrFail()->email)->toBe('anna@pelda.hu');
});

it('a MÁR `all` hatókörrel tiltott címen a leiratkozás nem lazít és nem is hibázik', function () {
    // Ez volt a másik 500-as út: a `(workspace_id, email)` egyedi kulcs `applies_to` NÉLKÜL
    // van, tehát egy meglévő `all` sor mellett már az ELSŐ POST elszállt.
    Suppression::create([
        'email' => 'anna@pelda.hu',
        'reason' => 'complaint',
        'applies_to' => 'all',
    ]);

    $token = app(UnsubscribeTokens::class)
        ->issue((int) $this->workspace->getKey(), 'msg-uuid', 'anna@pelda.hu');

    $this->post('/u/'.$token)->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    $suppression = Suppression::query()->firstOrFail();

    // A hatókör NEM szűkült `marketing`-re: a panasz a tranzakciós csatornát is zárta.
    expect($suppression->applies_to)->toBe('all');
    expect(Suppression::query()->count())->toBe(1);
});

it('a GET NEM ír — csak megerősítést kér', function () {
    // A levélben lévő linkeket a védelmi átjárók (SafeLinks, vírusszűrő proxy,
    // kliens-előtöltés) VÉGIGLÁTOGATJÁK. Írós GET-tel ezek csendben leiratkoztatnák azt, aki
    // soha nem kattintott — az RFC 8058 pontosan ezért ír elő POST-ot.
    $token = app(UnsubscribeTokens::class)
        ->issue((int) $this->workspace->getKey(), 'msg-uuid', 'anna@example.test');

    $this->get('/u/'.$token)->assertOk();

    app(WorkspaceContext::class)->set($this->workspace);

    expect(Suppression::query()->count())->toBe(0);
});

it('az A-rekordos DNSBL-választ a resolver TÉNYLEG visszaadja', function () {
    // A resolver az A-rekordokat `txt`/`target` kulcsból képezte, a `dns_get_record` viszont
    // `ip`-t ad: MINDEN A-lekérdezés üres listát adott, és a DNSBL-kliens ebből „tiszta"
    // ítéletet olvasott. A blocklist-monitor élesben SOHA nem talált volna semmit.
    $this->dns->publish('2.0.0.127.zen.spamhaus.org', 'A', '127.0.0.2');

    $verdict = app(DnsBlocklistClient::class)->check('127.0.0.2', 'zen.spamhaus.org');

    expect($verdict->listed)->toBeTrue();
    expect($verdict->conclusive)->toBeTrue();
    expect($verdict->detail)->toContain('127.0.0.2');
});

it('a szolgáltató HIBAJELZÉSE nem találat', function () {
    // A `127.255.255.254` = „nyilvános resolveren keresztül kérdeztél", a `.252` =
    // „lekérdezés-keret túllépve". Aki ezeket találatnak veszi, hamis riasztást ad.
    $this->dns->publish('20.113.0.203.zen.spamhaus.org', 'A', '127.255.255.252');

    $verdict = app(DnsBlocklistClient::class)->check('203.0.113.20', 'zen.spamhaus.org');

    expect($verdict->listed)->toBeFalse();
    expect($verdict->conclusive)->toBeFalse();
});

it('a DMARC-jelentés BETÖLTŐDIK, és az ismeretlen forrás megjelölődik', function () {
    // A parser eddig csak DTO-t adott: a három tábla halott séma volt, a `known_source`
    // sosem állt be — a 44. kritérium másik fele („forrásonkénti aggregáció, ismeretlen
    // forrás kiemelve") így nem teljesült.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    sendingIp('203.0.113.20');

    $report = app(DmarcReportIngestor::class)->ingest(dmarcXml([
        ['ip' => '203.0.113.20', 'count' => 100, 'dkim' => 'pass', 'spf' => 'pass'],
        ['ip' => '198.51.100.7', 'count' => 5, 'dkim' => 'fail', 'spf' => 'fail'],
    ]));

    expect($report->domain_id)->toBe($domain->getKey());
    expect($report->total)->toBe(105);
    expect($report->passed)->toBe(100);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(DmarcReportRow::query()->where('source_ip', '203.0.113.20')->firstOrFail()->known_source)->toBeTrue();
    expect(DmarcReportRow::query()->where('source_ip', '198.51.100.7')->firstOrFail()->known_source)->toBeFalse();

    // A forrás-lista az ISMERETLENNEL kezdődik: egy jelentésben több száz forrás lehet, és a
    // lényeg a lista elején van.
    $sources = app(DmarcReportIngestor::class)->sources($domain);

    expect($sources[0]['source_ip'])->toBe('198.51.100.7');
    expect($sources[0]['known'])->toBeFalse();
});

it('az ÚJRAKÜLDÖTT jelentés nem duplázza a számokat', function () {
    // A fogadók újraküldenek. Duplikálva minden újraküldés megkétszerezné a forgalmi
    // számokat, és a trend-grafikon hazudna.
    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $xml = dmarcXml([['ip' => '198.51.100.7', 'count' => 5]]);

    app(DmarcReportIngestor::class)->ingest($xml);
    app(DmarcReportIngestor::class)->ingest($xml);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(DmarcReport::query()->count())->toBe(1);
    expect(DmarcReportRow::query()->count())->toBe(1);
});

it('IDEGEN domainről szóló jelentést nem töltünk be', function () {
    // A jelentést BÁRKI elküldheti nekünk (az RUA-cím nyilvános a DNS-ben). A feladó szavára
    // írva egy hamisított jelentéssel bárki idegen tenant grafikonjára rajzolhatna.
    expect(fn () => app(DmarcReportIngestor::class)->ingest(dmarcXml([['ip' => '1.2.3.4', 'count' => 1]])))
        ->toThrow(InvalidArgumentException::class);
});

it('a `<style>` blokk NEM számít szövegnek', function () {
    // A ventMail sablonmotorja MJML: MINDEN valódi levélben van style-blokk. A `strip_tags`
    // a CSS-t szövegnek vette, tehát a két tartalmi szabály a gyakorlatban sosem tüzelt —
    // egy csak-képes levél 5.5 pont helyett 1.0-t kapott.
    $css = '<style>'.str_repeat('.a{color:#fff;background:#000;padding:10px}', 40).'</style>';

    $bare = app(SpamScorer::class)->score('Ajánlat', '<img src="a.png"><img src="b.png">', null);
    $styled = app(SpamScorer::class)->score('Ajánlat', $css.'<img src="a.png"><img src="b.png">', null);

    expect($styled->score)->toBe($bare->score);
    expect(array_column($styled->findings, 'rule'))->toContain('IMAGE_HEAVY');
});

it('a MOZAIKSZAVAS tárgy nem kiabálás', function () {
    // Az „ÁFA IDŐ 2026" hétköznapi tárgy. A hamis találat pont az a fajta, amitől az ügyfél
    // elkezdi figyelmen kívül hagyni az egész pontszámot.
    $score = app(SpamScorer::class)->score('ÁFA IDŐ 2026 — a havi elszámolás', '<p>Szia</p>', 'Szia');

    expect(array_column($score->findings, 'rule'))->not->toContain('SHOUTING_SUBJECT');
});

it('a FEKETELISTÁS link-domaint jelzi', function () {
    // A küldő IP-je lehet makulátlan: ha a levélben lévő link listás domainre mutat, a
    // fogadók a LEVELET minősítik spamnek.
    $this->app->instance(BlocklistClient::class, (new FakeBlocklistClient)->listOn('rossz.example', 'dbl.spamhaus.org'));

    $score = app(SpamScorer::class)->score('Ajánlat', '<p>Nézd</p>', 'Nézd', ['https://rossz.example/a']);

    $rules = array_column($score->findings, 'rule');

    expect($rules)->toContain('BLOCKLISTED_DOMAIN');
    expect(collect($score->findings)->firstWhere('rule', 'BLOCKLISTED_DOMAIN')['advice'])->toContain('rossz.example');
});

it('az ISMERETLEN seed-mappa nem tűnik el a nevezőből', function () {
    // Az `updates` a Gmail valódi fülneve. Ellenőrizetlenül átvéve a levél eltűnt a
    // statisztikából: két címből egy `inbox`, egy `updates` → 100% beérkező.
    expect(SeedPlacement::bucket('updates'))->toBe(SeedPlacement::PROMOTIONS);
    expect(SeedPlacement::bucket('junk'))->toBe(SeedPlacement::SPAM);
    expect(SeedPlacement::bucket('valami-uj-ful'))->toBeNull();

    expect(fn () => new SeedPlacement('a@b.example', 'Gmail', 'updates'))
        ->toThrow(InvalidArgumentException::class);
});

it('ugyanaz a szolgáltató EGY vödörbe kerül, akkor is, ha nem érkezett meg minden levél', function () {
    // A megérkezett levél a jelentés címkéje alá (`Gmail`), a hiányzó a cím domainje alá
    // (`gmail.com`) került: ugyanaz a szolgáltató KÉT vödörben látszott, és a
    // „szolgáltatónkénti romlás" pont ott nem látszott, ahol a baj volt.
    $provider = new FakeSeedListProvider;
    $provider->addresses = [
        new SeedAddress('a@gmail.example', 'Gmail'),
        new SeedAddress('b@gmail.example', 'Gmail'),
    ];
    $provider->place('a@gmail.example', 'Gmail', SeedPlacement::INBOX);

    $this->app->instance(SeedListProvider::class, $provider);

    $test = app(SeedTestRunner::class)->evaluate(SeedTest::query()->create());

    expect(array_keys($test->providers))->toBe(['Gmail']);
    expect($test->providers['Gmail'])->toBe(['inbox' => 1, 'spam' => 0, 'promotions' => 0, 'missing' => 1]);
});

it('DNS-kimaradáskor NINCS MTA-STS policy', function () {
    // Az üres `mx:` listájú policy azt jelenti, hogy EGYETLEN szerver sem jogosult levelet
    // fogadni a domainre — `enforce` módban a fogadók ezt a `max_age` hosszán (alapból 7
    // nap) cache-elik. Egy pillanatnyi DNS-hibából egy hetes, teljes bejövő leállás lenne.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->dns->blackhole('pelda.hu');

    expect(app(MtaStsPolicy::class)->policyFor($domain))->toBeNull();
});

it('az MTA-STS policy CRLF sorvégekkel megy ki', function () {
    // RFC 8461 §3.2. A szigorúbb fogadók a puszta LF-es változatot nem értelmezik — és a
    // hibát semmi nem jelezné vissza nekünk.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->dns->publish('pelda.hu', 'MX', '10 mx1.ventmail.app.');

    expect(app(MtaStsPolicy::class)->policyFor($domain))->toContain("\r\n");
});

it('a policy a `mta-sts.<domain>` hoszton kiszolgálódik — hitelesítés nélkül', function () {
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    $this->dns->publish('pelda.hu', 'MX', '10 mx1.ventmail.app.');

    $response = $this->get('http://mta-sts.pelda.hu/.well-known/mta-sts.txt');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toContain('mx: mx1.ventmail.app');
});

it('ismeretlen vagy nem verifikált domainre nincs policy', function () {
    // Egy tetszőleges hosztra kiszolgált policy azt állítaná, hogy a domain levélforgalmát MI
    // szabályozzuk — pedig nem.
    Domain::create(['name' => 'nemkesz.hu', 'status' => 'pending']);

    $this->get('http://mta-sts.nemkesz.hu/.well-known/mta-sts.txt')->assertNotFound();
    $this->get('http://mta-sts.idegen.hu/.well-known/mta-sts.txt')->assertNotFound();
    $this->get('http://pelda.hu/.well-known/mta-sts.txt')->assertNotFound();
});

it('a DNS-kimaradás nem rontja el a domain hitelesítését', function () {
    // Ha a `verify` DNS-hibára `failed`-et írna, egy resolver-kimaradás MINDEN domaint
    // hitelesítetlenné tenne, és a PreSendGate 1. kapuja a teljes platform küldését
    // megállítaná.
    $domain = Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now(), 'spf_status' => 'verified']);

    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function records(string $name, string $type): array
        {
            throw new DnsUnavailable('SERVFAIL');
        }
    });

    app(VerifyDomainDns::class)->handle($domain);

    expect($domain->fresh()->status)->toBe('verified');
});

it('a DNS-kimaradás nem szünteti meg a bejövő fogadást', function () {
    // A „nem tudtuk megkérdezni" NEM „levették az MX-et": `failed`-del a levelek nem sorban
    // állnának, hanem visszapattannának.
    $domain = Domain::create([
        'name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now(),
        'inbound_mx_status' => 'verified',
    ]);

    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function records(string $name, string $type): array
        {
            throw new DnsUnavailable('SERVFAIL');
        }
    });

    expect(app(VerifyInboundMx::class)($domain))->toBe('verified');
});

it('a lejárt kézbesíthetőségi adatok törlődnek', function () {
    // Golden rule 10. A `blocklist_checks` óránként `IP × zóna` sorral nő: takarítás nélkül
    // száz IP-vel évi másfél millió sor.
    sendingIp();

    BlocklistCheck::query()->create([
        'target' => '203.0.113.20', 'zone' => 'zen.spamhaus.org', 'listed' => false,
        'checked_at' => now()->subDays(400),
    ]);

    SeedTest::query()->create(['expires_at' => now()->subDay()]);

    $this->artisan('deliverability:purge')->assertSuccessful();

    expect(BlocklistCheck::query()->count())->toBe(0);

    app(WorkspaceContext::class)->set($this->workspace);

    expect(SeedTest::query()->count())->toBe(0);
});
