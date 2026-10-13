<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Validation\Services\EmailSyntax;
use ZipArchive;

/**
 * Az import-fájl FELMÉRÉSE feltöltéskor: formátum-részletek, fejléc, minta, sorszám.
 *
 * Minden mérés STREAMELT — egy 300 000 soros fájl felmérése sem tölti be a fájlt a
 * memóriába. A határok (sor, oszlop, zip-bomba) is itt dőlnek el, MIELŐTT bármi a
 * feldolgozásig jutna.
 */
final class ImportFileInspector
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    public function __construct(private readonly EmailSyntax $syntax) {}

    /**
     * OLCSÓ ŐRÖK a KÉRÉSBEN (D71): zip-szerkezet + valós bájt- és csomópont-számlálás streamen. Az
     * openspout NEM fut; a teljes felmérés a háttérjobban (`inspect()`).
     *
     * @throws ImportLimitExceeded
     */
    public function guard(string $path, string $format): void
    {
        $this->deadline = microtime(true) + (int) config('audience.import.inspect_budget_seconds');

        if ($format === 'xlsx') {
            $this->assertSafeZip($path);
        }
    }

    /**
     * @return array{
     *     format: string, delimiter: string, encoding: string, sheets: list<string>,
     *     sheet: string|null, hasHeader: bool, columns: list<string>, width: int,
     *     sample: list<list<string>>, records: int, rows: int
     * }
     *
     * @throws ImportLimitExceeded
     */
    public function inspect(string $path, string $format, ?string $sheet = null, ?int $budget = null, ?string $tempFolder = null): array
    {
        $this->deadline = microtime(true) + ($budget ?? (int) config('audience.import.inspect_budget_seconds'));

        return $format === 'xlsx' ? $this->xlsx($path, $sheet, $tempFolder) : $this->csv($path);
    }

    /** A felmérés időkerete (a kérésen belül fut — lásd a konfig `inspect_budget_seconds`). */
    private float $deadline = 0.0;

    private function checkBudget(): void
    {
        if (microtime(true) > $this->deadline) {
            throw new ImportLimitExceeded('timeout');
        }
    }

    /** @return array<string, mixed> */
    private function csv(string $path): array
    {
        $delimiter = $this->detectDelimiter($path);
        $encoding = $this->detectEncoding($path);

        return $this->profile(
            new CsvRowReader($path, $delimiter, $encoding),
            format: 'csv',
            delimiter: $delimiter,
            encoding: $encoding,
            sheets: [],
            sheet: null,
        );
    }

    /** @return array<string, mixed> */
    private function xlsx(string $path, ?string $sheet, ?string $tempFolder): array
    {
        // A zip-ellenőrzés EGYBEN gyűjti a munkalapok csomópont- és sorstatisztikáját: a
        // felmérés a teljes XML-t SOHA nem a (lassú) openspout-tal járja végig — az a háttérjob
        // dolga. Egy `<row>` nélküli, csomóponttal teli `sheetData` (a security-review 9,9 MB-os,
        // 200 MB-ra nyíló fájlja: 50 s) ezen a ponton, másodpercek alatt elbukik.
        $stats = $this->assertSafeZip($path);

        $sheets = $this->sheetNames($path);

        if ($sheets === []) {
            throw new ImportLimitExceeded('format');
        }

        $sheet ??= $sheets[0];

        if (! in_array($sheet, $sheets, true)) {
            throw new ImportLimitExceeded('sheet');
        }

        $file = $this->sheetFile($path, $sheet);
        // A munkalap-fájl statisztikája nélkül (szokatlan nevű bejegyzés) nincs mit felmérni: a
        // nem-munkalap bejegyzés a szigorú meta-plafont kapta, és ide sosem jut el sorokkal.
        if (! isset($stats[strtolower($file)]) && ! isset($stats[$file])) {
            throw new ImportLimitExceeded('format');
        }

        $records = (int) (($stats[$file] ?? $stats[strtolower($file)])['rows'] ?? 0);

        return $this->profile(
            new XlsxRowReader($path, $sheet, $tempFolder),
            format: 'xlsx',
            delimiter: ',',
            encoding: 'UTF-8',
            sheets: $sheets,
            sheet: $sheet,
            knownRecords: $records,
        );
    }

    /**
     * @param  list<string>  $sheets
     * @return array<string, mixed>
     */
    private function profile(
        ImportRowReader $reader,
        string $format,
        string $delimiter,
        string $encoding,
        array $sheets,
        ?string $sheet,
        ?int $knownRecords = null,
    ): array {
        $first = null;
        $sample = [];
        $count = 0;
        $width = 0;

        $rows = $reader->rows();

        foreach ($rows as $cells) {
            if (($count & 1023) === 0) {
                $this->checkBudget();
            }

            // XLSX: a sorszámot a zip-menet adta; a mintához elég az első néhány sor (a
            // generátor lezárásával az olvasó is leáll, nem megy végig a fájlon).
            if ($knownRecords !== null && $count >= 6) {
                break;
            }

            $count++;
            $width = max($width, count($cells));

            if ($count === 1) {
                $first = $cells;

                continue;
            }

            if (count($sample) < 5) {
                $sample[] = $cells;
            }
        }

        unset($rows);

        if ($knownRecords !== null) {
            $count = $knownRecords;
        }

        $first ??= [];
        $hasHeader = $first !== [] && ! $this->containsEmail($first);

        if (! $hasHeader && $first !== []) {
            // Fejléc nélkül az első sor ADAT: a mintába és a számba is beleszámít.
            array_unshift($sample, $first);
            $sample = array_slice($sample, 0, 5);
        }

        return [
            'format' => $format,
            'delimiter' => $delimiter,
            'encoding' => $encoding,
            'sheets' => $sheets,
            'sheet' => $sheet,
            'hasHeader' => $hasHeader,
            'columns' => $hasHeader ? array_values($first) : [],
            'width' => $width,
            'sample' => $sample,
            // A fájl nem üres rekordjai (a fejléccel együtt): a `rows` ebből számolódik újra,
            // ha a felhasználó átállítja a „van fejléc" jelölőt.
            'records' => $count,
            'rows' => $hasHeader ? max(0, $count - 1) : $count,
        ];
    }

    /** @param list<string> $cells */
    private function containsEmail(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (str_contains($cell, '@') && $this->syntax->valid($cell)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ELVÁLASZTÓ az első sorokból: az nyer, amelyik a legtöbb sorban ugyanannyi (>1) mezőre
     * bontja a szöveget. Az idézőjelet a `str_getcsv` kezeli, tehát a mezőn belüli
     * elválasztó nem téveszt meg.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $lines = [];

        while ($handle !== false && count($lines) < 20 && ($line = fgets($handle, 65536)) !== false) {
            if (trim($line) !== '') {
                $lines[] = rtrim($line, "\r\n");
            }
        }

        if ($handle !== false) {
            fclose($handle);
        }

        if ($lines !== []) {
            $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        }

        $best = ',';
        $bestScore = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(static fn (string $line): int => count(str_getcsv($line, $delimiter, '"', '')), $lines);
            $frequency = array_count_values($counts);
            arsort($frequency);
            $modal = array_key_first($frequency);

            if ($modal === null || $modal < 2) {
                continue;
            }

            $score = $frequency[$modal] * 1000 + $modal;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * KÓDOLÁS: érvényes UTF-8 (BOM-mal vagy anélkül) → `UTF-8`, különben `Windows-1250`.
     * Soronként ellenőrzünk, tehát a memória állandó; a többbájtos karaktert nem vághatja el
     * a sorhatár (az újsor ASCII).
     */
    private function detectEncoding(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 'UTF-8';
        }

        try {
            $cap = (int) config('audience.import.max_line_bytes');

            // UTF-16 BOM: a fájl nem ASCII-kompatibilis, a sorok NUL-bájtokkal tele — az olvasó
            // UTF-8-nak venné, és a NUL a mintából az adatbázisba jutna. Kódolási hiba.
            $bom = (string) fread($handle, 2);
            rewind($handle);

            if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
                throw new ImportLimitExceeded('encoding');
            }

            while (($line = fgets($handle, $cap + 1)) !== false) {
                // NUL-bájt sehol a CSV-ben (UTF-16 BOM nélkül, vagy hibás export): a Postgres
                // szövegben nem tárolja, és a hibaüzenete a címeket tartalmazná.
                if (str_contains($line, "\0")) {
                    throw new ImportLimitExceeded('encoding');
                }

                // Újsor nélkül a határig teli sor = a fizikai sor túl hosszú.
                if (strlen($line) > $cap || (strlen($line) === $cap && ! str_ends_with($line, "\n"))) {
                    throw new ImportLimitExceeded('line');
                }

                $this->checkBudget();

                if (! mb_check_encoding($line, 'UTF-8')) {
                    return 'Windows-1250';
                }
            }
        } finally {
            fclose($handle);
        }

        return 'UTF-8';
    }

    /**
     * ZIP-BOMBA ŐR — a kitömörített méretet a zip KÖZPONTI JEGYZÉKÉBŐL olvassuk, nulla
     * bájt kitömörítése nélkül. Három határ: bejegyzések száma, összméret, és a tömörítési
     * arány (a nullákból álló 1 GB néhány KB-ra tömörödik — az arány ezt fogja meg akkor is,
     * ha az összméret belefér).
     */
    private function assertSafeZip(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportLimitExceeded('format');
        }

        try {
            if ($zip->numFiles > (int) config('audience.import.xlsx_max_entries')) {
                throw new ImportLimitExceeded('zip');
            }

            $total = 0;
            $maxRatio = (int) config('audience.import.xlsx_max_ratio');
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    throw new ImportLimitExceeded('format');
                }

                $names[] = $stat['name'];
                $total += (int) $stat['size'];

                if ($total > (int) config('audience.import.xlsx_max_uncompressed_bytes')) {
                    throw new ImportLimitExceeded('zip');
                }

                if ($stat['size'] > 1024 * 1024 && $stat['comp_size'] > 0 && $maxRatio < $stat['size'] / $stat['comp_size']) {
                    throw new ImportLimitExceeded('zip');
                }
            }

            if (! in_array('[Content_Types].xml', $names, true) || ! in_array('xl/workbook.xml', $names, true)) {
                throw new ImportLimitExceeded('format');
            }

            // A SZEREPEK (munkalapok, sharedStrings, styles) az openspout feloldásával, a bájt-számlálás
            // ELŐTT (review r8 S1): a plafonokat a feloldott szerep választja, nem a bejegyzés neve.
            $roles = $this->resolveRoles($zip);

            $stats = $this->countRealBytes($zip, $roles);

            // A SZERKEZETI őr (review r7, D179): a bájt-ellenőrzések UTÁN, ugyanazzal a parserrel, amit
            // az openspout használ.
            $this->assertStructure($path, $zip, $roles);

            return $stats;
        } finally {
            $zip->close();
        }
    }

    /**
     * A VALÓS kitömörített bájtok számolása — a központi jegyzék mérete HAMISÍTHATÓ (a security-
     * review 205 KB-os fájlja 1000 bájtot hirdetett, és 200 MB-ot töltött ki ~55 mp CPU-n).
     * Streamen olvasunk, és megszakítjuk, ha egy bejegyzés meghaladja a deklarált méretét, vagy az
     * összeg a plafont, vagy az idő a keretet. A leállás a plafonnál történik, nem a végén.
     */
    /** @param  array{shared: string|null, styles: string|null, sheets: list<string>}  $roles */
    private function countRealBytes(ZipArchive $zip, array $roles): array
    {
        $ceiling = (int) config('audience.import.xlsx_max_uncompressed_bytes');
        $maxNodes = (int) config('audience.import.xlsx_max_nodes');
        $beforeData = (int) config('audience.import.xlsx_max_nodes_before_data');
        $total = 0;
        $stats = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $declared = (int) $stat['size'];
            // INDEX alapján, nem név alapján: egy ismétlődő nevű bejegyzés névvel a MÁSIKAT adná.
            $stream = $zip->getStreamIndex($i);

            if ($stream === false) {
                throw new ImportLimitExceeded('format');
            }

            // NÉV-FÜGGETLEN számlálás: az XLSX bejegyzés-neve a `rels` Target-jéből jön, tehát
            // `.bin`/`.XML`/bármi lehet — a csomópontot MINDEN bejegyzésben számoljuk, a név csak a
            // plafont választja (a nem-munkalap bejegyzés a szigorú meta-plafont kapja).
            $lowerName = strtolower($stat['name']);
            // A SZEREP a feloldott hivatkozásból (review r8 S1), pontos névegyezéssel (az openspout is a
            // pontos néven nyit): egy `worksheets/` alá tett sharedStrings így nem kaphatja meg a
            // munkalap 1 GB-os plafonját, és egy átnevezett sharedStrings sem kerüli meg a sajátját.
            $isSheet = in_array($stat['name'], $roles['sheets'], true);
            $isShared = $roles['shared'] !== null && $stat['name'] === $roles['shared'];
            $isStyles = $roles['styles'] !== null && $stat['name'] === $roles['styles'];
            // A styles saját, MÉRT plafont kap (review r9 N1, D204) — az openspout teljesen beolvassa.
            $partCap = match (true) {
                $isShared => (int) config('audience.import.xlsx_max_part_nodes'),
                $isStyles => (int) config('audience.import.xlsx_max_styles_nodes'),
                default => (int) config('audience.import.xlsx_max_meta_nodes'),
            };
            // BÁJT-plafon a nem-munkalap bejegyzésekre: a bájt-nehéz, kevés `<`-t tartalmazó sharedStrings
            // (nagy `<t>` szövegek) a csomópont-plafont nem érinti, de az openspout a memóriába
            // olvassa (mérve: 950 MB-ra nyíló fájl OOM-ot okozott). A sharedStrings 64 MB, a többi 16 MB.
            $byteCap = $isShared
                ? (int) config('audience.import.xlsx_max_shared_strings_bytes')
                : (int) config('audience.import.xlsx_max_meta_bytes');
            // A leghosszabb `<`-mentes szakasz (egy szövegcsomópont) legfeljebb 4 × a cella-hossz plafon.
            $maxRun = 4 * (int) config('audience.import.max_cell_length');
            $checkRun = $isSheet || $isShared;
            $run = 0;
            $entry = 0;
            $nodes = 0;
            $rows = 0;
            $sixthRowNodes = null;
            $carry = '';
            // A DTD-keresés farka: a `<!DOCTYPE` / `<!ENTITY` chunkhatáron kettévágva is megfogott.
            $declTail = '';
            // SZÖVEG-RÉSZ (review r6 S4b): MINDEN bejegyzés, kivéve az ismert bináris helyeket (kép,
            // nyomtatóbeállítás, beágyazott objektum, miniatűr). A név nem dönt (a `rels` Target bármi
            // lehet, pl. `.bin` munkalap); hogy az XML-olvasó ne kaphasson bináris helyről részt, azt a
            // szerkezeti őr (`assertStructure()`) biztosítja.
            $isTextPart = ! XlsxStructure::isBinaryPart($lowerName);
            // Az első nem-BOM / nem-szóköz bájt még hátravan (több chunkon át is követjük).
            $awaitingFirstByte = true;

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);

                    if ($chunk === false) {
                        throw new ImportLimitExceeded('format');
                    }

                    $entry += strlen($chunk);
                    $total += strlen($chunk);

                    if ($entry > $declared || $total > $ceiling) {
                        throw new ImportLimitExceeded('zip');
                    }

                    // SZÖVEG-RÉSZ ELLENŐRZÉSEI (review N-a, r5 N5, r6 S4): az Excel UTF-8 XML-t ír.
                    //  - UTF-16 BOM vagy NUL bájt: formátum-hiba (az ASCII-alapú DTD-keresés UTF-16-ban vak lenne);
                    //  - az első nem-BOM / nem-szóköz bájt kötelezően `<` — ez zárja ki az EBCDIC-et és minden
                    //    nem ASCII-kompatibilis kódolást, amelyben a `<?xml` fej nem is ismerhető fel;
                    //  - a deklaráció kódolása csak UTF-8 (vagy hiányzik).
                    if ($isTextPart) {
                        if (str_contains($chunk, "\0")
                            || ($entry === strlen($chunk) && (str_starts_with($chunk, "\xFF\xFE") || str_starts_with($chunk, "\xFE\xFF")))) {
                            throw new ImportLimitExceeded('format');
                        }

                        if ($awaitingFirstByte) {
                            $head = $entry === strlen($chunk) && str_starts_with($chunk, "\xEF\xBB\xBF") ? substr($chunk, 3) : $chunk;
                            $head = ltrim($head, " \t\r\n");

                            if ($head !== '') {
                                $awaitingFirstByte = false;

                                if ($head[0] !== '<') {
                                    throw new ImportLimitExceeded('format');
                                }

                                // A deklaráció kódolása (a fej első 512 bájtjából). KÜLÖN változónév: a
                                // `$declared` a bejegyzés hirdetett mérete (a hazug fejléc elleni védelem) —
                                // a felülírása a méret-ellenőrzést kapcsolta ki (review r6 B1).
                                // A deklaráció TELJES egészében az első olvasási egységben (64 KB) kell
                                // álljon (review r7 S1: a korábbi 512 bájtos ablak mögé tolt `encoding="UTF-7"`
                                // átcsúszott). Ez mélységi védelem — a döntő a szerkezeti őr (`XlsxStructure`).
                                if (str_starts_with($head, '<?xml')) {
                                    $close = strpos($head, '?>');

                                    if ($close === false) {
                                        throw new ImportLimitExceeded('format');
                                    }

                                    if (preg_match('/\sencoding\s*=\s*["\']([^"\']*)["\']/i', substr($head, 0, $close), $encodingMatch) === 1
                                        && strtolower($encodingMatch[1]) !== 'utf-8') {
                                        throw new ImportLimitExceeded('format');
                                    }
                                }
                            }
                        }
                    }

                    // DTD / ENTITÁS-deklaráció (review N2, mélységi védelem): egy XLSX-részben soha nem
                    // legitim, és az XML-olvasók entitás-kezelésére épülő támadások (XXE, „billion
                    // laughs") belépési pontja. MINDEN bejegyzésben, kis-nagybetűtől függetlenül.
                    $scan = $declTail.$chunk;

                    if (stripos($scan, '<!DOCTYPE') !== false || stripos($scan, '<!ENTITY') !== false) {
                        throw new ImportLimitExceeded('format');
                    }

                    $declTail = substr($scan, -8);

                    if ($isSheet) {
                        // CSOMÓPONT-SZÁMLÁLÁS: a `<` jelek száma olcsó (másodperc törtrésze 200 MB-on),
                        // és közelíti, mennyit dolgozna az XML-olvasó. Plafon az egészre ÉS az első
                        // adatsor ELŐTTI részre: a `<row>` nélküli áradat itt bukik el.
                        $nodes += substr_count($chunk, '<');
                        $buffer = $carry.$chunk;
                        $rows += substr_count($buffer, '<row ') + substr_count($buffer, '<row>');
                        $carry = substr($buffer, -4);

                        // A mintát az első HAT sorból olvassa az openspout, és közben végigmegy a köztes
                        // csomópontokon: ha a hatodik sorig (vagy, ha kevesebb sor van, a fájl végéig)
                        // több csomópont van a plafonnál, a felmérés időkerete megkerülhető lenne.
                        if ($rows >= 6 && $sixthRowNodes === null) {
                            $sixthRowNodes = $nodes;
                        }

                        if (
                            $nodes > $maxNodes
                            || ($rows < 6 && $nodes > $beforeData)
                            || ($sixthRowNodes !== null && $sixthRowNodes > $beforeData)
                        ) {
                            throw new ImportLimitExceeded('zip');
                        }
                    }

                    if (! $isSheet) {
                        $nodes += substr_count($chunk, '<');

                        if ($nodes > $partCap || $entry > $byteCap) {
                            throw new ImportLimitExceeded('zip');
                        }
                    }

                    if ($checkRun) {
                        // Hosszú `<`-mentes szakasz: a lánc folytatása az előző chunkból + a chunk
                        // belsejében bárhol.
                        $first = strpos($chunk, '<');

                        if ($run + ($first === false ? strlen($chunk) : $first) > $maxRun
                            || preg_match('/[^<]{'.($maxRun + 1).',}/', $chunk) === 1) {
                            throw new ImportLimitExceeded('zip');
                        }

                        $last = strrpos($chunk, '<');
                        $run = $last === false ? $run + strlen($chunk) : strlen($chunk) - $last - 1;
                    }

                    $this->checkBudget();
                }
            } finally {
                fclose($stream);
            }

            if ($isSheet) {
                $stats[$stat['name']] = ['nodes' => $nodes, 'rows' => $rows];
            }
        }

        return $stats;
    }

    /**
     * A SZEREPEK feloldása és a hivatkozások ellenőrzése (review r7 B1, r8 B1/S1; D181, D195–D197).
     *
     *  - A `workbook.xml.rels` MINDEN hivatkozása (bármely mélységű / előtagú `Relationship`) mindkét
     *    openspout-feloldással (rész és munkalap) NEM mutathat bináris helyre — a `TargetMode`-tól
     *    függetlenül (az openspout a `TargetMode`-ot nem nézi). A belső cél létező bejegyzés kell legyen;
     *    csak az egyéb (nem típusos, nem munkalap) KÜLSŐ hivatkozásnál marad ki a létezés.
     *  - A sharedStrings / styles cél és minden munkalap cél a `TargetMode`-tól függetlenül létező, nem
     *    bináris bejegyzés.
     *  - A sharedStrings / styles nem lehet munkalap-cél, és nem élhet az `xl/worksheets/` alatt.
     *
     * A két fájl (`workbook.xml`, `.rels`) olvasása korlátos (1 MB), és a parser DTD-t, entitást eleve
     * elutasít (`XlsxStructure::walk`).
     *
     * @return array{shared: string|null, styles: string|null, sheets: list<string>}
     *
     * @throws ImportLimitExceeded
     */
    private function resolveRoles(ZipArchive $zip): array
    {
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels', 1_048_576);
        $workbookXml = $zip->getFromName('xl/workbook.xml', 1_048_576);

        if ($relsXml === false || $workbookXml === false) {
            throw new ImportLimitExceeded('format');
        }

        $relationships = XlsxStructure::relationshipsFrom($relsXml);
        $exists = static fn (string $entry): bool => $zip->locateName($entry) !== false;

        foreach ($relationships as $relationship) {
            // A `/`-re végződő cél (könyvtár-szerű név) formátum-hiba (review r9 S3): az openspout az ilyen
            // nevű bejegyzést is megnyitja, a szerkezeti szkennelés viszont könyvtárnak nézhetné.
            if (str_ends_with($relationship['target'], '/')) {
                throw new ImportLimitExceeded('format');
            }

            $asPart = XlsxStructure::partPathFor($relationship['target']);
            $asSheet = XlsxStructure::sheetPathFor($relationship['target']);

            // A bináris-tilalom MINDEN hivatkozásra áll, a külsőre is (review r8 B1).
            if (XlsxStructure::isBinaryPart($asPart) || XlsxStructure::isBinaryPart($asSheet)) {
                throw new ImportLimitExceeded('format');
            }

            if (! $relationship['external'] && ! $exists($asPart) && ! $exists($asSheet)) {
                throw new ImportLimitExceeded('format');
            }
        }

        $sheets = [];

        foreach (XlsxStructure::sheetsFrom($workbookXml) as $sheet) {
            $file = XlsxStructure::sheetTarget($relationships, $sheet['rid']);

            if ($file === null || XlsxStructure::isBinaryPart($file) || ! $exists($file)) {
                throw new ImportLimitExceeded('format');
            }

            $sheets[] = $file;
        }

        $typed = [];

        foreach (['shared' => XlsxStructure::SHARED_STRINGS_TYPES, 'styles' => XlsxStructure::STYLES_TYPES] as $role => $types) {
            $target = XlsxStructure::typedTarget($relationships, $types);
            $file = $target === null ? null : XlsxStructure::partPathFor($target);

            // A típusos cél a `TargetMode`-tól függetlenül: létező, nem bináris, nem munkalap, és nem az
            // `xl/worksheets/` alatt (különben a munkalap plafonjait és prológus-ellenőrzését kapná).
            if ($file !== null && (! $exists($file) || XlsxStructure::isBinaryPart($file)
                || in_array($file, $sheets, true) || str_starts_with(strtolower($file), 'xl/worksheets/'))) {
                throw new ImportLimitExceeded('format');
            }

            $typed[$role] = $file;
        }

        // A sharedStrings és a styles NEM lehet ugyanaz a bejegyzés (review r9 S1): a styles különben a
        // sharedStrings (nagyobb) plafonját kapná — mérve 37,7 MB átment, az openspout csúcs ~3 GB.
        if ($typed['shared'] !== null && $typed['shared'] === $typed['styles']) {
            throw new ImportLimitExceeded('format');
        }

        return ['shared' => $typed['shared'], 'styles' => $typed['styles'], 'sheets' => array_values(array_unique($sheets))];
    }

    /**
     * A SZERKEZETI ŐR (review r7 B1/S1, D179–D180): minden nem bináris bejegyzés átmegy a parseren
     * (`XlsxStructure::assertWellFormedPart`): DTD, entitás, jelölés vagy bármely olvasási hiba →
     * formátum-hiba. A MUNKALAP-szerepű bejegyzésnél (feloldott cél, review r8 S1) a prológus és a
     * gyökér, minden másnál a teljes tartalom.
     *
     * @param  array{shared: string|null, styles: string|null, sheets: list<string>}  $roles
     *
     * @throws ImportLimitExceeded
     */
    private function assertStructure(string $path, ZipArchive $zip, array $roles): void
    {
        $real = realpath($path);

        if ($real === false) {
            throw new ImportLimitExceeded('format');
        }

        $maxNodes = (int) config('audience.import.xlsx_max_part_nodes');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');

            // CSAK a 0 bájtos könyvtár-bejegyzés marad ki (review r9 S3): egy `/`-re végződő, tartalommal
            // bíró bejegyzést az openspout megnyitna — a szkennelésnek is látnia kell.
            if ($name === '' || (str_ends_with($name, '/') && (int) ($stat['size'] ?? 0) === 0)
                || XlsxStructure::isBinaryPart($name)) {
                continue;
            }

            $isSheet = in_array($name, $roles['sheets'], true);

            XlsxStructure::assertWellFormedPart(
                'zip://'.$real.'#'.$name,
                full: ! $isSheet,
                tick: fn () => $this->checkBudget(),
                maxNodes: $isSheet ? PHP_INT_MAX : $maxNodes,
            );
        }
    }

    /**
     * A munkalap fájlja a neve alapján — UGYANAZZAL a feloldással, mint az openspout (review r7 N1):
     * a `sheet` csomópontok (bármely előtag) `r:id`-ja, és a `workbook.xml.rels` `Relationship`-jei
     * (bármely mélység / előtag) az openspout ciklus-szemantikájával.
     */
    private function sheetFile(string $path, string $name): string
    {
        [$workbook, $rels] = $this->workbookParts($path);

        foreach (XlsxStructure::sheetsFrom($workbook) as $sheet) {
            if ($sheet['name'] === $name) {
                $file = XlsxStructure::sheetTarget(XlsxStructure::relationshipsFrom($rels), $sheet['rid']);

                if ($file !== null) {
                    return $file;
                }

                break;
            }
        }

        throw new ImportLimitExceeded('format');
    }

    /**
     * A `workbook.xml` és a `workbook.xml.rels` (korlátos olvasás).
     *
     * @return array{0: string, 1: string}
     */
    private function workbookParts(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportLimitExceeded('format');
        }

        try {
            $workbook = $zip->getFromName('xl/workbook.xml', 1_048_576);
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels', 1_048_576);
        } finally {
            $zip->close();
        }

        if ($workbook === false || $rels === false) {
            throw new ImportLimitExceeded('format');
        }

        return [$workbook, $rels];
    }

    /**
     * A munkalapok nevei a `workbook.xml`-ből (XMLReader, openspout-paritás, korlátos olvasás) — NEM az openspoutból:
     * az újabb XML-menetet indítana a (drága) sharedStrings fölött, és a költséget megduplázná.
     *
     * @return list<string>
     */
    private function sheetNames(string $path): array
    {
        $names = [];

        foreach ($this->workbookSheets($path) as $sheet) {
            $names[] = $sheet['name'];
        }

        return $names;
    }

    /** @return list<array{name: string, rid: string}> */
    private function workbookSheets(string $path): array
    {
        return XlsxStructure::sheetsFrom($this->workbookParts($path)[0]);
    }
}
