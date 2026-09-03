<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per-FOGADÓ-DOMAIN sebességkorlát (19. kritérium).
 *
 * A nagy fogadók (Gmail, Outlook, Yahoo) saját küszöbökkel dolgoznak: ha egy IP-ről túl
 * gyorsan érkezik a forgalom, előbb lassítanak (4xx), aztán blokkolnak. A korlátnak ezért
 * DOMAINENKÉNT külön kell működnie — ha egy vödörben lenne, a Gmail felé futó kampány
 * megfogná a saját ügyfél-domainre menő tranzakciós levelet is.
 */
final class DomainThrottle
{
    public function allow(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $key = $this->key($domain);
        $limit = $this->limitFor($domain);

        // ATOMI: a `get` + `put` páros mellett N párhuzamos worker ugyanazt az értéket
        // olvassa, és MIND átmegy — a limit pont abban a helyzetben nem érvényesülne,
        // amiért létezik. Az `add` állítja be a TTL-t, az `increment` már csak számol.
        Cache::add($key, 0, now()->addMinutes(2));

        $used = (int) Cache::increment($key);

        if ($used > $limit) {
            return false;
        }

        return true;
    }

    /** Megnézi a keretet a token elfogyasztása NÉLKÜL (pl. előzetes ellenőrzéshez). */
    public function remaining(string $domain): int
    {
        $domain = strtolower(trim($domain));

        return max(0, $this->limitFor($domain) - (int) Cache::get($this->key($domain), 0));
    }

    public function limitFor(string $domain): int
    {
        $limits = (array) config('sending.domain_throttle.limits', []);

        return (int) ($limits[strtolower($domain)] ?? config('sending.domain_throttle.default', 60));
    }

    /**
     * A kulcs PERCHEZ kötött: az ablak fix óraperc, nem az első küldéstől számít.
     *
     * Ez a határon (10:00:59 + 10:01:00) elvileg a limit kétszeresét engedi át egy
     * másodpercen belül. Vállalt: a fogadók küszöbei nem másodperc-pontosak, a csúszó
     * ablak viszont vagy sorted set-et (Redis-specifikus), vagy nem-atomi olvasást kérne —
     * az utóbbi rosszabb, mert épp a párhuzamosságnál lyukad ki.
     */
    private function key(string $domain): string
    {
        return 'domain-throttle:'.$domain.':'.now()->format('YmdHi');
    }
}
