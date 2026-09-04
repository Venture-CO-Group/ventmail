<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

/**
 * ZIP-BOMBA felismerés (35. kritérium, golden rule 9).
 *
 * A zip-bomba egy néhány kilobájtos archívum, ami kicsomagolva gigabájtokra nő — a klasszikus
 * 42.zip 42 kB-ból 4,5 petabájt. Aki ilyet szkennelésre vagy előnézetre ad, az nem fájlt küld,
 * hanem a lemezünket és a memóriánkat célozza.
 *
 * A felismerés NEM csomagol ki: az archívum SAJÁT KÖNYVELÉSÉT olvassa (a ZIP központi
 * könyvtárában minden bejegyzés hordozza a tömörített és a kicsomagolt méretét). Kicsomagolni
 * épp az lenne, amit a támadó akar.
 *
 * KÉT korlát, mert egyik sem elég önmagában:
 *  - ARÁNY: a 42.zip aránya csillagászati, de egy 10 GB-ra bomló, 200 MB-os archívum aránya
 *    „csak" 50 — az mégis megfojtana minket;
 *  - ABSZOLÚT kicsomagolt méret: ez fogja meg az előbbit.
 */
final class ArchiveInspector
{
    /** Amit a bejegyzés-fejlécből ismerünk fel — a kiterjesztésre NEM hagyatkozunk. */
    private const ZIP_MAGIC = "PK\x03\x04";

    public function looksLikeArchive(string $contents): bool
    {
        return str_starts_with($contents, self::ZIP_MAGIC);
    }

    /**
     * Bomba-e?
     *
     * @return array{bomb: bool, reason: ?string, uncompressed: int}
     */
    public function inspect(string $contents): array
    {
        if (! $this->looksLikeArchive($contents)) {
            return ['bomb' => false, 'reason' => null, 'uncompressed' => 0];
        }

        $uncompressed = $this->declaredUncompressedSize($contents);
        $compressed = max(1, strlen($contents));

        $maxRatio = (int) config('inbound.attachments.max_compression_ratio');
        $maxUncompressed = (int) config('inbound.attachments.max_uncompressed_bytes');

        if ($uncompressed > $maxUncompressed) {
            return [
                'bomb' => true,
                'reason' => sprintf('A kicsomagolt méret (%d bájt) meghaladja a korlátot.', $uncompressed),
                'uncompressed' => $uncompressed,
            ];
        }

        if (intdiv($uncompressed, $compressed) > $maxRatio) {
            return [
                'bomb' => true,
                'reason' => sprintf('A tömörítési arány (%d:1) meghaladja a korlátot.', intdiv($uncompressed, $compressed)),
                'uncompressed' => $uncompressed,
            ];
        }

        return ['bomb' => false, 'reason' => null, 'uncompressed' => $uncompressed];
    }

    /**
     * A bejegyzések DEKLARÁLT kicsomagolt mérete, a helyi fejlécekből.
     *
     * A szám a támadótól jön, tehát HAZUDHAT — de csak lefelé érdemes neki: ha alábecsül, a
     * kicsomagolás nálunk úgyis a méret-limitbe ütközik, ha felülbecsül, elutasítjuk. Ezért a
     * deklarált méret önmagában elég a döntéshez, és nem kell kicsomagolni.
     */
    private function declaredUncompressedSize(string $contents): int
    {
        $total = 0;
        $offset = 0;
        $entries = 0;

        while (($position = strpos($contents, self::ZIP_MAGIC, $offset)) !== false) {
            // A helyi fejléc: 4 bájt aláírás + 22 bájt fejléc-mezők; a kicsomagolt méret a
            // 22. bájttól, 4 bájton, kis endian.
            $header = substr($contents, $position, 30);

            if (strlen($header) < 30) {
                break;
            }

            $fields = unpack('Vuncompressed', substr($header, 22, 4));

            $total += (int) ($fields['uncompressed'] ?? 0);
            $offset = $position + 4;

            // Egy archívum bejegyzés-SZÁMA is lehet a támadás (több millió üres fájl).
            if (++$entries > 10_000) {
                break;
            }
        }

        return $total;
    }
}
