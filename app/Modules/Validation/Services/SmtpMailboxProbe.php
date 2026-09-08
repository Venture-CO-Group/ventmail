<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Data\ProbeResult;
use InvalidArgumentException;
use Throwable;

/**
 * A VALÓDI mailbox-szonda: SMTP-párbeszéd `RCPT TO`-ig, DATA nélkül (40. kritérium).
 *
 * A `MAIL FROM` SZÁNDÉKOSAN null-sender (`<>`): ez a bevett gyakorlat a szondánál, mert (a)
 * bounce-nak látszik, amit minden szerver elfogad, és (b) nem állítunk magunkról olyan
 * feladót, akinek a nevében nem küldünk semmit.
 *
 * A CATCH-ALL felismerése egy MÁSODIK, biztosan nem létező címmel megy: ha a szerver azt is
 * elfogadja, akkor mindent elfogad, és az első `250` semmit nem bizonyít. Enélkül minden
 * catch-all domain minden címét „kézbesíthetőnek" jelentenénk — ami a legrosszabb fajta hamis
 * bizonyosság: az ügyfél erre építene listát.
 *
 * SOHA nem küldünk `DATA`-t. A szonda nem levél.
 */
final class SmtpMailboxProbe implements MailboxProbe
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function probe(string $email): ProbeResult
    {
        $domain = DomainNormalizer::ofEmail($email);

        try {
            $hosts = $this->mailExchangers($domain);
        } catch (DnsUnavailable) {
            // A DNS kiesése NEM „nincs ilyen postafiók". A `unknown` a helyes válasz: a
            // kockázat-pontszám 0.0 marad, és a hatodik kapu nem utasít el senkit egy
            // resolver-kimaradás miatt.
            return ProbeResult::unknown('a DNS most nem kérdezhető le');
        }

        if ($hosts === []) {
            return ProbeResult::unknown('nincs elérhető MX');
        }

        $timeout = (float) config('validation.probe.timeout_seconds');

        $socket = false;

        // FAILOVER: ha az elsődleges MX nem elérhető, a következő jön. Enélkül egy
        // pillanatnyi kiesés az egész domain minden címét „nem tudjuk"-ra állítaná.
        foreach ($hosts as $host) {
            $socket = @stream_socket_client('tcp://'.$host.':25', $errno, $error, $timeout);

            if ($socket !== false) {
                break;
            }
        }

        if ($socket === false) {
            // A 25-ös port kifelé sok hálózaton zárt. Ez a MI korlátunk, nem a címé.
            return ProbeResult::unknown('nem sikerült csatlakozni: '.($error ?? ''));
        }

        try {
            stream_set_timeout($socket, (int) $timeout);

            if (! $this->expect($socket, 220)) {
                return ProbeResult::unknown('nem várt köszöntés');
            }

            $this->send($socket, 'EHLO '.config('inbound.hostname'));
            $greeting = $this->read($socket);

            // STARTTLS, ha a fogadó hirdeti.
            //
            // A `RCPT TO:<cim@domain>` az ÜGYFÉL CÍMLISTÁJA, szemenként — titkosítatlanul a
            // 25-ös porton bárki elolvashatja az útvonalon. A szonda nem küld levelet, de
            // épp ezért a cím az EGYETLEN adat, amit kiad: azt kell védeni.
            $advertisesTls = stripos($greeting, 'STARTTLS') !== false;

            if ($advertisesTls) {
                $this->send($socket, 'STARTTLS');

                $upgraded = $this->expect($socket, 220)
                    && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true;

                if (! $upgraded) {
                    // A BUKOTT kézfogás után a socket használhatatlan: a további írás egy
                    // félig titkosított csatornára menne. Megszakítunk — a cím kiadása
                    // titkosítatlanul nem ér annyit, mint amennyit kockáztatnánk vele.
                    return ProbeResult::unknown('a TLS-kézfogás nem sikerült');
                }

                // A TLS után ÚJRA kell köszönni (RFC 3207 §4.2).
                $this->send($socket, 'EHLO '.config('inbound.hostname'));
                $this->read($socket);
            } elseif (config('validation.probe.require_tls')) {
                // A fogadó NEM hirdet TLS-t. A cím az EGYETLEN adat, amit a szonda kiad —
                // titkosítatlanul a 25-ös porton bárki elolvashatja az útvonalon. Inkább
                // mondjuk azt, hogy „nem tudjuk".
                return ProbeResult::unknown('a fogadó nem támogat TLS-t');
            }

            // NULL-SENDER: bounce-nak látszik, és nem állítunk magunkról hamis feladót.
            $this->send($socket, 'MAIL FROM:<>');

            if (! $this->expect($socket, 250)) {
                return ProbeResult::unknown('a null-sender elutasítva');
            }

            $this->send($socket, 'RCPT TO:<'.$email.'>');
            $response = $this->read($socket);
            $code = (int) substr(trim($response), 0, 3);

            if ($code >= 500) {
                return ProbeResult::rejected($code, trim($response));
            }

            if ($code !== 250) {
                // 4xx: greylisting vagy átmeneti korlát — NEM azt jelenti, hogy nincs ilyen cím.
                return ProbeResult::unknown('átmeneti válasz: '.$code);
            }

            // CATCH-ALL PRÓBA: egy biztosan nem létező cím ugyanazon a domainen.
            //
            // MINDEN LÉPÉS VÁLASZÁT ellenőrizzük. A nagy szolgáltatók bevett anti-harvesting
            // viselkedése, hogy az első `RCPT` után bontják a kapcsolatot — olyankor a
            // `read()` üres sztringet ad, és a naiv `!== 250` összehasonlítás
            // „nem catch-all"-t, azaz `accepted`-et jelentene. A transzport-hiba NEM
            // dönthet a hamis bizonyosság irányába.
            $this->send($socket, 'RSET');

            if (! $this->expect($socket, 250)) {
                return ProbeResult::unknown('a catch-all próba nem futtatható (RSET)');
            }

            $this->send($socket, 'MAIL FROM:<>');

            if (! $this->expect($socket, 250)) {
                return ProbeResult::unknown('a catch-all próba nem futtatható (MAIL FROM)');
            }

            $this->send($socket, 'RCPT TO:<'.bin2hex(random_bytes(12)).'@'.$domain.'>');

            $second = trim($this->read($socket));

            if ($second === '') {
                return ProbeResult::unknown('a fogadó bontotta a kapcsolatot a próba közben');
            }

            $secondCode = (int) substr($second, 0, 3);

            return $secondCode === 250 ? ProbeResult::catchAll() : ProbeResult::accepted($code);
        } catch (Throwable $e) {
            return ProbeResult::unknown('szonda-hiba: '.$e::class);
        } finally {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
    }

    /**
     * A fogadó MX-ei PRIORITÁS SZERINT.
     *
     * A sorrend nem részletkérdés: a BACKUP MX gyakran megengedőbb (mindent elfogad, majd
     * később bounce-ol), tehát a rossz sorrendből hamis `accepted` lesz. Az elsődleges MX
     * mondja meg az igazságot.
     *
     * @return list<string>
     */
    private function mailExchangers(string $domain): array
    {
        $hosts = [];

        foreach ($this->dns->records($domain, 'MX') as $record) {
            $record = trim((string) $record);

            $priority = preg_match('/^\s*(\d+)\s+/', $record, $match) === 1 ? (int) $match[1] : 50;
            $host = trim((string) preg_replace('/^\s*\d+\s+/', '', $record), '. ');

            if ($host !== '') {
                $hosts[] = ['priority' => $priority, 'host' => $host];
            }
        }

        usort($hosts, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        $ordered = array_column($hosts, 'host');

        // RFC 5321 §5.1: MX hiányában maga a domain az implicit MX.
        if ($ordered === [] && $this->dns->records($domain, 'A') !== []) {
            $ordered[] = $domain;
        }

        return $ordered;
    }

    /**
     * Egy SMTP-parancs kiírása — VEZÉRLŐKARAKTER NÉLKÜL.
     *
     * A cím a TÁMADÓTÓL jön (bárki beírhat bármit egy ellenőrzési kérésbe), és itt kerül egy
     * parancs közepébe. CR/LF-fel a támadó a MI IP-nkről diktálhatna tetszőleges SMTP-t egy
     * idegen szervernek — mérve, valódi fogadó ellen: `RCPT TO:<anna>` után `VRFY root`.
     *
     * A teljes láncon ma a szintaxis-ellenőrzés előbb elutasítaná, de az egy MÁSIK osztály
     * távoli előfeltétele: a `MailboxProbe` publikus kontraktus, és az első jövőbeli hívó
     * (A6, űrlapok) visszahozná a rést. A védelem ott van, ahol a bájtok a dróta kerülnek.
     */
    private function send(mixed $socket, string $line): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $line) === 1) {
            throw new InvalidArgumentException('Vezérlőkarakter az SMTP-parancsban.');
        }

        fwrite($socket, $line."\r\n");
    }

    private function read(mixed $socket): string
    {
        $response = '';

        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;

            // Többsoros válasz: a lezáró sorban a kód után SZÓKÖZ áll, nem kötőjel.
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }

        return $response;
    }

    private function expect(mixed $socket, int $code): bool
    {
        return (int) substr(trim($this->read($socket)), 0, 3) === $code;
    }
}
