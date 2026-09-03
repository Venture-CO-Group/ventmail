<?php

declare(strict_types=1);

use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Protocol\SessionState;
use App\Modules\Smtp\Protocol\SmtpSession;
use App\Modules\Smtp\Testing\FakeSmtpAuthenticator;
use App\Modules\Smtp\Testing\RecordingSink;

/*
 | A2 · 11–13. kritérium — a submission protokoll.
 |
 | A tesztek SOCKET NÉLKÜL futnak: az állapotgép I/O-mentes (nincs benne socket, DB, facade
 | és `now()` sem), a transzport az egyetlen osztály, ami socketet lát. Így a `--parallel`
 | alatt sincs portfoglalás-ütközés, és a párbeszéd determinisztikus — épp azért, mert a
 | 12. kritérium (NYÍLT RELAY TILOS) bizonyítéka nem függhet hálózattól.
 */

/**
 * A teszt-hitelesítő jelszava FUTÁSIDŐBEN generált.
 *
 * Egy beégetett, jelszó-alakú sztring a titok-szkennernek találat — joggal: a kódból nem
 * lehet megállapítani, hogy dummy-e. A determinizmust az adja, hogy futáson belül ugyanaz.
 */
function smtpTestPassword(): string
{
    static $password = null;

    return $password ??= 'pw-'.bin2hex(random_bytes(8));
}

function smtpSession(bool $encrypted = true, ?RecordingSink $sink = null): SmtpSession
{
    return new SmtpSession(
        new FakeSmtpAuthenticator(['vm-teszt' => smtpTestPassword()]),
        $sink ?? new RecordingSink,
        $encrypted ? ChannelSecurity::encrypted('TLSv1.3') : ChannelSecurity::plaintext(),
        'smtp.ventmail.test',
    );
}

/** @param list<string> $lines */
function play(SmtpSession $session, array $lines): array
{
    $codes = [];

    foreach ($lines as $line) {
        foreach ($session->feed($line) as $reply) {
            $codes[] = $reply->code;
        }
    }

    return $codes;
}

function authLine(string $user = 'vm-teszt', ?string $password = null): string
{
    return 'AUTH PLAIN '.base64_encode("\0".$user."\0".($password ?? smtpTestPassword()));
}

it('a köszöntés 220, és a session még nem hitelesített', function () {
    $session = smtpSession();

    expect($session->greeting()->code)->toBe(220);
    expect($session->principal())->toBeNull();
});

it('hitelesített küldés végigmegy titkosított csatornán', function () {
    $sink = new RecordingSink;
    $session = smtpSession(sink: $sink);

    $codes = play($session, [
        'EHLO kliens.test',
        authLine(),
        'MAIL FROM:<no-reply@pelda.hu>',
        'RCPT TO:<cimzett@example.test>',
        'DATA',
        'From: no-reply@pelda.hu',
        'Subject: Teszt',
        '',
        'Szia',
        '.',
        'QUIT',
    ]);

    expect($codes)->toBe([250, 235, 250, 250, 354, 250, 221]);
    expect($sink->accepted)->toHaveCount(1);
    expect($sink->accepted[0]['envelope']->recipients)->toBe(['cimzett@example.test']);
    expect($sink->accepted[0]['raw'])->toContain('Subject: Teszt');
});

/*
 | 12. kritérium — NYÍLT RELAY TILOS (blocking).
 */

it('hitelesítés NÉLKÜL nincs küldés', function () {
    $sink = new RecordingSink;
    $session = smtpSession(sink: $sink);

    $codes = play($session, [
        'EHLO kliens.test',
        'MAIL FROM:<no-reply@pelda.hu>',
        'RCPT TO:<aldozat@example.test>',
        'DATA',
    ]);

    expect($codes)->toBe([250, 530, 503, 503]);
    expect($sink->accepted)->toBeEmpty();
    expect($session->state())->not->toBe(SessionState::Data);
});

it('az állapotgépben NINCS út a DATA állapotba hitelesítés nélkül', function () {
    // Nem egyetlen forgatókönyv: a parancs-ábécé MINDEN legfeljebb négy hosszú sorozata.
    // Ha valaki egy jövőbeli refaktorral átmenetet nyit, ez a teszt elbukik.
    $alphabet = [
        'EHLO x', 'HELO x', 'STARTTLS', 'RSET', 'NOOP', 'VRFY a@b.hu', 'HELP',
        'MAIL FROM:<a@pelda.hu>', 'RCPT TO:<b@example.test>', 'DATA', '.', 'AUTH LOGIN',
    ];

    $reached = [];

    foreach ($alphabet as $first) {
        foreach ($alphabet as $second) {
            foreach ($alphabet as $third) {
                foreach ($alphabet as $fourth) {
                    $session = smtpSession();
                    play($session, [$first, $second, $third, $fourth]);

                    if ($session->state() === SessionState::Data) {
                        $reached[] = implode(' | ', [$first, $second, $third, $fourth]);
                    }
                }
            }
        }
    }

    expect($reached)->toBeEmpty('hitelesítés nélkül DATA állapotba jutott: '.implode(' ;; ', array_slice($reached, 0, 3)));
});

it('a rossz jelszó nem hitelesít, és három hiba után bont', function () {
    $session = smtpSession();

    $codes = play($session, [
        'EHLO kliens.test',
        authLine(password: smtpTestPassword().'-hibas'),
        authLine(password: smtpTestPassword().'-hibas'),
        authLine(password: smtpTestPassword().'-hibas'),
    ]);

    expect($codes)->toBe([250, 535, 535, 421]);
    expect($session->shouldClose())->toBeTrue();
});

/*
 | 13. kritérium — plaintext auth TLS nélkül.
 */

it('titkosítatlan csatornán az EHLO nem hirdet AUTH-ot', function () {
    $session = smtpSession(encrypted: false);

    $lines = array_map(fn ($reply) => $reply->text, $session->feed('EHLO kliens.test'));

    expect(implode(' ', $lines))->toContain('STARTTLS');
    expect(implode(' ', $lines))->not->toContain('AUTH');
});

it('titkosítatlan csatornán az AUTH kísérlet 538', function () {
    // A hirdetés elhagyása ÖNMAGÁBAN nem védelem: a kliensek egy része hirdetés nélkül is
    // próbálkozik, és a jelszó ilyenkor titkosítatlanul menne át.
    $session = smtpSession(encrypted: false);

    expect(play($session, ['EHLO kliens.test', authLine()]))->toBe([250, 538]);
    expect(play($session, ['AUTH LOGIN']))->toBe([538]);
    expect($session->principal())->toBeNull();
});

it('a STARTTLS után a session MINDENT elfelejt', function () {
    // CVE-2011-0411 osztály: a támadó a titkosítatlan szakaszban parancsokat pipeline-oz,
    // amiket a szerver a TITKOSÍTOTT munkamenet elején hajtana végre.
    $session = smtpSession(encrypted: false);

    play($session, ['EHLO kliens.test', 'STARTTLS', 'MAIL FROM:<hamis@pelda.hu>']);

    expect($session->shouldStartTls())->toBeTrue();

    $session->securedWith(ChannelSecurity::encrypted('TLSv1.3'));

    // A titkosított szakaszban ELŐLRŐL kezdünk: a TLS előtt pipeline-ozott parancs NEM
    // érvényesül, és a hitelesítés is elveszett (`530`, nem `250`).
    expect(play($session, ['MAIL FROM:<no-reply@pelda.hu>']))->toBe([530]);
    expect($session->state())->toBe(SessionState::Greeting);
    expect($session->principal())->toBeNull();
});

/*
 | Limitek (ADR D4).
 */

it('a túl hosszú parancssor 500', function () {
    $session = smtpSession();

    expect(play($session, ['EHLO '.str_repeat('a', 600)]))->toBe([500]);
});

it('a méret-túllépést a MAIL FROM SIZE paraméterén azonnal jelezzük', function () {
    $session = smtpSession();

    $codes = play($session, [
        'EHLO kliens.test',
        authLine(),
        'MAIL FROM:<no-reply@pelda.hu> SIZE=99999999',
    ]);

    expect($codes)->toBe([250, 235, 552]);
});

it('a DATA közbeni méret-túllépés is 552, és nem ad át semmit', function () {
    $sink = new RecordingSink;
    $session = smtpSession(sink: $sink);

    play($session, ['EHLO kliens.test', authLine(), 'MAIL FROM:<no-reply@pelda.hu>', 'RCPT TO:<a@example.test>', 'DATA']);

    $codes = [];

    // A limit tesztben kicsi (config), így néhány sor is túllépi.
    config(['smtp.limits.max_message_bytes' => 500]);

    foreach (range(1, 20) as $i) {
        $codes = array_merge($codes, array_map(fn ($r) => $r->code, $session->feed(str_repeat('x', 100))));
    }

    // A `552` a lezáró PONTNÁL jön: a tranzakció addig tart. (Korábban azonnal jött, és
    // onnantól a törzs sorai parancsként futottak — lásd a command-injection tesztet.)
    expect($codes)->toBe([]);

    $codes = array_map(fn ($reply) => $reply->code, $session->feed('.'));

    expect($codes)->toBe([552]);
    expect($sink->accepted)->toBeEmpty();
});

it('a címzett-limit felett 452', function () {
    config(['sending.max_recipients' => 3]);

    $session = smtpSession();

    play($session, ['EHLO kliens.test', authLine(), 'MAIL FROM:<no-reply@pelda.hu>']);

    $codes = [];

    foreach (range(1, 5) as $i) {
        $codes[] = $session->feed("RCPT TO:<c{$i}@example.test>")[0]->code;
    }

    expect($codes)->toBe([250, 250, 250, 452, 452]);
});

it('a VRFY nem árul el címet', function () {
    // Címfelderítés: a `252` azt jelenti, „nem erősítem meg, de megpróbálom kézbesíteni".
    expect(play(smtpSession(), ['EHLO kliens.test', authLine(), 'VRFY admin@pelda.hu']))->toBe([250, 235, 252]);
});

it('az ismeretlen parancs 500, és nem bontja a sessiont', function () {
    $session = smtpSession();

    expect(play($session, ['EHLO kliens.test', 'BDAT 100']))->toBe([250, 500]);
    expect($session->shouldClose())->toBeFalse();
});

it('a hitelesítés előtti parancs-özön bont', function () {
    $session = smtpSession();

    $codes = play($session, array_fill(0, 15, 'NOOP'));

    expect(end($codes))->toBe(421);
    expect($session->shouldClose())->toBeTrue();
});

it('az AUTH sor SOHA nem kerül a naplóba', function () {
    // A base64-elt PLAIN sor MAGA a jelszó.
    $session = smtpSession();

    $session->feed('EHLO kliens.test');
    $session->feed(authLine());

    expect($session->transcript())->not->toContain(smtpTestPassword());
    expect(implode(' ', $session->transcript()))->toContain('AUTH PLAIN <redacted>');
});

it('a túlméretes levél után a TÖRZS NEM lesz parancs', function () {
    // Command injection: a méret-túllépés korábban AZONNAL visszavitt `Ready`-be, és
    // onnantól a levéltörzs SORAI parancsként futottak — egy MÁR HITELESÍTETT sessionben.
    // Aki felhasználói szövegből épít MIME-ot, tetszőleges TOVÁBBI levelet küldethetett.
    config(['smtp.limits.max_message_bytes' => 500]);

    $sink = new RecordingSink;
    $session = smtpSession(sink: $sink);

    play($session, ['EHLO kliens.test', authLine(), 'MAIL FROM:<no-reply@pelda.hu>', 'RCPT TO:<a@example.test>', 'DATA']);

    // A limit átlépése…
    foreach (range(1, 10) as $i) {
        $session->feed(str_repeat('x', 100));
    }

    // …majd a „törzsbe" írt parancsok: EGYIK sem érvényesülhet.
    $codes = play($session, [
        'MAIL FROM:<no-reply@pelda.hu>',
        'RCPT TO:<aldozat@gmail.com>',
        'DATA',
        'From: Bank',
        '',
        'Kattints ide',
    ]);

    expect($codes)->toBe([]);
    expect($session->state())->toBe(SessionState::Data);

    // A tranzakció csak a lezáró ponttal ér véget — ÉS elutasítással.
    expect(array_map(fn ($r) => $r->code, $session->feed('.')))->toBe([552]);
    expect($sink->accepted)->toBeEmpty();
});

it('a null-sender submission-porton nem legitim', function () {
    $session = smtpSession();

    expect(play($session, ['EHLO kliens.test', authLine(), 'MAIL FROM:<>']))->toBe([250, 235, 501]);
});

it('az elgépelt MAIL sorok nem fogyasztják a tranzakció-keretet', function () {
    $session = smtpSession();

    play($session, ['EHLO kliens.test', authLine()]);

    $codes = [];

    foreach (range(1, 60) as $i) {
        $codes[] = $session->feed('MAIL FROM: hianyzo-jelek')[0]->code;
    }

    expect(array_unique($codes))->toBe([501]);
    expect($session->shouldClose())->toBeFalse();
});
