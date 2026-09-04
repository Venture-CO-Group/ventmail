<?php

declare(strict_types=1);

use App\Modules\Inbound\Console\ServeMxCommand;
use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Contracts\InboundSink;
use App\Modules\Inbound\Protocol\MxSession;
use App\Modules\Smtp\Console\ServeSubmissionCommand;
use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Contracts\SmtpConnection;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Protocol\SmtpSession;
use App\Modules\Smtp\Services\AuthThrottle;
use App\Modules\Smtp\Transport\StreamSubmissionServer;

/*
 | A4 — a KÉT LISTENER szétválasztása.
 |
 | A transzport közös (socket, TLS, időkorlát, per-IP keret), a protokoll-szabály nem. A
 | veszély az összemosás: ha a submission-listener a 25-ösön indulhatna, egy elgépelt
 | `--port` a hitelesítés-kötelező oldalt tenné ki a világnak — vagy fordítva, az MX-oldal
 | (ahol nincs hitelesítés) kerülne a submission portjára.
 */

it('a submission-listener NEM indulhat a 25-ösön', function () {
    $server = app(StreamSubmissionServer::class);

    expect(fn () => $server->serve('127.0.0.1', 25, false))
        ->toThrow(RuntimeException::class);
});

it('az MX-listener NEM indulhat a submission portjain', function () {
    // A port-politika a konstruktorban dől el, nem a parancsban: aki a szervert máshonnan
    // példányosítja, ugyanazt a korlátot kapja.
    $server = new StreamSubmissionServer(
        app(SmtpAuthenticator::class),
        app(SubmissionSink::class),
        app(AuthThrottle::class),
        sessionFactory: fn ($security) => new MxSession(
            app(InboundRecipientResolver::class),
            app(InboundSink::class),
            $security,
            'mx.test',
        ),
        allowedPorts: (array) config('inbound.allowed_ports'),
    );

    foreach ([587, 465] as $port) {
        expect(fn () => $server->serve('127.0.0.1', $port, false))->toThrow(RuntimeException::class);
    }
});

it('a két port-lista NEM fedi egymást', function () {
    // Ha bármelyik port mindkét listán szerepelne, a két bizalmi modell összecsúszna.
    $submission = (array) config('smtp.allowed_ports');
    $inbound = (array) config('inbound.allowed_ports');

    expect(array_intersect($submission, $inbound))->toBe([]);
    expect($submission)->not->toContain(25);
    expect($inbound)->toContain(25);
});

it('mindkét állapotgép ugyanazt a transzport-interfészt teljesíti', function () {
    // Ez teszi lehetővé, hogy a hardened transzportot NE másoljuk le: egy kód, két szabály.
    expect(is_subclass_of(SmtpSession::class, SmtpConnection::class))->toBeTrue();
    expect(is_subclass_of(MxSession::class, SmtpConnection::class))->toBeTrue();
});

it('az MX-parancs a bejövő portokra, a submission a sajátjaira van kötve', function () {
    // A parancsok forrásában NEM lehet fix, összekevert port: a politika a configból jön.
    $mx = file_get_contents((new ReflectionClass(ServeMxCommand::class))->getFileName());
    $submission = file_get_contents((new ReflectionClass(ServeSubmissionCommand::class))->getFileName());

    expect($mx)->toContain("config('inbound.allowed_ports')");
    expect($mx)->toContain('MxSession');
    expect($submission)->not->toContain('MxSession');
});
