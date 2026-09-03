<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Transport;

use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\Smtp\Protocol\SessionState;
use App\Modules\Smtp\Protocol\SmtpSession;
use App\Modules\Smtp\Services\AuthThrottle;
use RuntimeException;
use Throwable;

/**
 * A transzport — az EGYETLEN osztály, ami socketet lát (ADR 0001, D2).
 *
 * Natív PHP stream-eken fut, KÜLSŐ FÜGGŐSÉG NÉLKÜL. Az ADR ReactPHP-t javasolt, és nyitott
 * kérdésként hagyta a csomag jóváhagyását; kiderült, hogy nincs rá szükség: a `select`
 * alapú ciklus, a TLS és a STARTTLS-emelés mind benne van a nyelvben. Kevesebb
 * supply-chain felület, és a döntés visszafordítható — a protokoll-állapotgép nem tud a
 * transzportról, tehát a csere ide, egyetlen fájlba korlátozódik.
 *
 * Ami itt VAN: elfogadás, olvasás, időkorlát, TLS. Ami itt NINCS: protokoll-szabály,
 * hitelesítés, gate, adatbázis. Ez a szétvágás teszi lehetővé, hogy a 11–15. kritérium
 * socket nélkül, determinisztikusan tesztelhető legyen.
 */
final class StreamSubmissionServer
{
    /** @var array<int, array{socket: resource, session: SmtpSession, buffer: string, touched: float, started: float, ip: string, handshaking: bool, handshakeStarted: float}> */
    private array $connections = [];

    private bool $running = false;

    public function __construct(
        private readonly SmtpAuthenticator $authenticator,
        private readonly SubmissionSink $sink,
        private readonly AuthThrottle $throttle,
    ) {}

    /**
     * @param  int  $port  a `config('smtp.allowed_ports')` egyike — a 25 SZÁNDÉKOSAN nincs köztük
     * @param  bool  $implicitTls  465: a kapcsolat már TLS-sel indul; 587: STARTTLS-szel emelünk
     */
    public function serve(string $host, int $port, bool $implicitTls, ?callable $shouldStop = null): void
    {
        if (! in_array($port, (array) config('smtp.allowed_ports'), true)) {
            // A port 25 a BEJÖVŐ (MX) oldalé — más parser, más bizalmi modell (A4).
            throw new RuntimeException(sprintf(
                'A(z) %d port nem engedélyezett submissionre (engedélyezett: %s). A 25-ös port az A4 (MX) dolga.',
                $port,
                implode(', ', (array) config('smtp.allowed_ports'))
            ));
        }

        $context = stream_context_create($implicitTls ? ['ssl' => $this->tlsOptions()] : []);

        $server = @stream_socket_server(
            ($implicitTls ? 'tlsv1.2://' : 'tcp://').$host.':'.$port,
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ($server === false) {
            throw new RuntimeException(sprintf('Nem sikerült a %s:%d portra kötni: %s', $host, $port, $errorMessage));
        }

        stream_set_blocking($server, false);

        $this->running = true;

        while ($this->running) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            $this->tick($server, $implicitTls);
        }

        fclose($server);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** @param resource $server */
    private function tick(mixed $server, bool $implicitTls): void
    {
        $read = [$server];

        foreach ($this->connections as $connection) {
            $read[] = $connection['socket'];
        }

        $write = $except = [];

        if (@stream_select($read, $write, $except, 0, 200_000) === false) {
            return;
        }

        foreach ($read as $stream) {
            $stream === $server
                ? $this->accept($server, $implicitTls)
                : $this->readFrom($stream);
        }

        $this->expire();
    }

    /** @param resource $server */
    private function accept(mixed $server, bool $implicitTls): void
    {
        $socket = @stream_socket_accept($server, 0, $peer);

        if ($socket === false) {
            return;
        }

        $ip = $this->ipOf((string) $peer);

        // Kapcsolat-plafon: felette 421 + bontás. Ez BACKPRESSURE, nem OOM — egy
        // egyszálas ciklusban a memória-kimerítés az egész listenert viszi.
        if (count($this->connections) >= (int) config('smtp.limits.max_connections')) {
            @fwrite($socket, "421 4.7.0 Too many connections\r\n");
            @fclose($socket);

            return;
        }

        // Forrás-IP-nkénti plafon: enélkül EGY forrás lefoglalja mind a 200 slotot, és
        // percenként egy bájttal határtalanul tartja őket (lassú-loris).
        $fromIp = count(array_filter($this->connections, static fn (array $c) => $c['ip'] === $ip));

        if ($fromIp >= (int) config('smtp.limits.max_connections_per_ip')) {
            @fwrite($socket, "421 4.7.0 Too many connections from your address\r\n");
            @fclose($socket);

            return;
        }

        if ($this->throttle->tooManyFailures($ip)) {
            @fwrite($socket, "421 4.7.0 Too many authentication failures from this address\r\n");
            @fclose($socket);

            return;
        }

        stream_set_blocking($socket, false);

        $session = new SmtpSession(
            $this->authenticator,
            $this->sink,
            $implicitTls ? ChannelSecurity::encrypted('tls') : ChannelSecurity::plaintext(),
            (string) config('smtp.hostname'),
        );

        $this->connections[(int) $socket] = [
            'socket' => $socket,
            'session' => $session,
            'buffer' => '',
            'touched' => microtime(true),
            'started' => microtime(true),
            'ip' => $ip,
            'handshaking' => false,
            'handshakeStarted' => 0.0,
        ];

        $this->write($socket, $session->greeting()->toLine());
    }

    /** @param resource $socket */
    private function readFrom(mixed $socket): void
    {
        $id = (int) $socket;
        $connection = $this->connections[$id] ?? null;

        if ($connection === null) {
            return;
        }

        // Félbemaradt TLS-handshake: NEM blokkolunk rajta, hanem a következő körben
        // folytatjuk. Blokkoló handshake mellett egyetlen néma kliens megállítaná az
        // EGÉSZ listenert — hitelesítés előtt, egy csomagból.
        if ($connection['handshaking']) {
            $this->continueHandshake($id);

            return;
        }

        $chunk = @fread($socket, 8192);

        if ($chunk === false || $chunk === '') {
            if (feof($socket)) {
                $this->close($id);
            }

            return;
        }

        $connection['buffer'] .= $chunk;
        $connection['touched'] = microtime(true);
        $this->connections[$id] = $connection;

        // A parancssor-limit CSAK akkor érvényesül, ha van `\n`. Újsor nélkül öntött adat
        // korlátlanul növelte volna a puffert — a hosszúsor-védelem sosem futott volna le.
        $maxLine = (int) config('smtp.limits.max_auth_bytes');

        if (! str_contains($this->connections[$id]['buffer'], "\n") && strlen($this->connections[$id]['buffer']) > $maxLine) {
            $this->write($socket, '500 5.5.2 Line too long');
            $this->close($id);

            return;
        }

        while (($position = strpos($this->connections[$id]['buffer'], "\n")) !== false) {
            $line = substr($this->connections[$id]['buffer'], 0, $position);
            $this->connections[$id]['buffer'] = substr($this->connections[$id]['buffer'], $position + 1);

            $this->handle($id, rtrim($line, "\r"));

            if (! isset($this->connections[$id])) {
                return;
            }
        }
    }

    private function handle(int $id, string $line): void
    {
        $connection = $this->connections[$id];
        $session = $connection['session'];

        try {
            $replies = $session->feed($line);
        } catch (Throwable $e) {
            report($e);

            $this->write($connection['socket'], '451 4.3.0 Temporary failure');
            $this->close($id);

            return;
        }

        foreach ($replies as $reply) {
            // A sikertelen hitelesítés a PROCESSZEK KÖZÖTT osztott fékbe is beszámít:
            // a session-szintű három hiba önmagában semmit nem ér, mert a támadó új
            // kapcsolatot nyit.
            if ($reply->code === 535) {
                $this->throttle->recordFailure($connection['ip']);

                // TARPIT: a próbálgatás legolcsóbb fékje a LASSÍTÁS. Egy jóhiszemű
                // kliensnek egy másodperc semmi, a szótár-támadásnak viszont minden.
                if ($this->throttle->shouldTarpit($connection['ip'])) {
                    usleep(1_000_000);
                }
            }

            if ($reply->code === 235) {
                $this->throttle->clear($connection['ip']);

                // Hitelesítő-szintű nyom: mikor és HONNAN használták (a nyers IP-t nem
                // tároljuk — kulcsolt hash megy).
                $this->recordUsage($connection['session'], $connection['ip']);
            }

            $this->write($connection['socket'], $reply->toLine());
        }

        if ($session->shouldStartTls()) {
            $this->upgrade($id);

            return;
        }

        if ($session->shouldClose()) {
            $this->close($id);
        }
    }

    /**
     * TLS-emelés — NEM BLOKKOLVA.
     *
     * A blokkoló `stream_socket_enable_crypto()` az egyszálas ciklus közepén azt jelentené,
     * hogy egy néma kliens (aki elküldi a `STARTTLS`-t, majd egy bájtot sem küld) megállítja
     * az EGÉSZ listenert — a többi kapcsolat egyetlen parancsot sem kapna kiszolgálva.
     * Hitelesítés előtt, egy csomagból, teljes szolgáltatás-kiesés.
     *
     * Nem-blokkoló módban a hívás `0`-t ad, ha a handshake még tart: visszatérünk a
     * ciklusba, és a következő olvasható eseménynél folytatjuk — abszolút határidővel.
     */
    /** A hitelesítő utolsó használata — nyers IP nélkül. */
    private function recordUsage(SmtpSession $session, string $ip): void
    {
        $principal = $session->principal();

        if ($principal === null) {
            return;
        }

        try {
            SmtpCredential::findByUuid($principal->credentialUuid)?->touchUsage($ip);
        } catch (Throwable $e) {
            // A nyom hiánya nem állíthatja meg a küldést.
            report($e);
        }
    }

    private function upgrade(int $id): void
    {
        $connection = $this->connections[$id];

        foreach ($this->tlsOptions() as $option => $value) {
            stream_context_set_option($connection['socket'], 'ssl', $option, $value);
        }

        $connection['handshaking'] = true;
        $connection['handshakeStarted'] = microtime(true);
        $connection['buffer'] = '';

        $this->connections[$id] = $connection;

        $this->continueHandshake($id);
    }

    private function continueHandshake(int $id): void
    {
        $connection = $this->connections[$id];

        $result = @stream_socket_enable_crypto($connection['socket'], true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if ($result === 0) {
            // Még tart. Csak a HATÁRIDŐT nézzük — a lassú-loris itt is ugyanolyan olcsó.
            if (microtime(true) - $connection['handshakeStarted'] > (int) config('smtp.limits.command_timeout_seconds')) {
                $this->close($id);
            }

            $this->connections[$id]['touched'] = microtime(true);

            return;
        }

        if ($result !== true) {
            $this->close($id);

            return;
        }

        // A TLS-váltás után a session MINDENT elfelejt (STARTTLS command injection).
        $connection['session']->securedWith(ChannelSecurity::encrypted('tls'));
        $connection['handshaking'] = false;
        $connection['buffer'] = '';
        $connection['touched'] = microtime(true);

        $this->connections[$id] = $connection;
    }

    /**
     * Időkorlátok — HÁROM, mert három különböző visszaélést fognak.
     *
     * A tétlenségi korlát önmagában nem elég: aki percenként egy bájtot küld, azt
     * VÉGTELENSÉGIG tartja a kapcsolatot (lassú-loris), mert minden olvasás frissíti a
     * `touched`-ot. Ezért van ABSZOLÚT session-életkor is, és a `DATA` fázisnak saját,
     * hosszabb korlátja (egy 25 MB-os levél lassú vonalon legitim módon percekig tart).
     */
    private function expire(): void
    {
        $now = microtime(true);

        $idle = (int) config('smtp.limits.command_timeout_seconds');
        $data = (int) config('smtp.limits.data_timeout_seconds');
        $absolute = (int) config('smtp.limits.session_timeout_seconds');

        foreach ($this->connections as $id => $connection) {
            $inData = $connection['session']->state() === SessionState::Data;

            $expired = ($now - $connection['touched'] > ($inData ? $data : $idle))
                || ($now - $connection['started'] > $absolute);

            if ($expired) {
                $this->write($connection['socket'], '421 4.4.2 Timeout');
                $this->close($id);
            }
        }
    }

    /** @param resource $socket */
    private function write(mixed $socket, string $line): void
    {
        // A többsoros válasz (EHLO) sorai `250-`, az utolsó `250 `.
        $lines = explode("\n", $line);
        $code = strtok($lines[0], ' ');

        foreach ($lines as $index => $part) {
            $isLast = $index === count($lines) - 1;
            $text = $index === 0 ? substr($part, strlen((string) $code) + 1) : $part;

            @fwrite($socket, $code.($isLast ? ' ' : '-').$text."\r\n");
        }
    }

    private function close(int $id): void
    {
        if (! isset($this->connections[$id])) {
            return;
        }

        @fclose($this->connections[$id]['socket']);

        unset($this->connections[$id]);
    }

    private function ipOf(string $peer): string
    {
        $position = strrpos($peer, ':');

        return $position === false ? $peer : substr($peer, 0, $position);
    }

    /** @return array<string, mixed> */
    private function tlsOptions(): array
    {
        $certificate = (string) config('smtp.tls.certificate');

        if ($certificate === '') {
            throw new RuntimeException(
                'Nincs SMTP_TLS_CERT beállítva. A submission CSAK titkosított csatornán megy '
                .'(golden rule 3) — tanúsítvány nélkül a listener nem indul.'
            );
        }

        return [
            'local_cert' => $certificate,
            'local_pk' => (string) config('smtp.tls.private_key'),
            'allow_self_signed' => ! app()->isProduction(),
            'verify_peer' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        ];
    }
}
