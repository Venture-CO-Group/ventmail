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
        $process = proc_open($this->command(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

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
                    throw new ImportLimitExceeded('resources');
                }

                $remaining = $deadline - microtime(true);

                if ($remaining <= 0) {
                    throw new ImportLimitExceeded('timeout');
                }

                $read = [$pipes[1], $pipes[2]];
                $write = $except = null;
                $seconds = (int) min(1, $remaining);
                $micro = $seconds === 0 ? (int) (max(0.05, min(1, $remaining)) * 1_000_000) : 0;

                if (@stream_select($read, $write, $except, $seconds, $micro) === false) {
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
            $this->terminate($process, $pipes, $done, $stderr);
        }
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

        if (self::addressSpaceLimitSupported()) {
            $kilobytes = max(65536, (int) config('audience.import.xlsx_child_address_space_mb') * 1024);

            // A címtér-limit a `exec` ELŐTT áll be, tehát a PHP minden foglalása (a libxml malloc-ja is) alá esik.
            return ['/bin/sh', '-c', 'ulimit -v '.$kilobytes.' && exec "$@"', 'xlsx-reader', ...$child];
        }

        if (! self::$fallbackLogged) {
            self::$fallbackLogged = true;
            Log::info('XLSX-gyerekolvasó: címtér-limit ezen a platformon nem támogatott — memory_limit + időkeret marad.', [
                'os' => PHP_OS_FAMILY,
            ]);
        }

        return $child;
    }

    /**
     * A gyerek LEÁLLÍTÁSA és begyűjtése — minden úton (siker, hiba, korai `break`, időtúllépés).
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function terminate($process, array $pipes, bool $done, string $stderr): void
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

        if (! $done && $exit !== 0) {
            // NEM naplózunk tartalmat (a stderr útvonalat, a fájl darabját tartalmazhatja) — csak a tényt.
            Log::info('XLSX-gyerekolvasó rendellenesen lépett ki.', [
                'exit' => $exit,
                'stderr_bytes' => strlen($stderr),
            ]);
        }
    }
}
