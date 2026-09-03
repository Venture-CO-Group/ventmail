<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Services;

use App\Modules\Audit\Services\KeyedHash;
use Illuminate\Support\Facades\Cache;

/**
 * Brute-force fék az SMTP-hitelesítésen.
 *
 * Az állapotgép session-szinten három hiba után bont — az önmagában semmit nem ér, mert a
 * támadó új kapcsolatot nyit. A fék ezért a PROCESSZEK KÖZÖTT osztott tárban él, és a
 * forrás-IP-re megy: elég sok hiba után előbb lassítunk (tarpit), majd tiltunk.
 *
 * Nyers IP nem kerül a tárba: kulcsolt hash, saját domain-elválasztással.
 */
final class AuthThrottle
{
    public function tooManyFailures(string $ip): bool
    {
        return $this->failures($ip) >= (int) config('smtp.auth_throttle.ban_threshold');
    }

    /** Lassítandó-e a válasz (tarpit)? A lassítás a legolcsóbb védekezés a próbálgatás ellen. */
    public function shouldTarpit(string $ip): bool
    {
        return $this->failures($ip) >= (int) config('smtp.auth_throttle.failures_per_window');
    }

    public function recordFailure(string $ip): void
    {
        $key = $this->key($ip);

        // A TILTÁS hossza (`ban_seconds`) hosszabb, mint a mérési ablak: ha a kulcs TTL-je
        // csak az ablak lenne, a tiltás magától feloldódna az ablakkal együtt — a
        // beállított egy óra pedig halott konfiguráció maradna.
        $window = $this->failures($ip) + 1 >= (int) config('smtp.auth_throttle.ban_threshold')
            ? (int) config('smtp.auth_throttle.ban_seconds')
            : (int) config('smtp.auth_throttle.window_seconds');

        // ATOMI növelés: két listener-processz (587 és 465) ugyanazon a kulcson versenyez,
        // és a `get`+`put` páros mellett a fék pont a párhuzamos próbálgatásnál lyukas.
        // Az `add` állítja be a TTL-t, az `increment` utána már csak számol.
        try {
            Cache::add($key, 0, $window);
            $count = Cache::increment($key);

            // A tiltási küszöb átlépésekor a TTL-t is meghosszabbítjuk.
            if ((int) $count >= (int) config('smtp.auth_throttle.ban_threshold')) {
                Cache::put($key, $count, (int) config('smtp.auth_throttle.ban_seconds'));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function clear(string $ip): void
    {
        try {
            Cache::forget($this->key($ip));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function failures(string $ip): int
    {
        try {
            return (int) Cache::get($this->key($ip), 0);
        } catch (\Throwable $e) {
            // A fék tára (Redis) nem elérhető. FAIL-CLOSED: úgy vesszük, mintha tele lenne
            // a keret — inkább elutasított kapcsolat, mint korlátlan jelszó-próbálgatás.
            // (Korábban a kivétel az EGÉSZ listenert megölte: az még rosszabb.)
            report($e);

            return PHP_INT_MAX;
        }
    }

    private function key(string $ip): string
    {
        return 'smtp-auth-fail:'.KeyedHash::of('smtp_source_ip', $ip);
    }
}
