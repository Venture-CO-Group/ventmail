<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Protocol\MxSession;
use App\Modules\Inbound\Services\InboundIngest;
use App\Modules\Inbound\Services\VerifiedInboundResolver;
use App\Modules\Inbound\Testing\FakeRawMailStore;
use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Services\AuthThrottle;
use App\Modules\Smtp\Transport\StreamSubmissionServer;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;

uses(DatabaseMigrations::class)->group('smtp-socket');

/*
 | SMTP SMUGGLING — VALÓDI SOCKETEN (CVE-2023-51764 osztálya).
 |
 | Ezt a rést a session-szintű tesztek NEM látják: a sor-szétvágás a TRANSZPORTBAN történik,
 | és pontosan ott dől el, mi számít sorvégnek. Az első javításunk után a csupasz LF zárva
 | volt, a `.\r\r\n` viszont MÉG MINDIG átment — mérve KÉT elfogadott üzenettel egyetlen
 | DATA-ból, a másodikban a támadó által választott borítékfeladóval.
 |
 | A veszély a DIFFERENCIÁL: egy szigorú upstream (Postfix `smtpd_forbid_bare_newline`, Exim)
 | ugyanabban a bájtsorban EGY üzenetet lát. Mivel a becsempészett tranzakció ugyanazon a
 | kapcsolaton, ugyanarról a — jellemzően SPF-authorizált — relay-IP-ről érkezik, a
 | hitelesítési eredmény a HAMISÍTOTT feladóhoz rendelődne.
 |
 | Külön csoportban fut: portot foglal.
 */

beforeEach(function () {
    Queue::fake();

    $this->store = new FakeRawMailStore;
    $this->app->instance(RawMailStore::class, $this->store);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'inbound_mx_status' => 'verified',
    ]);

    app(WorkspaceContext::class)->forget();

    config(['inbound.limits.max_buffered_bytes' => 1_048_576]);
});

/**
 * A VALÓDI transzportot futtatjuk, valódi TCP-n, és NYERS bájtokat küldünk — nem sorokat.
 * A rés pontosan abban volt, hogy mi számít sornak.
 */
function smuggle(string $payload): array
{
    $port = random_int(22_000, 22_999);

    $server = new StreamSubmissionServer(
        app(SmtpAuthenticator::class),
        app(SubmissionSink::class),
        app(AuthThrottle::class),
        sessionFactory: fn (ChannelSecurity $security) => new MxSession(
            new VerifiedInboundResolver,
            new InboundIngest(test()->store, app(WorkspaceContext::class)),
            $security,
            'mx.test',
        ),
        allowedPorts: [$port],
        configPrefix: 'inbound',
    );

    $client = null;
    $received = '';
    $sent = false;
    $deadline = microtime(true) + 10;

    $server->serve('127.0.0.1', $port, false, function () use ($port, $payload, &$client, &$received, &$sent, $deadline): bool {
        if (microtime(true) > $deadline) {
            return true;
        }

        if ($client === null) {
            $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $error, 1);

            if ($client === false) {
                $client = null;

                return false;
            }

            stream_set_blocking($client, false);

            return false;
        }

        $chunk = @fread($client, 8192);

        if (is_string($chunk) && $chunk !== '') {
            $received .= $chunk;

            if (! $sent) {
                // A TELJES párbeszédet EGY bájtsorként küldjük: a pipelining ugyanúgy
                // legitim, és a támadó is így küldene.
                fwrite($client, $payload);
                $sent = true;

                return false;
            }
        }

        // A szerver bonthatott is (500 + close): akkor sincs mit várni tovább.
        if ($sent && (feof($client) || $received !== '')) {
            $meta = stream_get_meta_data($client);

            if ($meta['unread_bytes'] === 0 && microtime(true) > $deadline - 9.0) {
                @fclose($client);

                return true;
            }
        }

        return false;
    });

    app(WorkspaceContext::class)->set(test()->workspace);

    return [
        'response' => $received,
        'messages' => InboundMessage::query()->get(['sender', 'recipient'])->toArray(),
    ];
}

it('a MALFORMÁLT sorvég nem csempészhet be második üzenetet', function (string $terminator) {
    // A becsempészett rész EGY bájtsorban jön a levéllel — a támadó is így küldené.
    $smuggled = "MAIL FROM:<tamado@evil.example>\r\n"
        ."RCPT TO:<support@pelda.hu>\r\n"
        ."DATA\r\n"
        ."Subject: Hamisitott\r\n\r\ncsempeszett\r\n.\r\n";

    $result = smuggle(
        "EHLO kuldo.example\r\n"
        ."MAIL FROM:<aldozat@bank.example>\r\n"
        ."RCPT TO:<support@pelda.hu>\r\n"
        ."DATA\r\n"
        ."Subject: Elso\r\n\r\ntorzs\r\n"
        .$terminator
        .$smuggled
    );

    // A TÁMADÓ borítékfeladója SOHA nem lehet elfogadott üzenet feladója.
    expect(array_column($result['messages'], 'sender'))->not->toContain('tamado@evil.example');

    // …és a kapcsolat protokoll-sértéssel bomlott, tehát a levél SEM ment át: a küldő
    // újrapróbálhat, most már szabályos sorvéggel.
    expect($result['messages'])->toHaveCount(0);
    expect($result['response'])->toContain('500');
})->with([
    // Csupasz LF: a Postfix/Exim 2023 decemberi javításának osztálya.
    'csupasz LF' => ".\n",
    // TÖBB záró CR: ez volt a MÁSODIK rés. Az első javításunk `rtrim`-mel vágta a záró
    // `\r`-eket, tehát a `.\r\r` is `.`-tá vált és lezárt — miközben egy szigorú upstream
    // ugyanabban a bájtsorban EGY üzenetet lát.
    'két CR' => ".\r\r\n",
    'három CR' => ".\r\r\r\n",
]);

it('a SZABÁLYOS lezárás után a pipeline-ozott parancsok új tranzakciót nyitnak', function () {
    // Ez NEM csempészés: a szabályosan lezárt DATA után a következő `MAIL FROM` egy új,
    // teljesen legitim tranzakció — és egy szigorú upstream is pontosan ezt látja. A
    // csempészés attól csempészés, hogy a KÉT OLDAL MÁST lát ugyanabban a bájtsorban.
    $result = smuggle(
        "EHLO kuldo.example\r\n"
        ."MAIL FROM:<elso@kuldo.example>\r\n"
        ."RCPT TO:<support@pelda.hu>\r\n"
        ."DATA\r\nSubject: Elso\r\n\r\ntorzs\r\n.\r\n"
        ."MAIL FROM:<masodik@kuldo.example>\r\n"
        ."RCPT TO:<support@pelda.hu>\r\n"
        ."DATA\r\nSubject: Masodik\r\n\r\ntorzs\r\n.\r\n"
    );

    expect($result['messages'])->toHaveCount(2);
    expect(array_column($result['messages'], 'sender'))
        ->toContain('elso@kuldo.example')
        ->toContain('masodik@kuldo.example');
});
