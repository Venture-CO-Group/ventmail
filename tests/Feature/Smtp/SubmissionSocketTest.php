<?php

declare(strict_types=1);

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
