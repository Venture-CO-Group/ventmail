<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use DateTimeImmutable;

/**
 * Az egyéni mező ÉRTÉKÉNEK típus-ellenőrzése és normalizálása.
 *
 * Az `attributes` JSON-t a szegmens-motor szűri (`ltv > 100`, `birthday < …`), tehát egy
 * szövegként beszúrt „sok" egy számmezőbe csendben elrontaná a szegmenst. Ezért a hibás
 * érték SORHIBA, nem csendes szövegként tárolás.
 *
 * Kimenet: a tárolandó érték, vagy `null` ha az érték nem fér a típusba (az üres érték
 * a hívónál már kiszűrt: üres nem kerül az attribútumok közé).
 */
final class AttributeCoercer
{
    /** @var list<string> */
    private const TRUE = ['1', 'true', 'yes', 'y', 'x', 'igen', 'i', 'ja', 'oui', 'si', 'sì', 'sí', 'да'];

    /** @var list<string> */
    private const FALSE = ['0', 'false', 'no', 'n', 'nem', 'nein', 'non', 'нет'];

    /** @var list<string> */
    private const DATE_FORMATS = ['Y-m-d', 'Y.m.d', 'Y/m/d', 'd.m.Y', 'd/m/Y', 'd-m-Y'];

    public function coerce(string $type, string $value): string|int|float|bool|null
    {
        return match ($type) {
            'number' => $this->number($value),
            'date' => $this->date($value),
            'boolean' => $this->boolean($value),
            default => $value,
        };
    }

    private function number(string $value): int|float|null
    {
        $clean = str_replace([' ', "\u{00A0}"], '', $value);

        if ($clean === '') {
            return null;
        }

        // KÉTÉRTELMŰ ezres-jelölés: az `1,234` lehet 1,234 (tizedes) és 1234 is — a találgatás
        // csendben rossz számot tárolna, ezért sorhiba.
        if (preg_match('/^[+-]?\d{1,3},\d{3}$/', $clean) === 1) {
            return null;
        }

        // „1,234.5" → ezres-vessző; „1234,5" → tizedesvessző.
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }

        if (! is_numeric($clean)) {
            return null;
        }

        $isInteger = preg_match('/^[+-]?\d+$/', $clean) === 1;

        if ($isInteger) {
            // Túlcsordulás: a PHP csendben lebegőpontosra vált (vagy PHP_INT_MAX-ra vág).
            // VEZETŐ NULLÁK: számmezőben `007` = 7 (következetesen, mint a `0012.5` = 12.5); az
            // azonosító-jellegű, nullával kezdődő érték SZÖVEG-mezőbe való, ott érintetlen marad.
            $sign = str_starts_with($clean, '-') ? '-' : '';
            $digits = ltrim(ltrim($clean, '+-'), '0');
            $digits = $digits === '' ? '0' : $digits;
            $int = (int) ($sign.$digits);

            return (string) $int === ($digits === '0' ? '0' : $sign.$digits) ? $int : null;
        }

        $float = (float) $clean;

        // `1e999` → INF: a json_encode kivételt dobna, és az egész import elhasalna.
        return is_finite($float) ? $float : null;
    }

    private function date(string $value): ?string
    {
        // „1988. 04. 12." → szóköz és záró pont nélkül.
        $clean = rtrim(str_replace(' ', '', trim($value)), '.');

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $clean);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function boolean(string $value): ?bool
    {
        $needle = mb_strtolower(trim($value));

        return match (true) {
            in_array($needle, self::TRUE, true) => true,
            in_array($needle, self::FALSE, true) => false,
            default => null,
        };
    }
}
