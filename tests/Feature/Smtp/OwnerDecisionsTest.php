<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\Smtp\Protocol\SmtpSession;
use App\Modules\Smtp\Services\CredentialAuthenticator;
use App\Modules\Smtp\Transport\InvalidProxyHeader;
use App\Modules\Smtp\Transport\ProxyProtocolHeader;
use App\Modules\Smtp\Transport\StreamSubmissionServer;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SmtpConversation;

uses(RefreshDatabase::class);

/*
 | Az ADR 0001 tulajdonosi döntései (2026-09-03) — mindegyikhez viselkedés-teszt.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    ['username' => $this->username, 'password' => $this->password] = SmtpCredential::issue('CI');
});

/*
 | 1. Marketing SMTP-n: VÉGLEG TILTVA.
 */

it('az SMTP-n beadott levél MINDIG tranzakciós', function () {
    SmtpConversation::play(test()->username, test()->password, SmtpConversation::message(), test()->workspace);

    expect(Message::firstOrFail()->channel)->toBe('transactional');
});

it('a hitelesítő csatornája adatbázis-szinten sem állítható marketingre', function () {
    // Kettős zár: az ingest fix értéket küld, de egy „csak indokolt esetben" állítható
    // oszlop előbb-utóbb támogatási kérésre átbillen — a check-constraint ezt is kizárja.
    expect(fn () => DB::table('smtp_credentials')->update(['channel_restriction' => 'marketing']))
        ->toThrow(QueryException::class);
});

/*
 | 2. SMTPUTF8 / IDN.
 */

it('az EHLO hirdeti az SMTPUTF8-at', function () {
    $session = new SmtpSession(
        app(CredentialAuthenticator::class),
        app(SubmissionSink::class),
        ChannelSecurity::encrypted('TLSv1.3'),
        'smtp.ventmail.test',
    );

    expect($session->feed('EHLO kliens.test')[0]->text)->toContain('SMTPUTF8');
});

it('az IDN-domain PUNYCODE alakban keresi a hitelesített domaint', function () {
    // Ha a `példa.hu` és a `xn--plda-bpa.hu` két külön domainnek látszana, a küldő a másik
    // alakkal megkerülhetné a domain-kaput.
    Domain::create([
        'name' => DomainNormalizer::canonical('példa.hu'),
        'status' => 'verified',
        'verified_at' => now(),
    ]);

    $codes = SmtpConversation::play(test()->username, test()->password, [
        'MAIL FROM:<info@példa.hu>',
        'RCPT TO:<cimzett@example.test>',
        'DATA',
        'From: info@példa.hu',
        'Subject: Unicode',
        '',
        'Szia',
        '.',
    ], test()->workspace);

    expect(end($codes))->toBe(250);
    expect(Message::firstOrFail()->from_email)->toBe('info@xn--plda-bpa.hu');
});

it('a NEM hitelesített IDN-domain sem megy át — se unicode, se punycode alakban', function () {
    foreach (['info@másik.hu', 'info@xn--msik-5qa.hu'] as $from) {
        $codes = SmtpConversation::play(test()->username, test()->password, [
            'MAIL FROM:<'.$from.'>',
            'RCPT TO:<cimzett@example.test>',
            'DATA',
            'From: '.$from,
            '',
            'Szia',
            '.',
        ], test()->workspace);

        expect(end($codes))->toBe(550);
    }
});

it('a helyi rész VÁLTOZATLAN marad — azt csak a fogadó értelmezheti', function () {
    expect(DomainNormalizer::email('Ünnepi.Kovács@példa.hu'))->toBe('Ünnepi.Kovács@xn--plda-bpa.hu');
});

/*
 | 3. Hitelesítő-szintű rate limit (60/perc).
 */

it('a hitelesítő perces kerete fölött 452 jön', function () {
    config(['smtp.rate_limit.per_credential_per_minute' => 2]);

    // D297: a keret NAPTÁRI percre szól (`YmdHi` kulcs) — egy percváltáson átnyúló futásban a
    // harmadik levél már új keretbe esne (flaky). Az idő rögzítve: mindhárom ugyanabban a percben.
    $this->freezeTime();

    SmtpConversation::play(test()->username, test()->password, SmtpConversation::message(['Message-ID' => '<a@k.test>']), test()->workspace);
    SmtpConversation::play(test()->username, test()->password, SmtpConversation::message(['Message-ID' => '<b@k.test>']), test()->workspace);

    $codes = SmtpConversation::play(test()->username, test()->password, SmtpConversation::message(['Message-ID' => '<c@k.test>']), test()->workspace);

    // Átmeneti kód: a kliens újrapróbál, a levél nem vész el.
    expect(end($codes))->toBe(452);
    expect(Message::count())->toBe(2);
});

it('a hitelesítő SAJÁT limitje felülírja az alapértéket', function () {
    config(['smtp.rate_limit.per_credential_per_minute' => 1]);

    SmtpCredential::query()->update(['rate_limit_per_minute' => 5]);

    foreach (['a', 'b', 'c'] as $id) {
        $codes = SmtpConversation::play(test()->username, test()->password, SmtpConversation::message(['Message-ID' => "<{$id}@k.test>"]), test()->workspace);

        expect(end($codes))->toBe(250);
    }
});

/*
 | 4. PROXY protocol.
 */

dataset('proxy-fejlecek', [
    'v1 TCP4' => ["PROXY TCP4 203.0.113.7 10.0.0.1 56324 587\r\nEHLO x\r\n", '203.0.113.7'],
    'v1 TCP6' => ["PROXY TCP6 2001:db8::1 2001:db8::2 56324 587\r\n", '2001:db8::1'],
]);

it('a v1 PROXY fejlécből a VALÓDI forrás-IP jön', function (string $buffer, string $expected) {
    $header = ProxyProtocolHeader::parse($buffer);

    expect($header?->sourceIp)->toBe($expected);
})->with('proxy-fejlecek');

it('a v2 (bináris) PROXY fejlécet is értjük', function () {
    $signature = "\r\n\r\n\x00\r\nQUIT\n";
    $address = inet_pton('198.51.100.9').inet_pton('10.0.0.1').pack('n', 56_324).pack('n', 587);

    $buffer = $signature.chr(0x21).chr(0x11).pack('n', strlen($address)).$address."EHLO x\r\n";

    $header = ProxyProtocolHeader::parse($buffer);

    expect($header?->sourceIp)->toBe('198.51.100.9');
    expect($header?->consumedBytes)->toBe(16 + strlen($address));
});

it('a hiányzó vagy hamis fejléc ELUTASÍTÁS — nem esünk vissza a socket IP-jére', function () {
    // Fail-closed: különben a kliens maga dönthetné el, mikor küld PROXY fejlécet, és a
    // hiánya épp a per-IP fékek kikapcsolását jelentené.
    expect(fn () => ProxyProtocolHeader::parse("EHLO kliens.test\r\n"))->toThrow(InvalidProxyHeader::class);
    // A `PROXY UNKNOWN` és a v2 LOCAL a load balancer SAJÁT kapcsolata (health check):
    // ezeket eldobni azt jelentené, hogy az LB kiveszi a backendet a rotációból. Nincs
    // továbbított forrás, tehát a socket peer-címe marad érvényes.
    $unknown = ProxyProtocolHeader::parse("PROXY UNKNOWN\r\n");

    expect($unknown?->local)->toBeTrue();
    expect($unknown?->sourceIp)->toBe('');
    expect(fn () => ProxyProtocolHeader::parse("PROXY TCP4 nem-ip 10.0.0.1 1 2\r\n"))->toThrow(InvalidProxyHeader::class);
});

it('a félbe érkezett fejlécre VÁRUNK, nem hibázunk', function () {
    // A TCP darabolhat: a fél fejléc nem hiba, csak még nincs kész.
    expect(ProxyProtocolHeader::parse('PROXY TCP4 203.0.113.7 10.0'))->toBeNull();
});

it('a PROXY fejléc CSAK megbízható forrásból hihető', function () {
    // Enélkül aki közvetlenül eléri a portot (LB megkerülése), kapcsolatonként új forrás-IP-t
    // hazudna — és mivel a brute-force számláló IP-kulcsú, a tiltás SOHA nem telne be:
    // a feature a fék KIKAPCSOLÁSA lenne.
    config(['smtp.proxy_protocol.enabled' => true, 'smtp.proxy_protocol.trusted_sources' => ['10.0.0.0/8']]);

    $server = app(StreamSubmissionServer::class);
    $trusted = new ReflectionMethod($server, 'isTrustedProxy');

    expect($trusted->invoke($server, '10.1.2.3'))->toBeTrue();
    expect($trusted->invoke($server, '203.0.113.9'))->toBeFalse();

    // ÜRES allowlist bekapcsolt protokoll mellett: fail-closed (konfigurációs hiba).
    config(['smtp.proxy_protocol.trusted_sources' => []]);

    expect($trusted->invoke($server, '10.1.2.3'))->toBeFalse();
});

it('a rövid PROXY-töredékre VÁRUNK, nem bontunk', function () {
    // A TCP darabolhat: a `PRO` még lehet érvényes fejléc eleje. Korábban ez legitim
    // kapcsolatot dobott el.
    foreach (['P', 'PRO', 'PROXY'] as $fragment) {
        expect(ProxyProtocolHeader::parse($fragment))->toBeNull();
    }
});

it('az IDN-címre tiltott cím UNICODE alakban is tiltva marad', function () {
    // REGRESSZIÓ-TESZT: a küldési út kanonikalizál, a suppression-lista viszont sokáig
    // nyers `strtolower`-rel dolgozott — így a bérlő `user@példa.hu` tiltása után a
    // `user@xn--plda-bpa.hu` címre KIMENT volna a levél.
    Suppression::create([
        'email' => 'user@példa.hu',
        'reason' => 'unsubscribe',
    ]);

    Domain::create([
        'name' => DomainNormalizer::canonical('példa.hu'),
        'status' => 'verified',
        'verified_at' => now(),
    ]);

    $codes = SmtpConversation::play(test()->username, test()->password, [
        'MAIL FROM:<no-reply@példa.hu>',
        'RCPT TO:<user@xn--plda-bpa.hu>',
        'DATA',
        'From: no-reply@példa.hu',
        '',
        'Szia',
        '.',
    ], test()->workspace);

    expect(end($codes))->toBe(550);
    expect(Message::firstOrFail()->reason)->toBe('SUPPRESSED');
});
