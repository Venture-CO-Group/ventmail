<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Models\User;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\Sending\Services\MessageDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * CLUSTER HEALTH (86. kritérium) — és az ISMERETLEN állapot NEM zöld.
 *
 * ## Ez a kritérium egyetlen fontos szabálya
 *
 * „Zöld, mert nem tudjuk" nem elfogadható. Egy monitorozó felület legrosszabb hibája nem
 * az, hogy pirosat mutat, amikor minden jó — hanem hogy ZÖLDET mutat, amikor baj van. Ha
 * egy komponens nem válaszol, az `unknown`, és az a riportban LÁTSZIK; az üzemeltető
 * ilyenkor tudja, hogy oda kell néznie.
 *
 * Ezért minden ellenőrzés `try`-ban fut, és a kivétel `unknown` állapotot ad — nem
 * `healthy`-t, és nem is `down`-t (a `down` állítás, az `unknown` a tudás hiánya; a kettő
 * más döntést kíván).
 *
 * ## Amit MÉR
 *
 * Postgres, Redis, a sor (Horizon), a küldő driver (Postal), az MX-fogadó, a Meilisearch és
 * a karanténba tett IP-k. Ezek azok a komponensek, amelyek KIESÉSE levelet állít meg — a
 * többi (felület, statikus fájlok) nem a levél útján van.
 */
final class ClusterHealth
{
    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    /** ISMERETLEN: nem tudjuk. NEM zöld, és nem is piros. */
    public const UNKNOWN = 'unknown';

    /**
     * KIKAPCSOLVA: a komponens szándékosan nem üzemel (pl. `INBOUND_ENABLED=false`).
     *
     * Miért külön állapot: a „nem fogad levelet, mert nincs bekapcsolva" nem hiba
     * (`down`), nem is tudás-hiány (`unknown`), és zöldnek se nevezhető — az `overall()`
     * ezért nem rontja le vele az összképet, a riport viszont KIÍRJA, hogy nem üzemel.
     */
    public const DISABLED = 'disabled';

    /**
     * @return array{status: string, components: array<string, array{status: string, detail: ?string}>}
     */
    public function report(User $admin): array
    {
        /*
         | JOGOSULTSÁG — a riport ÜZEMELTETÉSI TOPOLÓGIÁT ad ki.
         |
         | Sor-mélység, DB-késleltetés, IP-pool-számok, driver-állapot: egy támadónak ez
         | pontosan azt mondja meg, mikor és mivel érdemes próbálkozni (torlódó sor,
         | karanténba tett IP-k). A többi C2-szolgáltatás mind kapuzik; ez volt az egyetlen,
         | amelyik nem — és a felület megjelenésekor pont ez lett volna a legkönnyebben
         | elérhető végpont.
         */
        if (! Gate::forUser($admin)->allows('platform.admin')) {
            throw new AuthorizationException('A cluster-health platform-admin jogosultságot igényel.');
        }

        $components = [
            'database' => $this->check(fn (): array => $this->database()),
            'redis' => $this->check(fn (): array => $this->redis()),
            'queue' => $this->check(fn (): array => $this->queue()),
            'sending_driver' => $this->check(fn (): array => $this->sendingDriver()),
            'search' => $this->check(fn (): array => $this->search()),
            // A 86. kritérium NÉVSZERINT kéri az MX-fogadót — a docblock fel is sorolta,
            // a riportból mégis kimaradt.
            'inbound_mx' => $this->check(fn (): array => $this->inboundMx()),
            'ip_pool' => $this->check(fn (): array => $this->ipPool()),
        ];

        return ['status' => $this->overall($components), 'components' => $components];
    }

    /**
     * @param  callable(): array{status: string, detail: ?string}  $probe
     * @return array{status: string, detail: ?string}
     */
    private function check(callable $probe): array
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            /*
             | A KIVÉTEL `unknown`, nem `down`.
             |
             | A `down` ÁLLÍTÁS („a komponens nem működik"), az `unknown` a TUDÁS HIÁNYA
             | („nem tudtuk megkérdezni"). A kettő más üzemeltetői döntést kíván: a `down`
             | mellett a komponenst kell javítani, az `unknown` mellett a MONITOROZÁST.
             |
             | A kivétel ÜZENETE nem megy a riportba: egy `QueryException` a teljes SQL-t
             | hordozza behelyettesített értékekkel, és a health-riport egy operátori
             | felületen jelenik meg.
             */
            return ['status' => self::UNKNOWN, 'detail' => 'nem megkérdezhető: '.$e::class];
        }
    }

    /** @return array{status: string, detail: ?string} */
    private function database(): array
    {
        $start = microtime(true);

        /*
         | PDO-SZINTŰ PING, nem Eloquent-lekérdezés.
         |
         | Ez a próba SEMMILYEN tenant-táblát nem érint: a `select 1` a KAPCSOLAT
         | életjelét méri, nem adatot olvas. Ezért nem is scope-megkerülés: az arch-őr a
         | nyers lekérdező helpereket (helyesen) a global scope megkerülésének tekinti, itt
         | viszont nincs mit megkerülni — ezért is PDO-szintű a próba, nem query-builderes.
         |
         | Miért `select 1` és nem puszta `getPdo()`: egy elavult, poolozott kapcsolat
         | „csatlakozottnak" LÁTSZIK, amíg nem küldünk rá valamit. Egy readiness-próba,
         | ami csak a kapcsolat-objektumot nézi, zöldet mutat egy halott kapcsolatra.
         */
        DB::connection()->getPdo()->query('select 1');

        $ms = (int) round((microtime(true) - $start) * 1000);

        return [
            // A LASSÚ adatbázis nem „healthy": a küldés még megy, de a következő
            // percben már nem biztos. A `degraded` az, ami odanézésre késztet.
            'status' => $ms > 500 ? self::DEGRADED : self::HEALTHY,
            'detail' => $ms.' ms',
        ];
    }

    /** @return array{status: string, detail: ?string} */
    private function redis(): array
    {
        Redis::connection()->ping();

        return ['status' => self::HEALTHY, 'detail' => null];
    }

    /** @return array{status: string, detail: ?string} */
    private function queue(): array
    {
        /*
         | A SOR MÉLYSÉGE, nem a „fut-e a worker".
         |
         | Egy futó worker nulla haladással ugyanolyan baj, mint egy leállt. A mélység az,
         | ami megmondja, hogy a levelek TORLÓDNAK-e — és a `campaign` sor mélysége
         | természetesen nagy lehet (százezres kampány), ezért a TRANZAKCIÓS sort nézzük:
         | ott a torlódás azonnal ügyfél-probléma (jelszó-visszaállítás, számla).
         */
        /*
         | ELŐBB A DRIVER, csak utána a mélység.
         |
         | A `sync` driver mellett a `Queue::size()` MINDIG 0, tehát a próba `healthy`-t
         | adott — worker nélkül, sor nélkül is (mérve: a teljes riport `healthy`). Ez pont
         | az a „zöld, mert nem tudjuk", amit ez az osztály a legsúlyosabb hibának nevez: a
         | 0 mélység és a „nincs is sor" megkülönböztethetetlen volt.
         */
        $driver = (string) config('queue.default');

        if ($driver !== 'redis') {
            return [
                'status' => self::UNKNOWN,
                'detail' => 'a sor-driver „'.$driver.'” — a mélység nem mérhető',
            ];
        }

        $depth = Queue::size('transactional');

        /*
         | A WORKER ÉLETJELE a mélység MELLETT.
         |
         | Egy üres sor lehet azért üres, mert nincs mit vinni — és lehet azért, mert nincs
         | miből: leállt Horizon mellett a mélység előbb-utóbb nő, de az ELSŐ percekben még
         | 0, és pont akkor kellene szólni. A `MasterSupervisorRepository` a Horizon saját
         | életjel-tárolója; ha nincs benne futó master, a sort senki nem hajtja.
         */
        $masters = app(MasterSupervisorRepository::class)->all();

        if ($masters === []) {
            return [
                'status' => self::DOWN,
                'detail' => 'nem fut Horizon worker ('.$depth.' üzenet a tranzakciós soron)',
            ];
        }

        return [
            'status' => match (true) {
                $depth > 10000 => self::DOWN,
                $depth > 1000 => self::DEGRADED,
                default => self::HEALTHY,
            },
            'detail' => $depth.' üzenet a tranzakciós soron',
        ];
    }

    /** @return array{status: string, detail: ?string} */
    private function sendingDriver(): array
    {
        /*
         | A DRIVER ÁLLAPOTA a Sending modul SZŰK ablakán át.
         |
         | A `GateBypassGuardTest` invariánsa: a `SendingDriver`-t KIZÁRÓLAG a
         | `MessageDispatcher` birtokolhatja — mert aki birtokolja, küldeni is tud vele, a
         | PreSendGate megkerülésével. Egy külön „csak health" osztály is birtokolná, tehát
         | a szabályt egy új osztállyal nem betartani, hanem megkerülni lehetne.
         |
         | Ezért a dispatchert kérdezzük: a `driverHealth()` semmit nem küld, egy `bool` +
         | szöveg jön vissza.
         */
        $health = app(MessageDispatcher::class)->driverHealth();

        return [
            'status' => $health->healthy ? self::HEALTHY : self::DOWN,
            'detail' => $health->detail === '' ? null : $health->detail,
        ];
    }

    /** @return array{status: string, detail: ?string} */
    private function search(): array
    {
        $host = rtrim((string) config('scout.meilisearch.host'), '/');

        if ($host === '') {
            // A NEM KONFIGURÁLT és a NEM VÁLASZOLÓ komponens nem ugyanaz. Korábban
            // mindkettő `degraded` volt, tehát megkülönböztethetetlen — az operátor a
            // keresőt kereste, miközben a config volt üres.
            return ['status' => self::UNKNOWN, 'detail' => 'a keresés nincs beállítva'];
        }

        /*
         | RÖVID TIMEOUT — és ez nem kozmetika volt.
         |
         | A korábbi `@file_get_contents()` timeout NÉLKÜL futott, a HTTP-wrapper pedig a
         | `default_socket_timeout`-ot (60 s) használja: egy blackholeolt Meili mellett a
         | teljes health-riport 60 másodpercig állt (mérve). A 30 s-os cache nem segített,
         | mert a próba HOSSZABB volt, mint a TTL — a bejegyzés lejárt, mire elkészült,
         | tehát MINDEN riport-hívás újra 60 s-et várt. Egy monitorozó szolgáltatás, ami a
         | figyelt komponens hibájától válik használhatatlanná, nem monitorozás.
         |
         | A cache-TTL (60 s) ezért NAGYOBB, mint a próba felső ideje (2 s).
         */
        $healthy = Cache::remember('cluster-health:search', 60, static function () use ($host): bool {
            $response = Http::connectTimeout(1)->timeout(2)->get($host.'/health');

            return $response->successful() && str_contains($response->body(), 'available');
        });

        // A MEILISEARCH KIESÉSE nem állít meg levelet: a keresés romlik, a küldés nem. Ezért
        // a legrosszabb, amit adhat, a `degraded` — egy `down` itt fölöslegesen riasztana
        // éjjel egy olyan komponensre, ami reggelig várhat. A KIVÉTEL viszont `unknown`
        // (a `check()` fogja el): akkor nem tudunk semmit, és az nem állítás.
        return [
            'status' => $healthy ? self::HEALTHY : self::DEGRADED,
            'detail' => $healthy ? null : 'a keresés nem elérhető (a küldést nem érinti)',
        ];
    }

    /**
     * AZ MX-FOGADÓ (86. kritérium): fogad-e egyáltalán bejövő levelet.
     *
     * A próba TCP-szintű, és szándékosan nem SMTP-párbeszéd: a `220` bevárása egy lassú
     * listeneren a health-riportot fogná meg, a kapcsolat felépülése pedig már megválaszolja
     * a kérdést („hallgat-e valaki a 25-esen"). A DNS-oldali MX-rekordot NEM ez a próba
     * nézi — arra az `inbound:verify-mx` ütemezett parancs van, domainenként.
     *
     * @return array{status: string, detail: ?string}
     */
    private function inboundMx(): array
    {
        if (! (bool) config('inbound.enabled')) {
            return ['status' => self::DISABLED, 'detail' => 'a bejövő fogadás kikapcsolva'];
        }

        // A CÉL configból: a listener KÜLÖN konténerben fut (`inbound-mx`, 2525), nem az
        // app-konténer loopbackján, és az `allowed_ports` az elfogadott portok listája —
        // nem a futó listener címe.
        $host = (string) config('inbound.health.host');
        $port = (int) config('inbound.health.port');

        if ($host === '' || $port <= 0) {
            return ['status' => self::UNKNOWN, 'detail' => 'az MX-próba célja nincs beállítva'];
        }

        $socket = @fsockopen($host, $port, $code, $message, 1.0);

        if ($socket === false) {
            return ['status' => self::DOWN, 'detail' => 'az MX-listener nem elérhető ('.$host.':'.$port.')'];
        }

        fclose($socket);

        return ['status' => self::HEALTHY, 'detail' => 'MX-listener: '.$host.':'.$port];
    }

    /** @return array{status: string, detail: ?string} */
    private function ipPool(): array
    {
        $quarantined = IpAddress::query()->whereNotNull('quarantined_at')->count();
        $total = IpAddress::query()->count();

        return [
            'status' => match (true) {
                // MINDEN IP karanténban: nincs miről küldeni.
                $total > 0 && $quarantined >= $total => self::DOWN,
                $quarantined > 0 => self::DEGRADED,
                default => self::HEALTHY,
            },
            'detail' => $quarantined.'/'.$total.' IP karanténban',
        ];
    }

    /**
     * AZ ÖSSZESÍTETT állapot — a LEGROSSZABB komponens dönt.
     *
     * Nem átlagolunk: öt zöld és egy piros komponens nem „nagyrészt jó", hanem BAJ. Az
     * `unknown` a `degraded` szintjén áll: nem tudjuk, hogy baj van-e, tehát oda kell
     * nézni — de nem állítunk kiesést.
     *
     * @param  array<string, array{status: string, detail: ?string}>  $components
     */
    private function overall(array $components): string
    {
        $statuses = array_column($components, 'status');

        return match (true) {
            in_array(self::DOWN, $statuses, true) => self::DOWN,
            in_array(self::DEGRADED, $statuses, true) => self::DEGRADED,
            in_array(self::UNKNOWN, $statuses, true) => self::UNKNOWN,
            default => self::HEALTHY,
        };
    }
}
