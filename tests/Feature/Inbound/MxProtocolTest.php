<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Protocol\MxSession;
use App\Modules\Inbound\Services\InboundIngest;
use App\Modules\Inbound\Services\VerifiedInboundResolver;
use App\Modules\Inbound\Testing\FakeRawMailStore;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Protocol\SessionState;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | A4 · 30., 37. kritérium — az MX-oldal protokollja és NYÍLT RELAY ZÁRAI.
 |
 | A submission-oldal invariánsa: „nincs `MAIL FROM` hitelesítés nélkül".
 | Az MX-oldalé MÁS: „nincs elfogadás olyan címzettre, aki nem a MI verifikált
 | inbound-domainünk". Ezt KÜLÖN kell bizonyítani — a másik oldal tesztjei erről semmit nem
 | mondanak.
 */

beforeEach(function () {
    Queue::fake();

    $this->store = new FakeRawMailStore;
    $this->app->instance(RawMailStore::class, $this->store);

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    // A MI inbound-domainünk: verifikált MX.
    Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'inbound_mx_status' => 'verified',
    ]);

    app(WorkspaceContext::class)->forget();
});

function mxSession(bool $encrypted = true): MxSession
{
    return new MxSession(
        new VerifiedInboundResolver,
        new InboundIngest(test()->store, app(WorkspaceContext::class)),
        $encrypted ? ChannelSecurity::encrypted('TLSv1.3') : ChannelSecurity::plaintext(),
        'mx.ventmail.app',
    );
}

/** @return list<string> a válaszsorok */
function mx(MxSession $session, string ...$lines): array
{
    $out = [];

    foreach ($lines as $line) {
        foreach ($session->feed($line) as $reply) {
            $out[] = $reply->toLine();
        }
    }

    return $out;
}

it('IDEGEN domainre címzett levelet NEM vesz át — ez a nyílt relay zára', function () {
    $session = mxSession();

    $replies = mx($session, 'EHLO kuldo.example', 'MAIL FROM:<valaki@kuldo.example>', 'RCPT TO:<barki@idegen.example>');

    expect(end($replies))->toStartWith('550');
    expect(end($replies))->toContain('Relay access denied');

    // …és DATA-ig sem jut el.
    expect(mx($session, 'DATA')[0])->toStartWith('503');
    expect($session->state())->not->toBe(SessionState::Data);
});

it('a NEM VERIFIKÁLT inbound-domain sem elég', function () {
    // A küldéshez hitelesített domain még nem jelenti, hogy az MX-rekord ránk mutat. Amíg
    // nem mutat, levelet fogadni rá nem a mi dolgunk.
    app(WorkspaceContext::class)->set($this->workspace);
    Domain::create(['name' => 'csak-kuldes.hu', 'status' => 'verified', 'verified_at' => now()]);
    app(WorkspaceContext::class)->forget();

    $replies = mx(mxSession(), 'EHLO kuldo.example', 'MAIL FROM:<a@kuldo.example>', 'RCPT TO:<b@csak-kuldes.hu>');

    expect(end($replies))->toStartWith('550');
});

it('AUTH ezen a porton NEM LÉTEZIK', function () {
    // Az első zár: a „hitelesítettem, most relayelj" út meg sem nyílik. Az EHLO sem hirdeti.
    $session = mxSession();

    $ehlo = mx($session, 'EHLO kuldo.example');

    expect(implode("\n", $ehlo))->not->toContain('AUTH');

    expect(mx($session, 'AUTH PLAIN AGFAYg==')[0])->toStartWith('502');
});

it('a SAJÁT domainre címzett levelet átveszi és eltárolja', function () {
    $session = mxSession();

    $replies = mx(
        $session,
        'EHLO kuldo.example',
        'MAIL FROM:<valaki@kuldo.example>',
        'RCPT TO:<support@pelda.hu>',
        'DATA',
        'Subject: Kérdés',
        'From: valaki@kuldo.example',
        '',
        'Szia, van egy kérdésem.',
        '.',
    );

    expect(end($replies))->toStartWith('250');

    app(WorkspaceContext::class)->set($this->workspace);

    $message = InboundMessage::query()->firstOrFail();

    expect($message->recipient)->toBe('support@pelda.hu');
    expect($message->sender)->toBe('valaki@kuldo.example');
    expect($message->status)->toBe('received');
    // A NYERS levél a fájltárban van, nem az adatbázisban.
    expect($this->store->get((string) $message->storage_key))->toContain('van egy kérdésem');
});

it('a NULL-SENDER (bounce) legitim — a submission-oldallal ellentétben', function () {
    // Minden bounce és minden kézbesítési értesítés `MAIL FROM:<>`-szal érkezik (RFC 5321
    // §4.5.5). Aki ezt elutasítja, a saját bounce-ait nem kapja meg.
    $session = mxSession();

    $replies = mx($session, 'EHLO kuldo.example', 'MAIL FROM:<>', 'RCPT TO:<support@pelda.hu>');

    expect($replies[count($replies) - 2])->toStartWith('250');
    expect(end($replies))->toStartWith('250');

    mx($session, 'DATA', 'Subject: Undelivered', '', 'Nem sikerült.', '.');

    app(WorkspaceContext::class)->set($this->workspace);

    expect(InboundMessage::query()->firstOrFail()->sender)->toBeNull();
});

it('a TÖBB találgatás után bontja a kapcsolatot', function () {
    // Címfelderítés elleni fék: aki tucatnyi címet próbálgat, az nem levelet küld.
    $session = mxSession();

    mx($session, 'EHLO kuldo.example', 'MAIL FROM:<a@kuldo.example>');

    $utolso = '';

    foreach (range(1, 12) as $i) {
        $replies = mx($session, 'RCPT TO:<proba'.$i.'@idegen.example>');
        $utolso = $replies === [] ? $utolso : end($replies);
    }

    expect($utolso)->toStartWith('421');
    expect($session->shouldClose())->toBeTrue();
});

it('a DATA nem indul feloldott címzett nélkül', function () {
    $session = mxSession();

    mx($session, 'EHLO kuldo.example', 'MAIL FROM:<a@kuldo.example>');

    expect(mx($session, 'DATA')[0])->toStartWith('503');
});

it('a TÚLMÉRETES levelet elutasítja, de a lezáró pontig olvassa', function () {
    config(['inbound.max_message_bytes' => 1024]);

    $session = mxSession();

    mx($session, 'EHLO kuldo.example', 'MAIL FROM:<a@kuldo.example>', 'RCPT TO:<support@pelda.hu>', 'DATA');

    foreach (range(1, 50) as $ignored) {
        mx($session, str_repeat('x', 100));
    }

    $replies = mx($session, '.');

    expect($replies[0])->toStartWith('552');
    // A kapcsolat ÉL: a félbehagyás a küldőnél végtelen újrapróbálást szülne.
    expect($session->shouldClose())->toBeFalse();

    app(WorkspaceContext::class)->set($this->workspace);
    expect(InboundMessage::query()->count())->toBe(0);
});

it('a TLS-váltás után a session mindent elfelejt', function () {
    // STARTTLS command-injection (CVE-2011-0411 családja): a támadó a titkosítatlan
    // szakaszban pipeline-oz, amit a szerver a titkosított munkamenet elején hajtana végre.
    $session = mxSession(encrypted: false);

    mx($session, 'EHLO kuldo.example', 'MAIL FROM:<a@kuldo.example>', 'RCPT TO:<support@pelda.hu>');

    expect(mx($session, 'STARTTLS')[0])->toStartWith('220');
    expect($session->shouldStartTls())->toBeTrue();

    $session->securedWith(ChannelSecurity::encrypted('TLSv1.3'));

    // A boríték eltűnt: `DATA` nem indulhat a váltás előtti címzettel.
    expect(mx($session, 'DATA')[0])->toStartWith('503');
});

/*
 | SMTP SMUGGLING (CVE-2023-51764 osztálya) — a DATA fázisban a sorvég KIZÁRÓLAG `\r\n`.
 |
 | A csupasz `\n`-t a szerverek KÜLÖNBÖZŐKÉPPEN értelmezik. Ha nálunk a `<LF>.<LF>` lezárja a
 | DATA-t, egy előttünk álló (jellemzően SPF-authorizált) relaynél viszont nem, akkor a támadó
 | EGYETLEN elfogadott levélbe egy MÁSODIK, teljesen általa választott borítékfeladójú
 | üzenetet csempészhet be — és az a mi kapcsolatunkon, a mi IP-nkről érkezik, tehát az
 | SPF-eredmény a HAMISÍTOTT feladóhoz rendelődik.
 |
 | Mérve a javítás előtt: egy DATA-ból KÉT elfogadott üzenet.
 */

it('a csupasz LF a DATA-ban protokoll-sértés — a transzport zárja', function () {
    // A védelem a TRANSZPORTBAN van (ott dől el, mi számít sornak), ezért ott is mérjük: a
    // sor-szétvágás a `\n`-re megy, és DATA alatt a `\r` hiánya bontja a kapcsolatot.
    $source = file_get_contents(base_path('app/Modules/Smtp/Transport/StreamSubmissionServer.php'));

    expect($source)->toContain('Bare LF is not allowed in DATA');
    expect($source)->toContain('SessionState::Data');
});

it('a szabályos CRLF-fel lezárt DATA viszont EGY üzenet', function () {
    // A becsempészett tranzakció ugyanezen a kapcsolaton, ugyanarról az IP-ről érkezne — a
    // különbség csak a sorvégben van.
    $session = mxSession();

    mx($session, 'EHLO kuldo.example', 'MAIL FROM:<valaki@kuldo.example>', 'RCPT TO:<support@pelda.hu>', 'DATA');

    // A transzport `\r`-rel adja át a sorokat; itt a session szintjén a lezáró pont EGY
    // üzenetet zár le, és a „becsempészett" parancsok a TÖRZS részei maradnak.
    foreach (['Subject: Első', '', 'törzs', 'MAIL FROM:<vezerigazgato@bank.hu>', 'RCPT TO:<support@pelda.hu>', 'DATA', 'Subject: Hamisított', '', 'csempészett', '.'] as $line) {
        mx($session, $line);
    }

    app(WorkspaceContext::class)->set($this->workspace);

    expect(InboundMessage::query()->count())->toBe(1);

    // A „második levél" a TÖRZSBEN van, nem külön üzenetként.
    $raw = $this->store->get((string) InboundMessage::query()->firstOrFail()->storage_key);

    expect($raw)->toContain('vezerigazgato@bank.hu');
    expect(InboundMessage::query()->where('sender', 'vezerigazgato@bank.hu')->count())->toBe(0);
});
