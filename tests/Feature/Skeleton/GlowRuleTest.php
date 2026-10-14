<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | GLOW-SZABÁLY (@venture/ui 0.3.0, UI-polírozás · 2. pont, D314).
 |
 | Egy kártyán a glow-line VAGY körbefut a teljes kártyán, VAGY nincs. Részleges (egyélű)
 | kártya-glow TILOS. A kártya ringjét a `VtCard` / `VmCard` EGYETLEN `glow` boolean propja adja.
 | A kártyán BELÜL tartalomként futó glow-vonal (folyamatjelző sáv, delivery rail,
 | `VtGlowLine` / `VtSentinelLine`) nem kártya-keret — azt a szabály nem érinti.
 */

/** @return list<array{file: string, selector: string, body: string}> */
function glowCssRules(): array
{
    $files = collect(File::allFiles(resource_path('js')))
        ->merge(File::allFiles(resource_path('css')))
        ->filter(fn ($file) => in_array($file->getExtension(), ['vue', 'css'], true));

    $rules = [];

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        // Vue-ban csak a <style> blokkok.
        if ($file->getExtension() === 'vue') {
            preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $contents, $styles);
            $contents = implode("\n", $styles[1]);
        }

        $contents = (string) preg_replace('/\/\*.*?\*\//s', '', $contents);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $rules[] = ['file' => $file->getRelativePathname(), 'selector' => trim($match[1]), 'body' => $match[2]];
        }
    }

    return $rules;
}

it('nincs egyélű (részleges) kártya-glow a ventMail stílusaiban', function () {
    $offenders = [];

    foreach (glowCssRules() as $rule) {
        $glow = preg_match('/glow/i', $rule['body']) === 1;
        $card = preg_match('/card|panel|vt-glass|vm-glow/i', $rule['selector']) === 1;

        if (! $glow || ! $card) {
            continue;
        }

        $singleBorder = preg_match('/border-(top|bottom|left|right|block-start|block-end|inline-start|inline-end)\s*:/', $rule['body']) === 1;
        // Egy él menti vonal: abszolút pozíció egyetlen élre (pl. `inset: auto 0 0 0` vagy `top: 0` + vonal-magasság).
        $edgeLine = preg_match('/inset\s*:\s*[^;]*\bauto\b/', $rule['body']) === 1
            || (preg_match('/(^|;)\s*(top|bottom|inset-block-start|inset-block-end)\s*:\s*0/', $rule['body']) === 1
                && preg_match('/(height|block-size)\s*:/', $rule['body']) === 1);

        if ($singleBorder || $edgeLine) {
            $offenders[] = "{$rule['file']}: {$rule['selector']}";
        }
    }

    expect($offenders)->toBe([]);
});

it('a részleges glow-utility-k kivezetve, a kártya glow-ja egyetlen boolean prop', function () {
    $sources = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($file) => in_array($file->getExtension(), ['vue', 'css', 'ts'], true))
        ->map(fn ($file) => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    expect($sources)->not->toMatch('/\.vm-glow-hairline|\.vm-glow-indicator|class="[^"]*vm-glow-(hairline|indicator)/')
        // Él-specifikus glow-prop nincs (glow-top, glowEdge, glow-edge, …).
        ->not->toMatch('/\b(glow-top|glow-bottom|glow-edge|glowEdge|glowTop)\b/');

    $card = (string) file_get_contents(resource_path('js/Components/Form/VmCard.vue'));

    expect($card)->toContain('glow?: boolean')->toContain(':glow="glow"');
});
