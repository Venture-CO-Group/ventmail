<?php

declare(strict_types=1);

use App\Modules\Analytics\Data\BrandColor;

/*
 | v1.1 · 8. modul (D236) — a brand-szín WCAG-döntése.
 */

it('a borítószöveg: fehér, ha olvasható; különben sötét; különben elutasítva', function () {
    expect(BrandColor::from('#0E1322')->coverText())->toBe('light')
        ->and(BrandColor::from('#1D4ED8')->coverText())->toBe('light')
        ->and(BrandColor::from('#FFD84D')->coverText())->toBe('dark')
        ->and(BrandColor::from('#777777')->coverText())->toBeNull()
        ->and(BrandColor::from('#777777')->isUsable())->toBeFalse();
});

it('a WCAG-arány a szabvány szerinti (fekete–fehér 21 : 1)', function () {
    expect(round(BrandColor::contrast('#000000', '#FFFFFF'), 2))->toBe(21.0)
        ->and(round(BrandColor::contrast('#FFFFFF', '#FFFFFF'), 2))->toBe(1.0);
});

it('sötét felületen gyenge szín csak figyelmeztetés', function () {
    expect(BrandColor::from('#1A1F3A')->weakOnDarkSurface())->toBeTrue()
        ->and(BrandColor::from('#FFD84D')->weakOnDarkSurface())->toBeFalse();
});

it('csak #RRGGBB alak', function (string $hex) {
    expect(BrandColor::isValid($hex))->toBeFalse();
})->with(['red', '#FFF', '#GGGGGG', '#0E1322;', 'url(x)', '#0E1322 ']);

it('a referenciaszínek egyeznek a @venture/design tokenekkel', function () {
    $tokens = base_path('node_modules/@venture/design/dist/tokens.css');

    if (! is_file($tokens)) {
        $this->markTestSkipped('A @venture/design csomag nincs telepítve (PHP-only CI job).');
    }

    $css = (string) file_get_contents($tokens);

    expect($css)->toContain('--vt-color-neutral-0: '.BrandColor::LIGHT_TEXT)
        ->toContain('--vt-color-neutral-900: '.BrandColor::DARK_TEXT)
        ->toContain('--vt-bg: '.BrandColor::DARK_SURFACE);
});
