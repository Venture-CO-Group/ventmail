<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

/**
 * ZIP-BOMBA és társai felismerése (35. kritérium, golden rule 9).
 *
 * A zip-bomba egy néhány kilobájtos archívum, ami kicsomagolva gigabájtokra nő — a klasszikus
 * 42.zip 42 kB-ból 4,5 petabájt. Aki ilyet szkennelésre vagy előnézetre ad, az nem fájlt
 * küld, hanem a lemezünket és a memóriánkat célozza.
 *
 * A felismerés NEM csomagol ki: az archívum SAJÁT KÖNYVELÉSÉT olvassa. Kicsomagolni épp az
 * lenne, amit a támadó akar.
 *
 * A KÖZPONTI KÖNYVTÁRAT olvassuk, nem a helyi fejléceket — és ez a különbség mérve is
 * lényeges volt. A streaming zip-írók (minden „zip on the fly" szerver, a Java
 * `ZipOutputStream`, a legtöbb nyelvi standard könyvtár) a helyi fejlécbe NULLÁT írnak, és a
 * valós méretet a data descriptorba meg a központi könyvtárba teszik. A helyi fejlécekből
 * dolgozó ellenőrzés ezekre `deklarált = 0`-t lát — mérve: egy 48 kB-os, 50 MB-ra bomló
 * archívum „rendben"-t kapott. A kicsomagolók viszont MIND a központi könyvtárból dolgoznak.
 *
 * Amit ezen felül kezelni kell:
 *  - ZIP64: a 32 bites mezőben `0xFFFFFFFF` áll, a valós méret az extra fieldben;
 *  - PREFIX: az önkicsomagoló (SFX) archívum elején stub van, tehát a fájl NEM `PK`-val
 *    kezdődik — a `str_starts_with` alapú felismerés egyetlen bájttal kikapcsolható;
 *  - BEÁGYAZOTT archívum: a bomba egy ártatlan zipben ül;
 *  - MÁS FORMÁTUMOK: a gzip, bzip2 és xz ugyanígy bombázható, és a mi ellenőrzésünk
 *    nélkül egyenesen a vírusirtóba öntenénk őket.
 */
final class ArchiveInspector
{
    /** A központi könyvtár záró rekordja (EOCD). */
    private const EOCD = "PK\x05\x06";

    private const EOCD64_LOCATOR = "PK\x06\x07";

    private const CENTRAL_HEADER = "PK\x01\x02";

    /** @var array<string, string> magic → formátum */
    private const MAGIC = [
        "PK\x03\x04" => 'zip',
        "PK\x05\x06" => 'zip',   // üres archívum
        "\x1f\x8b" => 'gzip',
        'BZh' => 'bzip2',
        "\xfd7zXZ\x00" => 'xz',
        "7z\xbc\xaf\x27\x1c" => '7z',
        'Rar!' => 'rar',
    ];

    /** Tömörített formátum-e egyáltalán? A KITERJESZTÉSRE nem hagyatkozunk. */
    public function looksLikeArchive(string $contents): bool
    {
        return $this->format($contents) !== null;
    }

    public function format(string $contents): ?string
    {
        foreach (self::MAGIC as $magic => $format) {
            if (str_starts_with($contents, $magic)) {
                return $format;
            }
        }

        // SFX / prefixelt archívum: a `PK` nem az elején van. A központi könyvtár záró
        // rekordja viszont a fájl VÉGÉN — azt keressük.
        if (strpos(substr($contents, -66_000), self::EOCD) !== false) {
            return 'zip';
        }

        return null;
    }

    /**
     * Bomba-e?
     *
     * @return array{bomb: bool, reason: ?string, uncompressed: int}
     */
    public function inspect(string $contents, int $depth = 0): array
    {
        $format = $this->format($contents);

        if ($format === null) {
            return ['bomb' => false, 'reason' => null, 'uncompressed' => 0];
        }

        if ($format === 'gzip') {
            // A gzip az utolsó négy bájtban tárolja a kicsomagolt méretet (ISIZE, modulo
            // 2^32). Ez alsó becslés — de egy bomba aránya olyan nagy, hogy már ebből is
            // kiderül.
            return $this->verdict($this->gzipSize($contents), strlen($contents));
        }

        if ($format !== 'zip') {
            // A bzip2/xz/7z/rar nem tárol kicsomagolt méretet, tehát kicsomagolás nélkül nem
            // eldönthető — és kicsomagolni épp az lenne, amit a támadó akar.
            //
            // NEM utasítjuk el: ezeket a formátumokat a VÍRUSIRTÓ épp ilyen bemenetre
            // tervezték (`MaxScanSize`, `MaxRecursion`), és a mi szkennerünk fail-closed —
            // amit a clamd nem tud átvizsgálni, arra `unavailable` jön, és a csatolmány nem
            // tölthető le. A tiltás itt csak annyit érne el, hogy a legitim `.bz2` melléklet
            // se működjön.
            return ['bomb' => false, 'reason' => null, 'uncompressed' => 0];
        }

        $entries = $this->centralDirectory($contents);

        if ($entries === null) {
            return [
                'bomb' => true,
                'reason' => 'Az archívum központi könyvtára nem olvasható.',
                'uncompressed' => 0,
            ];
        }

        $uncompressed = 0;

        foreach ($entries as $entry) {
            $uncompressed += $entry['uncompressed'];
        }

        $verdict = $this->verdict($uncompressed, strlen($contents));

        if ($verdict['bomb']) {
            return $verdict;
        }

        // BEÁGYAZOTT archívum: a bomba egy ártatlan zipben ül. A rekurzió mélységi korláttal
        // megy — különben maga a vizsgálat lenne a DoS.
        if ($depth < 3) {
            foreach ($entries as $entry) {
                if (! $this->archiveName($entry['name'])) {
                    continue;
                }

                // A belső bejegyzést a helyi fejléc alapján vágjuk ki. Csak a TÁROLT
                // (tömörítetlen) belsőt tudjuk így megnézni — a tömörítettet nem
                // csomagoljuk ki, hanem az arány alapján már fentebb elbuktattuk volna.
                $inner = $this->storedEntry($contents, $entry);

                if ($inner === null) {
                    continue;
                }

                $nested = $this->inspect($inner, $depth + 1);

                if ($nested['bomb']) {
                    return [
                        'bomb' => true,
                        'reason' => 'Beágyazott archívum: '.$nested['reason'],
                        'uncompressed' => $nested['uncompressed'],
                    ];
                }
            }
        }

        return ['bomb' => false, 'reason' => null, 'uncompressed' => $uncompressed];
    }

    /**
     * A két korlát KÖZÖS kiértékelése.
     *
     * Egyik sem elég önmagában: a 42.zip aránya csillagászati, de egy 10 GB-ra bomló,
     * 200 MB-os archívum aránya „csak" 50 — az mégis megfojtana minket.
     *
     * @return array{bomb: bool, reason: ?string, uncompressed: int}
     */
    private function verdict(int $uncompressed, int $compressed): array
    {
        $compressed = max(1, $compressed);

        $maxRatio = (int) config('inbound.attachments.max_compression_ratio');
        $maxUncompressed = (int) config('inbound.attachments.max_uncompressed_bytes');

        if ($uncompressed > $maxUncompressed) {
            return [
                'bomb' => true,
                'reason' => sprintf('A kicsomagolt méret (%d bájt) meghaladja a korlátot.', $uncompressed),
                'uncompressed' => $uncompressed,
            ];
        }

        if ($uncompressed / $compressed > $maxRatio) {
            return [
                'bomb' => true,
                'reason' => sprintf('A tömörítési arány (%.0f:1) meghaladja a korlátot.', $uncompressed / $compressed),
                'uncompressed' => $uncompressed,
            ];
        }

        return ['bomb' => false, 'reason' => null, 'uncompressed' => $uncompressed];
    }

    /** A gzip ISIZE mezője — az utolsó négy bájt, kis endian, modulo 2^32. */
    private function gzipSize(string $contents): int
    {
        if (strlen($contents) < 4) {
            return 0;
        }

        $fields = unpack('Vsize', substr($contents, -4));

        return $fields === false ? 0 : (int) $fields['size'];
    }

    /**
     * A KÖZPONTI KÖNYVTÁR bejegyzései.
     *
     * @return list<array{name: string, uncompressed: int, compressed: int, offset: int}>|null
     */
    private function centralDirectory(string $contents): ?array
    {
        $eocd = strrpos($contents, self::EOCD);

        if ($eocd === false || strlen($contents) < $eocd + 22) {
            return null;
        }

        $header = unpack('vdisk/vstartDisk/ventries/vtotal/Vsize/Voffset', substr($contents, $eocd + 4, 18));

        if ($header === false) {
            return null;
        }

        $offset = (int) $header['offset'];
        $total = (int) $header['total'];

        // ZIP64: a 32 bites mezők telítettek, a valós értékek a ZIP64 EOCD-ben vannak.
        if ($offset === 0xFFFFFFFF || $total === 0xFFFF) {
            [$offset, $total] = $this->zip64Eocd($contents) ?? [$offset, $total];
        }

        // PREFIXELT (SFX) archívum: az EOCD offsetje az ARCHÍVUM elejéhez képest értendő, a
        // fájl elején viszont stub van. Ha az offset nem központi fejlécre mutat, megkeressük
        // az elsőt — enélkül egy önkicsomagolóba tett bomba „olvashatatlan"-ként csúszna át.
        if ($offset < 0 || $offset >= strlen($contents) || substr($contents, $offset, 4) !== self::CENTRAL_HEADER) {
            $found = strpos($contents, self::CENTRAL_HEADER);

            if ($found === false) {
                return null;
            }

            $offset = $found;
        }

        $entries = [];
        $cursor = $offset;

        // A bejegyzés-SZÁM is lehet a támadás (több millió üres fájl).
        for ($i = 0; $i < min($total, 20_000); $i++) {
            if (substr($contents, $cursor, 4) !== self::CENTRAL_HEADER) {
                break;
            }

            $fields = unpack(
                'vversion/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength/vcommentLength',
                substr($contents, $cursor + 4, 30),
            );

            if ($fields === false) {
                break;
            }

            $nameLength = (int) $fields['nameLength'];
            $extraLength = (int) $fields['extraLength'];

            $name = substr($contents, $cursor + 46, $nameLength);
            $extra = substr($contents, $cursor + 46 + $nameLength, $extraLength);

            $uncompressed = (int) $fields['uncompressed'];
            $compressed = (int) $fields['compressed'];
            $localOffset = (int) unpack('Voffset', substr($contents, $cursor + 42, 4))['offset'];

            if ($uncompressed === 0xFFFFFFFF || $compressed === 0xFFFFFFFF || $localOffset === 0xFFFFFFFF) {
                [$uncompressed, $compressed, $localOffset] = $this->zip64Extra(
                    $extra,
                    $uncompressed,
                    $compressed,
                    $localOffset,
                );
            }

            $entries[] = [
                'name' => $name,
                'uncompressed' => $uncompressed,
                'compressed' => $compressed,
                'offset' => $localOffset,
            ];

            $cursor += 46 + $nameLength + $extraLength + (int) $fields['commentLength'];
        }

        return $entries === [] ? null : $entries;
    }

    /** @return array{0: int, 1: int}|null offset + bejegyzés-szám */
    private function zip64Eocd(string $contents): ?array
    {
        $locator = strrpos($contents, self::EOCD64_LOCATOR);

        if ($locator === false) {
            return null;
        }

        $fields = unpack('Vdisk/Poffset/Vdisks', substr($contents, $locator + 4, 16));

        if ($fields === false) {
            return null;
        }

        $eocd64 = (int) $fields['offset'];

        if ($eocd64 < 0 || $eocd64 + 56 > strlen($contents)) {
            return null;
        }

        $record = unpack('Psize/vversion/vneeded/Vdisk/VstartDisk/Pentries/Ptotal/Pcdsize/Pcdoffset', substr($contents, $eocd64 + 4, 52));

        return $record === false ? null : [(int) $record['cdoffset'], (int) $record['total']];
    }

    /**
     * A ZIP64 extra field (0x0001) valós méretei.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function zip64Extra(string $extra, int $uncompressed, int $compressed, int $offset): array
    {
        $cursor = 0;

        while ($cursor + 4 <= strlen($extra)) {
            $header = unpack('vid/vsize', substr($extra, $cursor, 4));

            if ($header === false) {
                break;
            }

            $size = (int) $header['size'];

            if ((int) $header['id'] === 0x0001) {
                $payload = substr($extra, $cursor + 4, $size);
                $values = [];

                for ($i = 0; $i + 8 <= strlen($payload); $i += 8) {
                    $value = unpack('Pvalue', substr($payload, $i, 8));
                    $values[] = $value === false ? 0 : (int) $value['value'];
                }

                // A sorrend: kicsomagolt, tömörített, helyi offset — de CSAK azok, amelyek
                // a 32 bites mezőben telítettek voltak.
                $index = 0;

                if ($uncompressed === 0xFFFFFFFF) {
                    $uncompressed = $values[$index++] ?? $uncompressed;
                }

                if ($compressed === 0xFFFFFFFF) {
                    $compressed = $values[$index++] ?? $compressed;
                }

                if ($offset === 0xFFFFFFFF) {
                    $offset = $values[$index] ?? $offset;
                }

                break;
            }

            $cursor += 4 + $size;
        }

        return [$uncompressed, $compressed, $offset];
    }

    /** @param array{name: string, uncompressed: int, compressed: int, offset: int} $entry */
    private function storedEntry(string $contents, array $entry): ?string
    {
        $offset = $entry['offset'];

        if ($offset < 0 || substr($contents, $offset, 4) !== "PK\x03\x04") {
            return null;
        }

        $local = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength', substr($contents, $offset + 4, 26));

        if ($local === false || (int) $local['method'] !== 0) {
            // Nem tárolt (tömörített) bejegyzés: kicsomagolni épp az lenne, amit a támadó
            // akar. Az ilyen belsőt az ARÁNY fogja meg a külső szinten.
            return null;
        }

        $start = $offset + 30 + (int) $local['nameLength'] + (int) $local['extraLength'];

        return substr($contents, $start, $entry['compressed']) ?: null;
    }

    private function archiveName(string $name): bool
    {
        return preg_match('/\.(zip|jar|docx|xlsx|pptx|odt|ods|apk|gz|bz2|xz|7z|rar)$/i', $name) === 1;
    }
}
