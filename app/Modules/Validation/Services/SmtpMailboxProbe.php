<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Data\ProbeResult;
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
        $host = $this->mailExchanger($domain);

        if ($host === null) {
            return ProbeResult::unknown('nincs elérhető MX');
        }

        $timeout = (float) config('validation.probe.timeout_seconds');

        $socket = @stream_socket_client('tcp://'.$host.':25', $errno, $error, $timeout);

        if ($socket === false) {
            // A 25-ös port kifelé sok hálózaton zárt. Ez a MI korlátunk, nem a címé.
            return ProbeResult::unknown('nem sikerült csatlakozni: '.$error);
        }

        try {
            stream_set_timeout($socket, (int) $timeout);

            if (! $this->expect($socket, 220)) {
                return ProbeResult::unknown('nem várt köszöntés');
            }

            $this->send($socket, 'EHLO '.config('inbound.hostname'));
            $this->read($socket);

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
            $this->send($socket, 'RSET');
            $this->read($socket);
            $this->send($socket, 'MAIL FROM:<>');
            $this->read($socket);
            $this->send($socket, 'RCPT TO:<'.bin2hex(random_bytes(12)).'@'.$domain.'>');

            $catchAll = (int) substr(trim($this->read($socket)), 0, 3) === 250;

            return $catchAll ? ProbeResult::catchAll() : ProbeResult::accepted($code);
        } catch (Throwable $e) {
            return ProbeResult::unknown('szonda-hiba: '.$e::class);
        } finally {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
    }

    private function mailExchanger(string $domain): ?string
    {
        foreach ($this->dns->records($domain, 'MX') as $record) {
            $host = trim((string) preg_replace('/^\s*\d+\s+/', '', $record), '. ');

            if ($host !== '') {
                return $host;
            }
        }

        // RFC 5321 §5.1: MX hiányában maga a domain az implicit MX.
        return $this->dns->records($domain, 'A') !== [] ? $domain : null;
    }

    private function send(mixed $socket, string $line): void
    {
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
