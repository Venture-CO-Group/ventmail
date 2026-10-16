<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Data\ScanResult;
use Throwable;

/**
 * ClamAV a clamd INSTREAM protokollján.
 *
 * Sem shell, sem ideiglenes fájl: a tartalom a socketen megy át. A `clamscan` parancssori
 * hívása egyszerre lenne lassú (minden híváskor újratöltené a szignatúra-adatbázist) és
 * kockázatos (fájlnév a parancssorban, ideiglenes fájl a lemezen).
 */
final class ClamAvScanner implements VirusScanner
{
    public function scan(string $filename, string $contents, ?string $reference = null): ScanResult
    {
        $socket = @stream_socket_client(
            (string) config('inbound.clamav.dsn'),
            $errno,
            $error,
            (float) config('inbound.clamav.timeout', 10),
        );

        if ($socket === false) {
            // FAIL-CLOSED: amit nem tudtunk megnézni, azt nem adjuk ki.
            return ScanResult::unavailable('clamd nem elérhető: '.$error);
        }

        try {
            stream_set_timeout($socket, (int) config('inbound.clamav.timeout', 10));
            fwrite($socket, "zINSTREAM\0");

            // Darabokban: a protokoll minden darab elé 4 bájtos, hálózati bájtsorrendű
            // hosszt vár, a végét nulla hosszú darab zárja.
            foreach (str_split($contents, 65536) ?: [''] as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));

            $response = (string) stream_get_contents($socket);
        } catch (Throwable $e) {
            return ScanResult::unavailable('clamd hiba: '.$e::class);
        } finally {
            fclose($socket);
        }

        if (str_contains($response, 'FOUND')) {
            return ScanResult::infected(trim(str_replace(['stream:', 'FOUND', "\0"], '', $response)));
        }

        return str_contains($response, 'OK')
            ? ScanResult::clean()
            : ScanResult::unavailable('Értelmezhetetlen clamd válasz.');
    }
}
