<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use InvalidArgumentException;

/**
 * A WORKSPACE ELSŐDLEGES SZÍNE (v1.1 · 8. modul, D236) — WCAG-kontraszt alapján dönt.
 *
 * (a) A riport-borító SZÖVEGE a brand-sávon: fehér (`--vt-color-neutral-0`), ha a kontraszt
 *     ≥ 4,5 : 1; különben sötét (`--vt-color-neutral-900`), ha az ≥ 4,5 : 1; különben a szín
 *     ELUTASUL (olvashatatlan borító nem menthető).
 * (b) Sötét app-háttéren (`--vt-bg` sötét téma) ≥ 3 : 1 — csak figyelmeztetés (a szín a felület
 *     előnézetében kevésbé látszik, a nyomtatott riportot nem érinti).
 *
 * A három referenciaszín a `@venture/design` tokenek ÉRTÉKE (a kontraszt-számításhoz kell a
 * szám; a riport és a felület maga a tokent használja). A `BrandColorTest` ellenőrzi, hogy az
 * itteni érték a token-fájllal egyezik, ha a csomag elérhető.
 */
final class BrandColor
{
    public const PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    /** `--vt-color-neutral-0` */
    public const LIGHT_TEXT = '#FFFFFF';

    /** `--vt-color-neutral-900` */
    public const DARK_TEXT = '#0E1322';

    /** `--vt-bg` a sötét témában */
    public const DARK_SURFACE = '#080B16';

    public const MIN_TEXT_CONTRAST = 4.5;

    public const MIN_SURFACE_CONTRAST = 3.0;

    private function __construct(public readonly string $hex) {}

    public static function isValid(?string $hex): bool
    {
        return is_string($hex) && preg_match(self::PATTERN, $hex) === 1;
    }

    public static function from(string $hex): self
    {
        if (! self::isValid($hex)) {
            throw new InvalidArgumentException('Érvénytelen szín.');
        }

        return new self(strtoupper($hex));
    }

    /** A borító szövegének színe: `light` | `dark` | `null` (= elutasítandó). */
    public function coverText(): ?string
    {
        if (self::contrast($this->hex, self::LIGHT_TEXT) >= self::MIN_TEXT_CONTRAST) {
            return 'light';
        }

        if (self::contrast($this->hex, self::DARK_TEXT) >= self::MIN_TEXT_CONTRAST) {
            return 'dark';
        }

        return null;
    }

    public function isUsable(): bool
    {
        return $this->coverText() !== null;
    }

    /** Figyelmeztetés: a sötét felületen gyengén látszik (< 3 : 1). */
    public function weakOnDarkSurface(): bool
    {
        return self::contrast($this->hex, self::DARK_SURFACE) < self::MIN_SURFACE_CONTRAST;
    }

    public function coverContrast(): float
    {
        return round(max(self::contrast($this->hex, self::LIGHT_TEXT), self::contrast($this->hex, self::DARK_TEXT)), 2);
    }

    /** WCAG 2.x kontrasztarány két `#RRGGBB` szín között. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(
            static function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
