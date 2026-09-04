<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Transport;

use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Contracts\SmtpConnection;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\Smtp\Protocol\SessionState;
use App\Modules\Smtp\Protocol\SmtpSession;
use App\Modules\Smtp\Services\AuthThrottle;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;
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
    /** @var array<int, array{socket: resource, session: SmtpConnection, buffer: string, touched: float, started: float, ip: string, handshaking: bool, handshakeStarted: float, readableAfter: float}> */
    private array $connections = [];

    private bool $running = false;

    /**
     * A SESSION-GYÁR opcionális, és ez a bejövő (MX) oldal miatt van.
     *
     * A transzport mindkét oldalon UGYANAZ: socket, TLS, időkorlát, per-IP keret, tarpit.
     * Ami különbözik, az a PROTOKOLL-SZABÁLY — és épp azt nem szabad összemosni (ADR 0002).
     * Ezért a transzport csak egy `SmtpConnection`-t lát, és hogy melyiket, azt a hívó dönti
     * el. Gyár nélkül a viselkedés VÁLTOZATLAN: submission-session, az eddigi függőségekkel.
     *
     * @param  (callable(ChannelSecurity): SmtpConnection)|null  $sessionFactory
     * @param  list<int>|null  $allowedPorts  null = a `config('smtp.allowed_ports')`
     */
    public function __construct(
        private readonly SmtpAuthenticator $authenticator,
        private readonly SubmissionSink $sink,
        private readonly AuthThrottle $throttle,
        private $sessionFactory = null,
        private readonly ?array $allowedPorts = null,
    ) {}

    /**
     * @param  int  $port  a `config('smtp.allowed_ports')` egyike — a 25 SZÁNDÉKOSAN nincs köztük
     * @param  bool  $implicitTls  465: a kapcsolat már TLS-sel indul; 587: STARTTLS-szel emelünk
     */
    public function serve(string $host, int $port, bool $implicitTls, ?callable $shouldStop = null): void
    {
        $allowed = $this->allowedPorts ?? (array) config('smtp.allowed_ports');

        if (! in_array($port, $allowed, true)) {
            // A port 25 a BEJÖVŐ (MX) oldalé — más protokoll-szabály, más bizalmi modell.
            // A submission-listener sosem indulhat rajta, és fordítva.
            throw new RuntimeException(sprintf(
                'A(z) %d port nem engedélyezett ezen a listeneren (engedélyezett: %s).',
                $port,
                implode(', ', $allowed)
            ));
        }

        // A hallgató socket MINDIG sima TCP — az implicit TLS-t (465) is a saját,
        // nem-blokkoló handshake-utunkon emeljük fel.
        //
        // Miért: a `tlsv1.2://` wrapper a HANDSHAKE-et az `accept`-re bízza, az viszont
        // nem-blokkoló szerver-socketen (újabb PHP/OpenSSL páron) egyszerűen nem futtatja
        // le. Mérve: a 465-ös lábon a TLS-kliens `WRONG_VERSION_NUMBER`-t kapott, a
        // plaintext kliens viszont KISZOLGÁLÁST — vagyis a service egyetlen levelet sem
        // tudott kiküldeni, és egy implicit TLS-t váró porton STARTTLS-t hirdettünk.
        if ($implicitTls) {
            // Fail-fast: tanúsítvány nélkül a 465 nem indulhat el.
            $this->tlsOptions();
        }

        $this->assertMemoryHeadroom();

        $server = @stream_socket_server(
            'tcp://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
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

    /**
     * A puffer-plafon BELEFÉR-E a PHP memória-limitjébe?
     *
     * A `php:8.3-fpm-alpine` nem szállít aktív `php.ini`-t, tehát a limit a beépített
     * 128 MB — a korábbi 256 MB-os plafon így SOHA nem sült volna el: a PHP OOM-fatal
     * előbb jött, azt pedig `try/catch` nem fogja, tehát a listener meghalt. Egy
     * hitelesített tenant hat kapcsolattal kivitte az összes többi submissionjét.
     */
    private function assertMemoryHeadroom(): void
    {
        $limit = $this->memoryLimitBytes();

        if ($limit <= 0) {
            return; // korlátlan (CLI-ban gyakori)
        }

        // A `.=` realloc-duplázás miatt a valós csúcs a könyvelt bájtok kétszerese, plusz
        // a keretrendszer alapfoglalása.
        $needed = ((int) config('smtp.limits.max_buffered_bytes') * 2) + 64 * 1024 * 1024;

        if ($needed > $limit) {
            throw new RuntimeException(sprintf(
                'A puffer-plafon (%d bájt) nem fér bele a PHP memory_limitbe (%d bájt): '
                .'a listener OOM-mal halna meg, MIELŐTT a plafon elsülne. Emeld a '
                .'memory_limitet legalább %d bájtra, vagy csökkentsd az '
                .'SMTP_MAX_BUFFERED_BYTES értékét.',
                (int) config('smtp.limits.max_buffered_bytes'),
                $limit,
                $needed
            ));
        }
    }

    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** @param resource $server */
    private function tick(mixed $server, bool $implicitTls): void
    {
        $read = [$server];

        foreach ($this->connections as $id => $connection) {
            if (! isset($connection['socket']) || ! is_resource($connection['socket'])) {
                unset($this->connections[$id]);

                continue;
            }

            // A tarpitolt kapcsolat KIMARAD a select-halmazból: benne hagyva a select
            // minden körben azonnal visszatérne (az adat ott van, csak nem olvassuk) —
            // mérve ~100% mag égetése kapcsolatonként. A fék maga lett volna a DoS.
            if (($connection['readableAfter'] ?? 0.0) > microtime(true)) {
                continue;
            }

            $read[] = $connection['socket'];
        }

        $write = $except = [];

        if (@stream_select($read, $write, $except, 0, 200_000) === false) {
            return;
        }

        foreach ($read as $stream) {
            try {
                $stream === $server
                    ? $this->accept($server, $implicitTls)
                    : $this->readFrom($stream, $implicitTls);
            } catch (Throwable $e) {
                // Egyetlen romlott kapcsolat SOHA nem viheti el a processzt: a listener
                // minden más tenant submissionjét is kiszolgálja.
                report($e);

                if ($stream !== $server) {
                    $this->close((int) $stream);
                }
            }
        }

        try {
            $this->expire();
        } catch (Throwable $e) {
            report($e);
        }
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
        //
        // PROXY protocol mellett ITT még a BALANCER IP-jét látjuk — a plafont ilyenkor a
        // fejléc megérkezése UTÁN érvényesítjük (`consumeProxyHeader`). Ha itt ütnénk, a
        // 11. párhuzamos kapcsolat kapna 421-et, függetlenül attól, hány ügyféltől jött:
        // ön-DoS a saját LB-nk mögött, miközben a valódi forrásra semmi nem vonatkozna.
        if (! (bool) config('smtp.proxy_protocol.enabled') && $this->exceedsPerIpLimit($ip)) {
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

        // A titkosítottság TÉNY, nem szándék: a socket meta-adatából olvassuk ki, nem a
        // `--port` kapcsolóból. Mérve: egy másik PHP/OpenSSL páron a nem-blokkoló accept
        // NEM futtatta le a handshake-et, és a listener sima plaintext SMTP-vé
        // degradálódott — a kapcsoló szerint viszont „titkosított" volt, tehát base64
        // jelszóval hitelesített volna a dróton (golden rule 3).
        $security = $this->securityOf($socket);

        $session = $this->sessionFactory !== null
            ? ($this->sessionFactory)($security)
            : new SmtpSession(
                $this->authenticator,
                $this->sink,
                $security,
                (string) config('smtp.hostname'),
            );

        $id = (int) $socket;

        $this->connections[$id] = [
            'socket' => $socket,
            'session' => $session,
            'buffer' => '',
            'touched' => microtime(true),
            'started' => microtime(true),
            'ip' => $ip,
            'handshaking' => false,
            'handshakeStarted' => 0.0,
            'readableAfter' => 0.0,
            // PROXY protocol: amíg a fejléc meg nem jött, a kapcsolat még „névtelen".
            'awaitingProxyHeader' => (bool) config('smtp.proxy_protocol.enabled'),
        ];

        // PROXY protocol: a köszöntés és a TLS is VÁR a fejlécre — a valódi forrás-IP
        // nélkül a kapcsolat-limit és a brute-force fék rossz vödörbe könyvelne.
        if ($this->connections[$id]['awaitingProxyHeader']) {
            return;
        }

        // Implicit TLS: ELŐBB a handshake, és CSAK utána köszönünk — a köszöntés
        // plaintextben elárulná, hogy nem is titkosítunk.
        if ($implicitTls) {
            $this->connections[$id]['handshaking'] = true;
            $this->connections[$id]['handshakeStarted'] = microtime(true);

            $this->continueHandshake($id, greetAfterwards: true);

            return;
        }

        $this->write($socket, $session->greeting()->toLine());
    }

    /**
     * A PROXY protocol fejléc feldolgozása.
     *
     * @return bool feldolgozható-e már a puffer többi része
     */
    private function consumeProxyHeader(int $id, bool $implicitTls): bool
    {
        $connection = $this->connections[$id];

        // A fejléc CSAK megbízható forrástól hihető. Enélkül bárki, aki közvetlenül eléri a
        // portot, kapcsolatonként új forrás-IP-t hazudna — és mivel a brute-force számláló
        // IP-kulcsú, a tiltás soha nem telne be: a feature a fék KIKAPCSOLÁSA lenne.
        if (! $this->isTrustedProxy($connection['ip'])) {
            Log::warning('PROXY fejléc nem megbízható forrásból', ['ip' => $connection['ip']]);

            $this->close($id);

            return false;
        }

        try {
            $header = ProxyProtocolHeader::parse($connection['buffer']);
        } catch (InvalidProxyHeader $e) {
            // FAIL-CLOSED: bekapcsolt PROXY protocol mellett a fejléc kötelező. Enélkül a
            // kliens maga dönthetné el, mikor küld ilyet — és a hiánya épp a per-IP fékek
            // kikapcsolását jelentené.
            Log::warning('Érvénytelen PROXY protocol fejléc', ['error' => $e->getMessage()]);

            $this->close($id);

            return false;
        }

        if ($header === null) {
            return false; // még nem jött meg a teljes fejléc
        }

        // LOCAL / UNKNOWN: nincs továbbított forrás — a socket peer-címe marad.
        $connection['ip'] = $header->local ? $connection['ip'] : $header->sourceIp;
        $connection['buffer'] = substr($connection['buffer'], $header->consumedBytes);
        $connection['awaitingProxyHeader'] = false;

        $this->connections[$id] = $connection;

        // A fékek MOST már a VALÓDI forrásra vonatkoznak — innentől számít a plafon.
        if ($this->exceedsPerIpLimit($connection['ip'], $id)) {
            $this->write($connection['socket'], '421 4.7.0 Too many connections from your address');
            $this->close($id);

            return false;
        }

        if ($this->throttle->tooManyFailures($connection['ip'])) {
            $this->write($connection['socket'], '421 4.7.0 Too many authentication failures from this address');
            $this->close($id);

            return false;
        }

        if ($implicitTls) {
            $this->connections[$id]['handshaking'] = true;
            $this->connections[$id]['handshakeStarted'] = microtime(true);

            $this->continueHandshake($id, greetAfterwards: true);

            return false;
        }

        $this->write($connection['socket'], $connection['session']->greeting()->toLine());

        return true;
    }

    /** Túllépi-e a forrás a párhuzamos kapcsolatok plafonját? */
    private function exceedsPerIpLimit(string $ip, ?int $exceptId = null): bool
    {
        $count = 0;

        foreach ($this->connections as $id => $connection) {
            if ($id !== $exceptId && ($connection['ip'] ?? null) === $ip) {
                $count++;
            }
        }

        return $count >= (int) config('smtp.limits.max_connections_per_ip');
    }

    /** Megbízható-e a kapcsolat forrása (LB) — CIDR-listából. */
    private function isTrustedProxy(string $ip): bool
    {
        $sources = (array) config('smtp.proxy_protocol.trusted_sources');

        if ($sources === []) {
            // Bekapcsolt PROXY protocol ÜRES allowlisttel: fail-closed. Ez konfigurációs
            // hiba, nem alapértelmezés — inkább ne fogadjunk kapcsolatot, mint hamisítható
            // forrás-IP-vel dolgozzunk.
            return false;
        }

        foreach ($sources as $source) {
            if (IpUtils::checkIp($ip, (string) $source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A csatorna tényleges kripto-állapota.
     *
     * @param  resource  $socket
     */
    private function securityOf(mixed $socket): ChannelSecurity
    {
        $meta = stream_get_meta_data($socket);
        $crypto = $meta['crypto'] ?? null;

        $protocol = is_array($crypto) ? (string) ($crypto['protocol'] ?? '') : '';

        // ALLOWLIST: ha a `crypto_method` valaha lazul, egy TLSv1.0/SSLv3 session is
        // „titkosítottnak" látszana — és azon a jelszó gyakorlatilag olvasható.
        return in_array($protocol, ['TLSv1.2', 'TLSv1.3'], true)
            ? ChannelSecurity::encrypted($protocol)
            : ChannelSecurity::plaintext();
    }

    /** @param resource $socket */
    private function readFrom(mixed $socket, bool $implicitTls = false): void
    {
        $id = (int) $socket;
        $connection = $this->connections[$id] ?? null;

        if ($connection === null) {
            return;
        }

        // Tarpit: ennek a kapcsolatnak még várnia kell. A ciklus közben másokat szolgál ki.
        if (($connection['readableAfter'] ?? 0.0) > microtime(true)) {
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

        if ($connection['awaitingProxyHeader'] && ! $this->consumeProxyHeader($id, $implicitTls)) {
            return;
        }

        // A parancssor-limit CSAK akkor érvényesül, ha van `\n`. Újsor nélkül öntött adat
        // korlátlanul növelte volna a puffert — a hosszúsor-védelem sosem futott volna le.
        $maxLine = (int) config('smtp.limits.max_auth_bytes');

        if (! str_contains($this->connections[$id]['buffer'], "\n") && strlen($this->connections[$id]['buffer']) > $maxLine) {
            $this->write($socket, '500 5.5.2 Line too long');
            $this->close($id);

            return;
        }

        while (($position = strpos($this->connections[$id]['buffer'], "\n")) !== false) {
            // A tarpit a PUFFERELT sorokra is érvényes: enélkül egyetlen TCP-szegmensbe
            // pipeline-ozva (`AUTH ...\r\nNOOP\r\n`) a lassítás nem ért semmit — a
            // szerver maga hirdeti a PIPELINING-et.
            if (($this->connections[$id]['readableAfter'] ?? 0.0) > microtime(true)) {
                return;
            }

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

        // GLOBÁLIS puffer-plafon: a kapcsolatonkénti méretlimit önmagában nem véd, mert a
        // kapcsolatok SZÁMÁVAL szorzódik. Túllépésnél ezt a tranzakciót dobjuk el —
        // átmeneti kóddal, hogy a kliens újrapróbálhassa.
        if ($this->bufferedBytes() > (int) config('smtp.limits.max_buffered_bytes')) {
            $this->write($connection['socket'], '452 4.3.1 Insufficient system storage, try again later');
            $this->close($id);

            return;
        }

        foreach ($replies as $reply) {
            // A sikertelen hitelesítés a PROCESSZEK KÖZÖTT osztott fékbe is beszámít:
            // a session-szintű három hiba önmagában semmit nem ér, mert a támadó új
            // kapcsolatot nyit.
            if ($reply->code === 535) {
                $this->throttle->recordFailure($connection['ip']);

                // TARPIT: a próbálgatás legolcsóbb fékje a LASSÍTÁS — de NEM `usleep`-pel.
                // Az egyszálas ciklusban egyetlen rossz jelszó egy MÁSODPERCRE megállította
                // volna az összes többi kapcsolatot is (mérve: 1010 ms), vagyis a fék maga
                // lett volna a DoS. Helyette a KAPCSOLAT kap egy „nem előbb, mint"
                // időbélyeget: csak ez a kliens vár, a ciklus fut tovább.
                if ($this->throttle->shouldTarpit($connection['ip'])) {
                    $this->connections[$id]['readableAfter'] = microtime(true) + 1.0;
                }
            }

            if ($reply->code === 235) {
                // A hiba-számlálót NEM nullázzuk: aki birtokol egy érvényes hitelesítőt,
                // különben korlátlanul próbálgathatna (24 tipp → siker → újabb 24 tipp).
                // A számláló a mérési ablakkal jár le.
                //
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
    /**
     * A hitelesítő utolsó használata — nyers IP nélkül.
     *
     * CSAK a submission-oldalon értelmezett: az MX-en nincs hitelesítő, amit használni
     * lehetne. A típus-ellenőrzés ezért nem óvatoskodás, hanem a két oldal szétválasztása.
     */
    private function recordUsage(SmtpConnection $session, string $ip): void
    {
        if (! $session instanceof SmtpSession) {
            return;
        }

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

    private function continueHandshake(int $id, bool $greetAfterwards = false): void
    {
        $connection = $this->connections[$id];

        $result = @stream_socket_enable_crypto($connection['socket'], true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if ($result === 0) {
            // Még tart. Csak a HATÁRIDŐT nézzük — a lassú-loris itt is ugyanolyan olcsó.
            if (microtime(true) - $connection['handshakeStarted'] > (int) config('smtp.limits.command_timeout_seconds')) {
                $this->close($id);

                // RETURN, nem esés tovább: a `close()` UTÁNI értékadás korábban
                // ÚJRA LÉTREHOZTA a bejegyzést — `socket` és `session` kulcs nélkül. A
                // következő körben a `stream_select` vagy az `expire()` végzetes hibára
                // futott, és mivel semmi nem védte, a PROCESSZ MEGHALT. Egyetlen
                // hitelesítetlen, félbehagyott TLS-handshake elvitte az egész listenert.
                return;
            }

            $this->connections[$id]['touched'] = microtime(true);
            $this->connections[$id]['greetAfterHandshake'] = $greetAfterwards
                || ($connection['greetAfterHandshake'] ?? false);

            return;
        }

        if ($result !== true) {
            $this->close($id);

            return;
        }

        // A TLS-váltás után a session MINDENT elfelejt (STARTTLS command injection).
        $security = $this->securityOf($connection['socket']);

        // Ha a handshake „sikeres", de a csatorna mégsem titkosított, NEM szolgálunk ki:
        // az implicit TLS-t váró kliensnek plaintextet adni csendes golden rule 3 sértés.
        if (! $security->encrypted) {
            Log::warning('TLS-handshake után sem titkosított a csatorna — bontás', ['ip' => $connection['ip']]);

            $this->close($id);

            return;
        }

        $connection['session']->securedWith($security);
        $connection['handshaking'] = false;
        $connection['buffer'] = '';
        $connection['touched'] = microtime(true);

        $this->connections[$id] = $connection;

        if ($greetAfterwards || ($connection['greetAfterHandshake'] ?? false)) {
            $this->connections[$id]['greetAfterHandshake'] = false;

            $this->write($connection['socket'], $connection['session']->greeting()->toLine());
        }
    }

    /**
     * Időkorlátok — HÁROM, mert három különböző visszaélést fognak.
     *
     * A tétlenségi korlát önmagában nem elég: aki percenként egy bájtot küld, azt
     * VÉGTELENSÉGIG tartja a kapcsolatot (lassú-loris), mert minden olvasás frissíti a
     * `touched`-ot. Ezért van ABSZOLÚT session-életkor is, és a `DATA` fázisnak saját,
     * hosszabb korlátja (egy 25 MB-os levél lassú vonalon legitim módon percekig tart).
     */
    /** Az összes kapcsolat által pufferelt levéltörzs. */
    private function bufferedBytes(): int
    {
        $total = 0;

        foreach ($this->connections as $connection) {
            $total += $connection['session']->bufferedBytes() + strlen($connection['buffer']);
        }

        return $total;
    }

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
