<?php

declare(strict_types=1);

use App\Modules\Consent\Models\Consent;
use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Deliverability\Data\SeedPlacement;
use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\Deliverability\Models\SeedTest;
use App\Modules\Deliverability\Services\BlocklistMonitor;
use App\Modules\Deliverability\Services\DmarcReportParser;
use App\Modules\Deliverability\Services\MtaStsPolicy;
use App\Modules\Deliverability\Services\SeedTestRunner;
use App\Modules\Deliverability\Services\SpamScorer;
use App\Modules\Deliverability\Testing\FakeBlocklistClient;
use App\Modules\Deliverability\Testing\FakeSeedListProvider;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Gate\Gates\UnsubscribeHeaderGate;
use App\Modules\Sending\Models\Message;
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

    return '<?xml version="1.0" encoding="UTF-8"?><feedback>'
        .'<report_metadata><org_name>google.com</org_name><report_id>12345</report_id>'
        .'<date_range><begin>1757000000</begin><end>1757086400</end></date_range></report_metadata>'
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
        ->place('seed1@gmail.example', 'gmail.example', SeedPlacement::SPAM)
        ->place('seed2@outlook.example', 'outlook.example', SeedPlacement::INBOX)
        ->place('seed3@yahoo.example', 'yahoo.example', SeedPlacement::PROMOTIONS);

    $this->app->instance(SeedListProvider::class, $provider);

    $test = app(SeedTestRunner::class)->evaluate(SeedTest::query()->create(['name' => 'kampány']));

    expect($test->inbox)->toBe(1);
    expect($test->spam)->toBe(1);
    expect($test->promotions)->toBe(1);
    expect($test->providers['gmail.example']['spam'])->toBe(1);
    expect($test->providers['outlook.example']['inbox'])->toBe(1);
    expect($test->inboxRate())->toBe(33.33);
});

it('a MEG NEM ÉRKEZETT levél is beleszámít', function () {
    // Ez a legrosszabb eset, és a leggyakrabban elhallgatott: ha kihagynánk, a beérkező arány
    // pont attól lenne szép, amitől rossznak kellene lennie.
    $provider = (new FakeSeedListProvider)
        ->place('seed1@gmail.example', 'gmail.example', SeedPlacement::INBOX);

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
