<?php

declare(strict_types=1);

namespace App\Modules\Template\Data;

/**
 * A SABLON GLOBÁLIS STÍLUSA (v1.1 · Stílusok fül): betű, színek, gomb, háttér, szélesség.
 *
 * ## Miért zárt kulcs-készlet, és miért nem szabad CSS
 *
 * A stílus a LEVÉLBE kerül, a mi DKIM-ünkkel aláírva, és a webes előnézetben a mi
 * originünkön jelenik meg. Egy szabad CSS-mező (`font-family: x; background:url(…)`) ugyanaz
 * a felület, amit a `HtmlSanitizer` a `style` attribútumon lezár. Ezért itt minden érték
 * TÍPUSOS: szín = hex, betű = ismert kulcs (a családlista a SZERVEREN van), szám = tartomány.
 *
 * ## A `null` stílus a régi kimenet
 *
 * A `templates.styles` nullable: a v1.1 előtti sablonok (és a golden fixture) bitre azonos
 * HTML-t kapnak. A fordító csak akkor ír stílus-jelölőt, ha van mit.
 */
final class TemplateStyles
{
    /**
     * A betűkészletek: a levélkliensekben megbízhatóan elérhető (web-safe) családok.
     * Webfontot szándékosan nem kínálunk: az Outlook és a Gmail nagy része eldobja.
     */
    public const FONTS = [
        'sans' => 'Helvetica, Arial, sans-serif',
        'humanist' => 'Verdana, Geneva, sans-serif',
        'rounded' => "'Trebuchet MS', Tahoma, sans-serif",
        'serif' => 'Georgia, Times, serif',
        'mono' => "'Courier New', Courier, monospace",
    ];

    /** Az alapértékek — a szerkesztő ezeket mutatja, ha a sablonnak nincs saját stílusa. */
    public const DEFAULTS = [
        'font' => 'sans',
        'text_color' => '#1f2933',
        'heading_color' => '#1f2933',
        'link_color' => '#1f6feb',
        'background' => '#f5f7fa',
        'content_background' => '#ffffff',
        'button_background' => '#1f6feb',
        'button_color' => '#ffffff',
        'button_radius' => 6,
        'font_size' => 16,
        'width' => 600,
    ];

    /** @var array<string, array{int, int}> */
    public const RANGES = [
        'button_radius' => [0, 40],
        'font_size' => [12, 22],
        'width' => [480, 800],
    ];

    /**
     * Az érvényesség — a hibák listája (üres, ha rendben).
     *
     * @return list<string>
     */
    public static function errorsIn(mixed $styles): array
    {
        if ($styles === null) {
            return [];
        }

        if (! is_array($styles)) {
            return ['styles: objektum kell legyen.'];
        }

        $errors = [];

        foreach ($styles as $key => $value) {
            $key = (string) $key;

            if (! array_key_exists($key, self::DEFAULTS)) {
                $errors[] = sprintf('styles: ismeretlen mező: `%s`.', $key);

                continue;
            }

            if ($key === 'font') {
                if (! is_string($value) || ! array_key_exists($value, self::FONTS)) {
                    $errors[] = sprintf('styles.font: %s.', implode(', ', array_keys(self::FONTS)));
                }

                continue;
            }

            if (isset(self::RANGES[$key])) {
                [$min, $max] = self::RANGES[$key];

                if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
                    $errors[] = sprintf('styles.%s: %d és %d között lehet.', $key, $min, $max);
                }

                continue;
            }

            if (! is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                $errors[] = sprintf('styles.%s: hat jegyű hex szín kell legyen (#rrggbb).', $key);
            }
        }

        return $errors;
    }

    /**
     * A teljes (alapértékekkel kiegészített) stílus.
     *
     * @param  array<string, mixed>|null  $styles
     * @return array<string, mixed>
     */
    public static function resolve(?array $styles): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($styles ?? [], self::DEFAULTS));
    }

    public static function fontStack(string $key): string
    {
        return self::FONTS[$key] ?? self::FONTS['sans'];
    }
}
