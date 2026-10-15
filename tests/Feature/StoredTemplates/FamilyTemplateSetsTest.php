<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Services\TemplateRenderer;

/*
 | A család alkalmazásainak tárolt sablonkészletei (`resources/mail-templates/<set>/`) a
 | manifesztjeik ellen: `docs/integration/manifests/<set>.json` (a másolatok tulajdonosa az
 | adott app-repó `docs/integration/ventmail-templates.json`-ja; itt csak a teszt
 | önállósága miatt él másolat). DB nélkül fut.
 */

const FAMILY_SET_DIRS = ['ventsso' => 'ventsso', 'ventsec' => 'ventsec', 'ventcookie' => 'ventcookie', 'vemoa' => 'vemoa'];

/** @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> */
function familyTemplateCases(): array
{
    $cases = [];

    foreach (FAMILY_SET_DIRS as $set => $dir) {
        $manifestPath = dirname(__DIR__, 3)."/docs/integration/manifests/{$set}.json";

        if (! is_file($manifestPath)) {
            continue;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        foreach ($manifest['templates'] as $template) {
            foreach ($manifest['locales'] as $locale) {
                $name = "{$manifest['prefix']}-{$template['key']}-{$locale}";
                $cases["{$set}/{$name}"] = [$dir, $name, $locale, $template];
            }
        }
    }

    return $cases;
}

/** @return list<string> */
function familyPlaceholders(string $body): array
{
    preg_match_all('/\{\{\s*([A-Za-z0-9_.-]+)\s*(?:\|\|[^}]*?)?\s*\}\}/', $body, $matches);

    return array_values(array_unique($matches[1]));
}

function familyFile(string $dir, string $name, string $ext): string
{
    $path = resource_path("mail-templates/{$dir}/{$name}.{$ext}");
    expect(is_file($path))->toBeTrue("hiányzik: {$dir}/{$name}.{$ext}");

    return (string) file_get_contents($path);
}

it('has every manifest set present', function (): void {
    expect(familyTemplateCases())->not->toBeEmpty();
});

it('ships html, txt and subject for every manifest key and locale', function (string $dir, string $name): void {
    foreach (['html', 'txt', 'subject'] as $ext) {
        expect(trim(familyFile($dir, $name, $ext)))->not->toBe('');
    }
})->with(familyTemplateCases());

it('uses only manifest variables, with no malformed placeholders', function (string $dir, string $name, string $locale, array $template): void {
    $allowed = array_keys($template['variables']);

    foreach (['html', 'txt', 'subject'] as $ext) {
        $body = familyFile($dir, $name, $ext);

        expect(substr_count($body, '{{'))->toBe(preg_match_all('/\{\{\s*[A-Za-z0-9_.-]+\s*(?:\|\|[^}]*?)?\s*\}\}/', $body), "{$name}.{$ext}: hibás helyőrző");
        expect(substr_count($body, '%recipient'))->toBe(0);

        foreach (familyPlaceholders($body) as $var) {
            expect(in_array($var, $allowed, true))->toBeTrue("{$name}.{$ext}: ismeretlen változó {$var}");
        }
    }
})->with(familyTemplateCases());

it('keeps the html and txt twins on the same variables', function (string $dir, string $name): void {
    $html = familyPlaceholders(familyFile($dir, $name, 'html'));
    $txt = familyPlaceholders(familyFile($dir, $name, 'txt'));
    sort($html);
    sort($txt);

    expect($html)->toBe($txt);
})->with(familyTemplateCases());

it('carries every required link in both html and txt', function (string $dir, string $name, string $locale, array $template): void {
    foreach ($template['required_links'] as $var) {
        expect(familyFile($dir, $name, 'html'))->toContain('href="{{ '.$var.' }}"');
        expect(familyPlaceholders(familyFile($dir, $name, 'txt')))->toContain($var);
    }
})->with(familyTemplateCases());

it('uses the manifest subject as a single line', function (string $dir, string $name, string $locale, array $template): void {
    $subject = familyFile($dir, $name, 'subject');

    expect($subject)->toBe($template['subject'][$locale]."\n");
    expect(substr_count(rtrim($subject, "\n"), "\n"))->toBe(0);
})->with(familyTemplateCases());

it('keeps the html email-safe', function (string $dir, string $name, string $locale): void {
    $html = familyFile($dir, $name, 'html');

    expect($html)->toContain('<html lang="'.$locale.'"');
    expect($html)->toContain('max-width:600px');
    expect($html)->toContain('role="presentation"');
    expect(preg_match('/<script|<link\b|<iframe|@import|url\(|<form|javascript:/i', $html))->toBe(0);
    expect(preg_match('/(?:src|href)="http/i', $html))->toBe(0, 'külső erőforrás vagy beégetett link');
    expect(preg_match('/<img\b(?![^>]*\balt=)/i', $html))->toBe(0, '<img> alt nélkül');
    expect($html)->toContain('display:none;max-height:0');
})->with(familyTemplateCases());

it('renders without leftover placeholders even when optional variables are missing', function (string $dir, string $name, string $locale, array $template): void {
    $renderer = new TemplateRenderer;
    $required = [];

    foreach ($template['variables'] as $var => $_) {
        $required[$var] = str_ends_with($var, '_url') || in_array($var, $template['required_links'], true) ? 'https://example.test/x?a=1&b=2' : "<b>{$var}</b>";
    }

    foreach (['html' => true, 'txt' => false] as $ext => $escape) {
        $rendered = $renderer->render(familyFile($dir, $name, $ext), $required, $escape);

        expect($rendered)->not->toContain('{{');

        if ($escape) {
            expect($rendered)->not->toContain('<b>');
        }
    }

    // Hiányzó (nem kötelező) változókkal sem maradhat nyers helyőrző vagy üres link.
    $minimal = array_intersect_key($required, array_flip($template['required_links']));
    $partial = $renderer->render(familyFile($dir, $name, 'html'), $minimal);

    expect($partial)->not->toContain('{{');
    expect(preg_match('/href=""|background:;|color:;/', $partial))->toBe(0);
})->with(familyTemplateCases());
