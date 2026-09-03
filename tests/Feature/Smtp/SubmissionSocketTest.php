<?php

declare(strict_types=1);

use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Protocol\SmtpSession;
use App\Modules\Smtp\Services\AuthThrottle;
use App\Modules\Smtp\Testing\FakeSmtpAuthenticator;
use App\Modules\Smtp\Testing\RecordingSink;
use App\Modules\Smtp\Transport\StreamSubmissionServer;

/*
 | A TRANSZPORT — valódi socketen (11. kritérium).
 |
 | A protokoll-tesztek szándékosan socket nélkül futnak; ez a teszt pont azt méri, ami ott
 | nem látszik: a sor-összefűzést a beolvasó pufferben, a többsoros EHLO tördelését
 | (`250-` folytatás, `250 ` lezárás), és a kapcsolat-életciklust. A korábbi kör három
 | transzport-hibája (blokkoló handshake, korlátlan puffer, hiányzó időkorlát) egyike sem
 | derült volna ki a protokoll-tesztekből.
 |
 | AMIT EZ A TESZT NEM FED: a TLS-handshake és a hitelesített küldés a dróton. A szerver és
 | a kliens ugyanabban a processzben fut, tehát egy blokkoló TLS-handshake holtpontot adna;
 | a hitelesítés pedig titkosított csatornát követel (golden rule 3). Az élő TLS-próba a
 | telepítés utáni füstteszt dolga — ezt kimondjuk, nem hallgatjuk el.
 |
 | Külön csoportban fut: portot foglal, ezért a párhuzamos alap-futásból kizárva.
 */

/**
 * A szervert és a klienst EGY processzben futtatjuk: a szerver `shouldStop` callbackje
 * lépteti a kliens párbeszédét, majd leállítja a ciklust.
 *
 * @param  list<string>  $script  a kliens sorai
 * @return list<string> a szerver válaszai
 */
beforeEach(function () {
    // A teszt-futás `memory_limit`-je 128 MB: az éles alapérték (128 MB puffer-plafon) nem
    // férne bele az indulási ellenőrzésbe. A plafon LOGIKÁJÁT külön teszt méri.
    config(['smtp.limits.max_buffered_bytes' => 1_048_576]);
});

function socketTestPassword(): string
{
    static $password = null;

    return $password ??= 'pw-'.bin2hex(random_bytes(8));
}

function converse(array $script, ?RecordingSink $sink = null): array
{
    $port = random_int(21_000, 21_999);

    $server = new StreamSubmissionServer(
        new FakeSmtpAuthenticator(['vm-teszt' => socketTestPassword()]),
        $sink ?? new RecordingSink,
        app(AuthThrottle::class),
    );

    config(['smtp.allowed_ports' => [$port]]);

    $client = null;
    $received = [];
    $pending = $script;
    $deadline = microtime(true) + 10;

    $server->serve('127.0.0.1', $port, false, function () use ($port, &$client, &$received, &$pending, $deadline): bool {
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
            $received[] = $chunk;

            // Csak a LEZÁRÓ sor után lépünk tovább (a többsoros EHLO közben nem).
            if (preg_match('/^\d{3} /m', $chunk) !== 1) {
                return false;
            }

            if ($pending === []) {
                fclose($client);

                return true;
            }

            fwrite($client, array_shift($pending)."\r\n");
        }

        return false;
    });

    return $received;
}

it('a valódi socketen megjön a köszöntés és a többsoros EHLO', function () {
    $lines = converse(['EHLO kliens.test', 'QUIT']);

    $all = implode('', $lines);

    expect($all)->toContain('220');
    // A tördelés: folytatás `250-`, lezárás `250 `. Ha ez elromlik, a kliensek beragadnak.
    expect($all)->toContain('250-');
    expect($all)->toMatch('/250 [^\-]/');
    expect($all)->toContain('STARTTLS');
    // Titkosítatlan csatornán az AUTH-ot nem hirdetjük.
    expect($all)->not->toContain('AUTH PLAIN');
})->group('smtp-socket');

it('a valódi socketen sem lehet hitelesítés nélkül küldeni', function () {
    $sink = new RecordingSink;

    $lines = converse(['EHLO kliens.test', 'MAIL FROM:<no-reply@pelda.hu>', 'QUIT'], $sink);

    expect(implode('', $lines))->toContain('530');
    expect($sink->accepted)->toBeEmpty();
})->group('smtp-socket');

it('a valódi socketen a plaintext AUTH 538', function () {
    $lines = converse([
        'EHLO kliens.test',
        'AUTH PLAIN '.base64_encode("\0vm-teszt\0".socketTestPassword()),
        'QUIT',
    ]);

    expect(implode('', $lines))->toContain('538');
})->group('smtp-socket');

it('a félbehagyott TLS-handshake NEM viszi el a listenert', function () {
    // Egyetlen hitelesítetlen kapcsolat: `STARTTLS`, majd csonka handshake. A handshake
    // időkorlátja korábban `close()`-t hívott, UTÁNA viszont egy értékadás ÚJRA létrehozta
    // a bejegyzést socket és session nélkül — a következő körben a processz meghalt, és a
    // `restart: unless-stopped` végtelen újraindulási ciklust adott.
    config(['smtp.limits.command_timeout_seconds' => 1]);

    $lines = converse(['EHLO kliens.test', 'STARTTLS', 'ez-nem-tls-handshake', 'EHLO ujra', 'QUIT']);

    // A lényeg nem a válasz, hanem hogy a ciklus TÚLÉLTE: a `converse()` a saját
    // határidejéig fut, és visszatér — összeomlás esetén végzetes hibával szállna el.
    expect($lines)->not->toBeEmpty();

    // És a listener a következő kapcsolatot is kiszolgálja.
    expect(implode('', converse(['EHLO ujabb-kliens.test', 'QUIT'])))->toContain('250');
})->group('smtp-socket');

it('a tarpitolt kapcsolat NEM égeti a CPU-t és nem állítja meg a többit', function () {
    // A `usleep` helyett kapcsolat-szintű időbélyeg van — de ha a tarpitolt socket bent
    // maradna a select olvasási halmazában, a ciklus minden körben azonnal visszatérne
    // (mérve ~100% mag). A fék maga lett volna a DoS.
    $server = new StreamSubmissionServer(
        new FakeSmtpAuthenticator(['vm-teszt' => socketTestPassword()]),
        new RecordingSink,
        app(AuthThrottle::class),
    );

    $reflection = new ReflectionClass($server);
    $connections = $reflection->getProperty('connections');

    // Egy „tarpitolt" bejegyzés a jövőbe állított időbélyeggel.
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    $connections->setValue($server, [
        (int) $a => [
            'socket' => $a,
            'session' => new SmtpSession(
                new FakeSmtpAuthenticator([]),
                new RecordingSink,
                ChannelSecurity::plaintext(),
                'smtp.test',
            ),
            'buffer' => '',
            'touched' => microtime(true),
            'started' => microtime(true),
            'ip' => '127.0.0.1',
            'handshaking' => false,
            'handshakeStarted' => 0.0,
            'readableAfter' => microtime(true) + 5,
        ],
    ]);

    // Adat a dróton, amit NEM olvasunk el: ha a socket a select-halmazban lenne, a
    // következő `tick()` azonnal visszatérne.
    fwrite($b, "NOOP\r\n");

    $tick = $reflection->getMethod('tick');
    $server2 = @stream_socket_server('tcp://127.0.0.1:0');

    $start = microtime(true);
    $tick->invoke($server, $server2, false);
    $elapsed = microtime(true) - $start;

    fclose($a);
    fclose($b);
    fclose($server2);

    // A select a 200 ms-os időkorlátjáig vár — nem pörög.
    expect($elapsed)->toBeGreaterThan(0.1, "a tick() {$elapsed} mp alatt tért vissza: a tarpitolt socket a select-halmazban maradt");
})->group('smtp-socket');


it('a listener EL SEM INDUL, ha a puffer-plafon nem fér a memory_limitbe', function () {
    // A `php:8.3-fpm-alpine` nem szállít aktív `php.ini`-t: az érvényes limit a beépített
    // 128 MB. A korábbi 256 MB-os plafon így SOHA nem sült volna el — a PHP OOM-fatal
    // előbb jött, azt pedig try/catch nem fogja, tehát a listener meghalt.
    config(['smtp.limits.max_buffered_bytes' => 512 * 1024 * 1024]);

    $server = new StreamSubmissionServer(
        new FakeSmtpAuthenticator([]),
        new RecordingSink,
        app(AuthThrottle::class),
    );

    config(['smtp.allowed_ports' => [2587]]);

    expect(fn () => $server->serve('127.0.0.1', 2587, false, fn () => true))
        ->toThrow(RuntimeException::class);
})->group('smtp-socket');
