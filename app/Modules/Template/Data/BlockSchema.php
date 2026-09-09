<?php

declare(strict_types=1);

namespace App\Modules\Template\Data;

/**
 * A KÖZÖS BLOCK-TREE SÉMA (58. kritérium).
 *
 * Ez az alak a ventStudio-val KÖZÖS — a `.claude/rules` szerint a block-tree JSON Schema
 * közös, és az MJML-fordítás van a ventMail oldalán. Ezért a séma DEKLARATÍV, egy helyen
 * leírva: ha a két app kódjában két külön értelmezés élne, a szerkesztőben összeállított
 * levél a másik oldalon máshogy nézne ki — és a hibát senki nem venné észre, csak a címzett.
 *
 * ## Miért nem külső JSON Schema könyvtár
 *
 * Egy JSON Schema validátor általános, de az általános hibaüzenete (`#/0/attrs: does not
 * match`) a szerkesztő felhasználójának semmit nem mond. Itt a hiba MEGNEVEZHETŐ: melyik
 * blokk, melyik mező, mit várunk. A séma viszont GÉPI alakban is elérhető
 * (`toJsonSchema()`), hogy a ventStudio és az `@venture/sdk` ugyanezt lássa — a közös
 * igazság így nem két implementáció, hanem egy leírás.
 */
final class BlockSchema
{
    /**
     * A BLOKKTÍPUSOK és a mezőik.
     *
     * `required`: a mező nélkül a blokk értelmezhetetlen (kivétel).
     * `optional`: lehet, hogy nincs — alapértelmezéssel.
     * `children`: tartalmazhat-e beágyazott blokkokat (és milyeneket).
     *
     * @var array<string, array{required: list<string>, optional: list<string>, children: ?list<string>}>
     */
    public const BLOCKS = [
        // A GYÖKÉR-szintű elrendezés. A `columns` a reszponzív viselkedés hordozója: az
        // MJML-ben `mj-section` + `mj-column`, ami mobilon egymás alá csúszik.
        'section' => [
            'required' => [],
            'optional' => ['background', 'padding', 'full_width'],
            'children' => ['column'],
        ],
        'column' => [
            'required' => [],
            'optional' => ['width', 'background', 'padding'],
            'children' => ['text', 'heading', 'image', 'button', 'divider', 'spacer', 'unsubscribe', 'social', 'html'],
        ],
        'heading' => ['required' => ['content'], 'optional' => ['level', 'align', 'color', 'font_size'], 'children' => null],
        'text' => ['required' => ['content'], 'optional' => ['align', 'color', 'font_size', 'line_height'], 'children' => null],
        'image' => ['required' => ['src'], 'optional' => ['alt', 'href', 'width', 'align'], 'children' => null],
        'button' => ['required' => ['content', 'href'], 'optional' => ['background', 'color', 'align', 'border_radius'], 'children' => null],
        'divider' => ['required' => [], 'optional' => ['color', 'padding'], 'children' => null],
        'spacer' => ['required' => [], 'optional' => ['height'], 'children' => null],
        // A LEIRATKOZÓ BLOKK (61. kritérium). Külön típus, nem „egy text blokk linkkel":
        // a szabály csak akkor kikényszeríthető, ha a blokk FELISMERHETŐ.
        'unsubscribe' => ['required' => [], 'optional' => ['content', 'align', 'color', 'font_size'], 'children' => null],
        'social' => ['required' => ['networks'], 'optional' => ['align', 'icon_size'], 'children' => null],
        // NYERS HTML — szűk, dokumentált kivétel. A tartalma NEM megy át a merge-tag
        // escape-en (a hívó szándéka a nyers alak), ezért a szerkesztőben jogosultsághoz
        // kötött, és a validátor legalább a `<script>`-et kizárja.
        'html' => ['required' => ['content'], 'optional' => [], 'children' => null],
    ];

    /** A gyökérben CSAK section állhat: a levél elrendezése szakaszokból épül. */
    public const ROOT_BLOCKS = ['section'];

    /** A fa maximális mélysége. Egy 50 szintű fa a fordítót és a szerkesztőt is megállítja. */
    public const MAX_DEPTH = 6;

    /** A blokkok maximális száma egy sablonban. */
    public const MAX_BLOCKS = 500;

    /**
     * A séma GÉPI alakja — a ventStudio és a `@venture/sdk` ezt olvassa.
     *
     * @return array<string, mixed>
     */
    public static function toJsonSchema(): array
    {
        $definitions = [];

        foreach (self::BLOCKS as $type => $spec) {
            $properties = ['type' => ['const' => $type], 'attrs' => ['type' => 'object']];

            if ($spec['children'] !== null) {
                $properties['children'] = [
                    'type' => 'array',
                    'items' => ['$ref' => '#/definitions/block'],
                ];
            }

            $definitions[$type] = [
                'type' => 'object',
                'required' => ['type'],
                'properties' => $properties,
                'x-required-attrs' => $spec['required'],
                'x-optional-attrs' => $spec['optional'],
                'x-allowed-children' => $spec['children'],
            ];
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'ventMail block-tree',
            'type' => 'array',
            'items' => ['$ref' => '#/definitions/root'],
            'definitions' => array_merge($definitions, [
                'root' => ['oneOf' => array_map(
                    static fn (string $type) => ['$ref' => '#/definitions/'.$type],
                    self::ROOT_BLOCKS
                )],
                'block' => ['oneOf' => array_map(
                    static fn (string $type) => ['$ref' => '#/definitions/'.$type],
                    array_keys(self::BLOCKS)
                )],
            ]),
            'x-max-depth' => self::MAX_DEPTH,
            'x-max-blocks' => self::MAX_BLOCKS,
        ];
    }
}
