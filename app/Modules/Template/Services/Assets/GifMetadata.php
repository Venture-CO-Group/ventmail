<?php

declare(strict_types=1);

namespace App\Modules\Template\Services\Assets;

use InvalidArgumentException;

/**
 * GIF METAADAT-SZŰRÉS újrakódolás nélkül (az animáció megmarad).
 *
 * A GIF blokk-szerkezetű: fejléc + logikai képernyő (+ globális paletta), utána
 * kiterjesztések (`0x21`) és képek (`0x2C`), végül a lezáró `0x3B`. Megtartjuk a képeket,
 * a grafikai vezérlő kiterjesztést (`0xF9`, időzítés/átlátszóság) és az animációs
 * ismétlést (`NETSCAPE2.0` / `ANIMEXTS1.0`). Kiesik a komment (`0xFE`), a szöveges
 * (`0x01`) és minden más alkalmazás-kiterjesztés (pl. `XMP DataXMP`).
 *
 * A hibás szerkezet ELUTASÍTÁS (kivétel) — egy félig értelmezett fájlt nem teszünk publikussá.
 */
final class GifMetadata
{
    private const KEEP_APPLICATIONS = ['NETSCAPE2.0', 'ANIMEXTS1.0'];

    public static function strip(string $gif): string
    {
        $length = strlen($gif);

        if ($length < 13 || ! in_array(substr($gif, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            throw new InvalidArgumentException('Nem GIF.');
        }

        $flags = ord($gif[10]);
        $offset = 13 + (($flags & 0x80) ? 3 * (2 ** (($flags & 0x07) + 1)) : 0);
        $out = substr($gif, 0, $offset);

        while ($offset < $length) {
            $byte = ord($gif[$offset]);

            if ($byte === 0x3B) {
                return $out."\x3B";
            }

            if ($byte === 0x2C) {
                $start = $offset;
                self::need($gif, $offset + 10);
                $packed = ord($gif[$offset + 9]);
                $offset += 10 + (($packed & 0x80) ? 3 * (2 ** (($packed & 0x07) + 1)) : 0);
                self::need($gif, $offset + 1);
                $offset = self::skipSubBlocks($gif, $offset + 1);
                $out .= substr($gif, $start, $offset - $start);

                continue;
            }

            if ($byte === 0x21) {
                self::need($gif, $offset + 2);
                $start = $offset;
                $label = ord($gif[$offset + 1]);
                $end = self::skipSubBlocks($gif, $offset + 2);
                $keep = $label === 0xF9
                    || ($label === 0xFF && in_array(substr($gif, $offset + 3, 11), self::KEEP_APPLICATIONS, true));

                if ($keep) {
                    $out .= substr($gif, $start, $end - $start);
                }

                $offset = $end;

                continue;
            }

            throw new InvalidArgumentException('Ismeretlen GIF-blokk.');
        }

        throw new InvalidArgumentException('Hiányzó GIF-lezáró.');
    }

    /** A sub-blokk lánc vége (a `0x00` lezáró utáni pozíció). */
    private static function skipSubBlocks(string $gif, int $offset): int
    {
        while (true) {
            self::need($gif, $offset + 1);
            $size = ord($gif[$offset]);
            $offset += 1 + $size;

            if ($size === 0) {
                return $offset;
            }
        }
    }

    private static function need(string $gif, int $offset): void
    {
        if ($offset > strlen($gif)) {
            throw new InvalidArgumentException('Csonka GIF.');
        }
    }
}
