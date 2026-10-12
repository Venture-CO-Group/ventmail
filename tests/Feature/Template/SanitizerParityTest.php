<?php

declare(strict_types=1);

use App\Modules\Template\Services\HtmlSanitizer;

/*
 | A KLIENS (`sanitize.ts`) és a SZERVER (`HtmlSanitizer`) allowlistje UGYANAZ.
 |
 | A szerver a döntő (a kimenet az övé), de ha a kliens tágabb lenne, a vászon a mi
 | originünkön többet futtatna, mint amit a mentés megtart; ha szűkebb, a felhasználó
 | elveszítené a formázást, amit a szerver megtartana. Ez a teszt a két listát gépileg
 | veti össze, hogy egyik oldal se csússzon el csendben.
 */

/** @return list<string> */
function tsList(string $source, string $name): array
{
    preg_match('/const '.$name.' = \[(.*?)\];/s', $source, $match);
    preg_match_all("/'([^']+)'/", $match[1] ?? '', $items);

    return $items[1];
}

function phpConst(string $name): mixed
{
    return (new ReflectionClassConstant(HtmlSanitizer::class, $name))->getValue();
}

it('a tag-, attribútum- és stílus-listák a kliensen és a szerveren azonosak', function () {
    $ts = (string) file_get_contents(resource_path('js/Components/Editor/sanitize.ts'));

    foreach (['INLINE_TAGS', 'RICH_TAGS', 'BLOCK_TAGS', 'FORBIDDEN_STYLE'] as $name) {
        // A TS-forrásban a `\\` egyetlen visszaperjel.
        $client = array_map(static fn ($v) => str_replace('\\\\', '\\', $v), tsList($ts, $name));
        $server = phpConst($name);

        sort($client);
        sort($server);

        expect($client)->toBe($server, $name);
    }

    // A kliens a „tartalommal együtt dobott" elemekből legalább a szerverét dobja.
    expect(array_diff(phpConst('DROP_WITH_CONTENT'), tsList($ts, 'DROP_WITH_CONTENT')))->toBe([]);

    preg_match('/const ATTRIBUTES[^=]*= \{(.*?)\};/s', $ts, $block);
    foreach (phpConst('ATTRIBUTES') as $tag => $attributes) {
        $key = $tag === '*' ? "'\\*'" : $tag;
        preg_match('/(?:^|\s)'.$key.': \[(.*?)\]/m', $block[1], $row);
        preg_match_all("/'([^']+)'/", $row[1] ?? '', $items);

        expect($items[1])->toBe($attributes, "ATTRIBUTES[{$tag}]");
    }

    // KOMMENT a kliensen is eldobva (SS3).
    expect($ts)->toContain('COMMENT_NODE')->toContain('PROCESSING_INSTRUCTION_NODE');
});
