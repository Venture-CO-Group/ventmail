<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Az XLSX-sorolvasás KÜLÖN, erőforrás-korlátos gyerekfolyamatban (v1.1 · modul 5, 10. review-kör;
 * orchestrátor-döntés D220, megvalósítás D221–D226).
 *
 * ## Miért nem még egy plafon
 *
 * Az openspout néhány útja a PHP `memory_limit`-jét FATAL-lal lépi túl (a `<row spans="1:N">`
 * `array_fill`-je egy 1 KB-os fájlból), vagy a libxml saját memóriájában — a `memory_limit`-en KÍVÜL
 * — épít DOM-ot (`expand()` egy óriási `<c>` / `<si>` részfán: mérve 1,9 GB maxrss). Egy fatal-t a
 * `catch` nem fog meg, egy cgroup OOM-kill pedig a teljes import-workert (mindkét supervisort) viszi.
 * Ezeket nem bájtmintákkal üldözzük: az olvasás egy eldobható folyamatban fut.
 *
 * ## A korlátok
 *
 *  - CÍMTÉR (Linux): `sh -c 'ulimit -v <KB>; exec php …'` — a libxml malloc-ja is ebbe számít;
 *  - `-d memory_limit` (mindenhol) és `opcache.enable_cli=0` (a JIT-puffer ne foglaljon címteret);
 *  - FALIÓRA: a lejáratkor a gyerek SIGKILL-t kap, és a szülő nem vár rá tovább.
 * macOS-en (fejlesztés) a `ulimit -v` nem hat: ott a memory_limit + időkeret marad, és ez egyszer naplózva.
 *
 * ## Szemantika
 *
 * A gyerek UGYANAZT az `XlsxRowReader`-t futtatja (ugyanazok a cellák, sorszámok, plafonok), és NDJSON-ban
 * küldi a szülőnek; a szülő a meglévő sor-pipeline-on dolgozik tovább. Bármely rendellenes kilépés
 * (fatal, signal, OOM-kill, nem nulla exit, csonka kimenet) `ImportLimitExceeded('resources')`, az
 * időtúllépés `timeout`, a gyerek kezelt hibája a saját oka (`rows`, `columns`, `format`) — a hívók
 * (felmérés, import) ezt már elegáns elutasításként kezelik: a job nem hal meg, nem próbálkozik újra.
 */
final class IsolatedXlsxRowReader implements ImportRowReader
{
    /** A gyerek által küldhető (és a szülő által továbbengedett) okok. */
    private const CHILD_REASONS = ['rows', 'columns', 'format', 'sheet', 'resources'];

    /** A stderr-ből legfeljebb ennyi bájtot tartunk meg (csak a puffer ürítéséhez, nem naplózzuk). */
    private const STDERR_CAP = 8192;

    /**
     * Egy NDJSON-sor felső mérete (review r11 N2): 100 oszlop × 2000 karakter, JSON-escape-pel is jóval
     * alatta. Ennél hosszabb, újsor nélküli kimenet nem a protokoll — erőforrás-hiba.
     */
    private const MAX_LINE_BYTES = 4 * 1024 * 1024;

    /** A gyerek környezetéből CSAK ezek öröklődnek (review r11 S1): titok (APP_KEY, DB_*, …) soha. */
    private const ENV_PASSTHROUGH = ['PHPRC', 'PHP_INI_SCAN_DIR', 'TMPDIR', 'TMP', 'TEMP'];

    /** TESZT-kapcsoló: a gyerek a környezetét és nyitott fd-it jelenti olvasás helyett. */
    private static bool $testProbe = false;

    /** TESZT-kapcsoló: a gyerek ennyi másodpercig vár olvasás előtt. Csak `runningUnitTests()` alatt utazik. */
    private static int $testDelay = 0;

    private static bool $fallbackLogged = false;

    private ?int $childPid = null;

    public function __construct(
        private readonly string $path,
        private readonly ?string $sheet,
        private readonly ?string $tempFolder,
        private readonly int $timeoutSeconds,
    ) {}

    /** Linuxon a `ulimit -v` (RLIMIT_AS) érvényes; macOS-en nem. */
    public static function addressSpaceLimitSupported(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && is_executable('/bin/sh');
    }

    public static function delayChildForTests(int $seconds): void
    {
        self::$testDelay = max(0, $seconds);
    }

    /** Az utolsó gyerekfolyamat PID-je (teszthez: a korai leállás után már nem él). */
    public function lastChildPid(): ?int
    {
        return $this->childPid;
    }

    /** @return Generator<int, list<string>> */
    public function rows(): Generator
    {
        $pipes = [];
        // MINIMÁLIS környezet (review r11 S1): a gyerek nem látja a szülő titkait (APP_KEY, DB_PASSWORD,
        // REDIS_PASSWORD …). A php.ini/bővítmény-útvonalak a binárisba fordítottak; ha mégis env adja
        // (`PHPRC`, `PHP_INI_SCAN_DIR`), az öröklődik — titkot nem hordoz.
        $process = proc_open($this->command(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::childEnvironment());

        if (! is_resource($process)) {
            throw new ImportLimitExceeded('resources');
        }

        $this->childPid = (int) (proc_get_status($process)['pid'] ?? 0) ?: null;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + max(1, $this->timeoutSeconds);
        $buffer = '';
        $stderr = '';
        $done = false;
        $emitted = 0;
        // A kimenetel a naplózáshoz (review r11 N1): `null` = a hívó korán leállt (break) — a saját
        // SIGKILL-ünk nem rendellenes kilépés; `died` = a gyerek magától halt meg; `timeout` = mi öltük meg.
        $outcome = null;

        try {
            while (true) {
                // Teljes sorok feldolgozása a pufferből.
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $message = json_decode($line, true);

                    if (! is_array($message)) {
                        throw new ImportLimitExceeded('resources');
                    }

                    if (array_key_exists('l', $message) && is_array($message['c'] ?? null)) {
                        $emitted++;

                        yield (int) $message['l'] => array_map(static fn ($cell): string => (string) $cell, array_values($message['c']));

                        continue;
                    }

                    if (isset($message['error'])) {
                        $reason = (string) $message['error'];

                        throw new ImportLimitExceeded(in_array($reason, self::CHILD_REASONS, true) ? $reason : 'format');
                    }

                    if (($message['done'] ?? false) === true) {
                        // A sorok száma egyezik: a kimenet nem csonka.
                        if ((int) ($message['rows'] ?? -1) !== $emitted) {
                            throw new ImportLimitExceeded('resources');
                        }

                        $done = true;
                    }
                }

                if ($done) {
                    return;
                }

                if (feof($pipes[1])) {
                    // A gyerek kimenete véget ért `done` nélkül: fatal, signal, OOM-kill.
                    $outcome = 'died';

                    throw new ImportLimitExceeded('resources');
                }

                // Újsor nélküli, túl hosszú kimenet: nem a protokoll (review r11 N2).
                if (strlen($buffer) > self::MAX_LINE_BYTES) {
                    $outcome = 'died';

                    throw new ImportLimitExceeded('resources');
                }

                $remaining = $deadline - microtime(true);

                if ($remaining <= 0) {
                    $outcome = 'timeout';

                    throw new ImportLimitExceeded('timeout');
                }

                $read = [$pipes[1], $pipes[2]];
                $write = $except = null;
                $seconds = (int) min(1, $remaining);
                $micro = $seconds === 0 ? (int) (max(0.05, min(1, $remaining)) * 1_000_000) : 0;

                if (@stream_select($read, $write, $except, $seconds, $micro) === false) {
                    $outcome = 'died';

                    throw new ImportLimitExceeded('resources');
                }

                foreach ($read as $stream) {
                    $chunk = (string) fread($stream, 65536);

                    if ($stream === $pipes[1]) {
                        $buffer .= $chunk;
                    } elseif (strlen($stderr) < self::STDERR_CAP) {
                        $stderr .= $chunk;
                    }
                }
            }
        } finally {
            $this->terminate($process, $pipes, $done ? 'done' : $outcome, $stderr);
        }
    }

    /**
     * TESZT: a gyerek KÖRNYEZETE és NYITOTT fd-i, ugyanazzal a parancssal és env-vel, ahogy az olvasáskor
     * indul (review r11 S1). Éles futásban nem hívható.
     *
     * @return array{env: list<string>, fds: list<string>}
     */
    public function probeChildForTests(): array
    {
        if (! app()->runningUnitTests()) {
            throw new \LogicException('Csak tesztben.');
        }

        self::$testProbe = true;

        try {
            $pipes = [];
            $process = proc_open($this->command(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::childEnvironment());
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            self::$testProbe = false;
        }

        $probe = json_decode(trim($output), true);

        return is_array($probe['probe'] ?? null) ? $probe['probe'] : ['env' => [], 'fds' => []];
    }

    /** @return array<string, string> */
    private static function childEnvironment(): array
    {
        $environment = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];

        foreach (self::ENV_PASSTHROUGH as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /** @return list<string> */
    private function command(): array
    {
        $php = (string) (config('audience.import.xlsx_child_php') ?: (PHP_SAPI === 'cli' ? PHP_BINARY : 'php'));
        $memory = max(16, (int) config('audience.import.xlsx_child_memory_mb'));

        $options = [
            'path' => $this->path,
            'sheet' => $this->sheet,
            'temp' => $this->tempFolder,
            'maxColumns' => (int) config('audience.import.max_columns'),
            'maxCell' => (int) config('audience.import.max_cell_length'),
            'maxRows' => (int) config('audience.import.max_rows'),
            // TESZT-kapcsoló: éles futásban SOHA nem utazik (a gyerek csak ezzel a kulccsal vár).
            'delay' => app()->runningUnitTests() ? self::$testDelay : 0,
            'probe' => app()->runningUnitTests() && self::$testProbe,
        ];

        // A php.ini (a bővítmények betöltése) öröklődik; csak a korlátok és a hiba-kimenet felülírva.
        $child = [
            $php,
            '-d', 'memory_limit='.$memory.'M',
            '-d', 'opcache.enable_cli=0',
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            '-d', 'zend.exception_ignore_args=1',
            base_path('app/Modules/Audience/Resources/xlsx-reader-child.php'),
            json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        if (! is_executable('/bin/sh')) {
            return $child;
        }

        // A WRAPPER (review r11 S1/S2): (1) a 2 fölötti ÖSSZES örökölt fd lezárva az `exec` ELŐTT — a
        // szülő socketjei (Redis, Postgres) és fájljai nem jutnak a gyerekhez; (2) CPU-idő plafon
        // (`ulimit -t`, a falióra-keret + 10 mp): egy árva gyerek sem pörög a végtelenségig; (3) Linuxon a
        // címtér-limit (`ulimit -v`, a libxml malloc-ja is alá esik). A `/proc/$$/fd` Linuxon, a `/dev/fd`
        // macOS-en sorolja a shell saját fd-it.
        $limits = 'ulimit -t '.(max(1, $this->timeoutSeconds) + 10);

        if (self::addressSpaceLimitSupported()) {
            $limits .= ' && ulimit -v '.max(65536, (int) config('audience.import.xlsx_child_address_space_mb') * 1024);
        } elseif (! self::$fallbackLogged) {
            self::$fallbackLogged = true;
            Log::info('XLSX-gyerekolvasó: címtér-limit ezen a platformon nem támogatott — memory_limit + CPU- és időkeret marad.', [
                'os' => PHP_OS_FAMILY,
            ]);
        }

        $closeInherited = 'for f in /proc/$$/fd/* /dev/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done';

        return ['/bin/sh', '-c', $closeInherited.'; '.$limits.' && exec "$@"', 'xlsx-reader', ...$child];
    }

    /**
     * A gyerek LEÁLLÍTÁSA és begyűjtése — minden úton (siker, hiba, korai `break`, időtúllépés).
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function terminate($process, array $pipes, ?string $outcome, string $stderr): void
    {
        $status = proc_get_status($process);

        if (($status['running'] ?? false) === true) {
            proc_terminate($process, 9);
        }

        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index] ?? null)) {
                fclose($pipes[$index]);
            }
        }

        $exit = proc_close($process);

        // A hívó korai leállása (`break`, pl. a felmérés mintavétele) utáni SAJÁT SIGKILL nem rendellenes —
        // nem naplózzuk (review r11 N1). A magától meghalt gyerek és az időtúllépés igen.
        if ($outcome === 'died' || $outcome === 'timeout') {
            // NEM naplózunk tartalmat (a stderr útvonalat, a fájl darabját tartalmazhatja) — csak a tényt.
            Log::info('XLSX-gyerekolvasó rendellenesen lépett ki.', [
                'outcome' => $outcome,
                'exit' => $exit,
                'stderr_bytes' => strlen($stderr),
            ]);
        }
    }
}
