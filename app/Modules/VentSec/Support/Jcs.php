<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Support;

use InvalidArgumentException;
use stdClass;

/**
 * JSON Canonicalization Scheme (RFC 8785) azon részhalmazra, amit a ventSec aláír: null, bool,
 * string, lista, objektum és az I-JSON tartományú (±(2^53−1)) egész. Bármi más — különösen a
 * lebegőpontos szám — kivétel, SOHA nem kerekítés: egy kerekített mező más bájtokat jelentene,
 * mint amit a ventSec aláírt.
 *
 * Vektorok: tests/Fixtures/ventsec/{jcs,scan-verdict}.json (a ventSec független generátorából).
 * Az objektumot `json_decode(…, false)` stdClass-ként adjuk át, hogy az üres `{}` és `[]` ne
 * keveredjen.
 */
final class Jcs
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    private const STRING_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function canonicalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => self::integer($value),
            is_string($value) => self::string($value),
            is_array($value) && array_is_list($value) => '['.implode(',', array_map(self::canonicalize(...), $value)).']',
            is_array($value) => self::object(self::members($value)),
            $value instanceof stdClass => self::object(self::members((array) $value)),
            default => throw new InvalidArgumentException('Nem kanonizálható JSON-érték: '.get_debug_type($value).'.'),
        };
    }

    private static function integer(int $value): string
    {
        if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException('Az egész az I-JSON tartományon kívül van.');
        }

        return (string) $value;
    }

    private static function string(string $value): string
    {
        try {
            return json_encode($value, self::STRING_FLAGS);
        } catch (\JsonException) {
            throw new InvalidArgumentException('A string nem érvényes UTF-8.');
        }
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return list<array{string, mixed}>
     */
    private static function members(array $value): array
    {
        $members = [];

        foreach ($value as $name => $member) {
            $members[] = [(string) $name, $member];
        }

        return $members;
    }

    /** @param list<array{string, mixed}> $members */
    private static function object(array $members): string
    {
        // Az objektum-tagok sorrendje az UTF-16 kódegységek szerinti (RFC 8785 3.2.3).
        foreach ($members as $i => [$name]) {
            if (! mb_check_encoding($name, 'UTF-8')) {
                throw new InvalidArgumentException('A tagnév nem érvényes UTF-8.');
            }

            $members[$i][] = (string) mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
        }

        usort($members, fn (array $a, array $b): int => strcmp($a[2], $b[2]));

        return '{'.implode(',', array_map(
            fn (array $m): string => self::string($m[0]).':'.self::canonicalize($m[1]),
            $members,
        )).'}';
    }
}
