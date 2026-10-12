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
            'optional' => ['background', 'padding', 'full_width', 'condition', 'hide_on_mobile'],
            'children' => ['column'],
        ],
        'column' => [
            'required' => [],
            'optional' => ['width', 'background', 'padding'],
            'children' => self::CONTENT_BLOCKS,
        ],
        'heading' => ['required' => ['content'], 'optional' => ['level', 'align', 'color', 'font_size', 'padding', 'condition', 'hide_on_mobile'], 'children' => null],
        'text' => ['required' => ['content'], 'optional' => ['align', 'color', 'font_size', 'line_height', 'padding', 'condition', 'hide_on_mobile'], 'children' => null],
        'image' => ['required' => ['src'], 'optional' => ['alt', 'href', 'width', 'align', 'condition', 'hide_on_mobile'], 'children' => null],
        'button' => ['required' => ['content', 'href'], 'optional' => ['background', 'color', 'align', 'border_radius', 'condition', 'hide_on_mobile'], 'children' => null],
        'divider' => ['required' => [], 'optional' => ['color', 'padding', 'condition', 'hide_on_mobile'], 'children' => null],
        'spacer' => ['required' => [], 'optional' => ['height', 'condition', 'hide_on_mobile'], 'children' => null],
        // A LEIRATKOZÓ BLOKK (61. kritérium). Külön típus, nem „egy text blokk linkkel":
        // a szabály csak akkor kikényszeríthető, ha a blokk FELISMERHETŐ. FELTÉTEL NÉLKÜL:
        // egy feltételhez kötött leiratkozó link pont annál a címzettnél hiányozhatna.
        'unsubscribe' => ['required' => [], 'optional' => ['content', 'align', 'color', 'font_size'], 'children' => null],
        // KÖZÖSSÉGI blokk: `mode = follow` (a profilokra mutat) vagy `share` (a `share_url`-t
        // osztja meg a hálózaton). A megosztó linkeket a FORDÍTÓ építi, nem a felhasználó.
        'social' => ['required' => ['networks'], 'optional' => ['align', 'icon_size', 'mode', 'share_url', 'condition', 'hide_on_mobile'], 'children' => null],
        // NYERS HTML — szűk, dokumentált kivétel (a szerkesztőben „Kód"). A kimenet az
        // allowlist-sanitizeren megy át (`HtmlSanitizer::block()`).
        'html' => ['required' => ['content'], 'optional' => ['condition', 'hide_on_mobile'], 'children' => null],
        // v1.1 — LOGÓ: kép, kisebb alapszélességgel és a fejléc-mintákhoz.
        'logo' => ['required' => ['src'], 'optional' => ['alt', 'href', 'width', 'align', 'condition', 'hide_on_mobile'], 'children' => null],
        // v1.1 — VIDEÓ: a levélkliensek nem játszanak le videót, ezért bélyegkép + lejátszás
        // jel + link a videó oldalára. Ez a de facto e-mail-minta.
        'video' => ['required' => ['thumbnail', 'href'], 'optional' => ['alt', 'width', 'align', 'play_label', 'condition', 'hide_on_mobile'], 'children' => null],
        // v1.1 — TERMÉK: név, kép, ár, gomb.
        'product' => [
            'required' => ['name', 'href'],
            'optional' => ['image', 'alt', 'price', 'old_price', 'description', 'button_label', 'button_background', 'button_color', 'align', 'condition', 'hide_on_mobile'],
            'children' => null,
        ],
        // v1.1 — KÉRDŐÍV: egykattintásos válaszok. Minden válasz a `base_url`-re mutat
        // `answer=<n>` paraméterrel — a válasz a kattintásban van, nincs űrlap.
        'survey' => [
            'required' => ['question', 'base_url', 'answers'],
            'optional' => ['align', 'color', 'button_background', 'button_color', 'condition', 'hide_on_mobile'],
            'children' => null,
        ],
        // v1.1 — LÁBLÉC a KÖTELEZŐ elemekkel: postai cím (kötelező mező), leiratkozó és
        // beállítás-link (a fordító MINDIG kiírja, nem kapcsolható ki). FELTÉTEL NÉLKÜL.
        'footer' => [
            'required' => ['address'],
            'optional' => ['company', 'content', 'unsubscribe_label', 'preferences_label', 'align', 'color', 'link_color', 'font_size'],
            'children' => null,
        ],
    ];

    /** A tartalmi (levél-) blokkok — egy oszlop ezeket tartalmazhatja. */
    public const CONTENT_BLOCKS = [
        'text', 'heading', 'image', 'button', 'divider', 'spacer', 'unsubscribe', 'social', 'html',
        'logo', 'video', 'product', 'survey', 'footer',
    ];

    /**
     * A KÖTELEZŐ LÁBLÉC-ELEMEKET hordozó blokkok: ezek nem lehetnek feltételhez kötve, és a
     * szakaszuk sem — egy feltételes szakaszban a leiratkozó link pont annál a címzettnél
     * hiányozhatna, akinél a feltétel hamis.
     */
    public const COMPLIANCE_BLOCKS = ['unsubscribe', 'footer'];

    /** A közösségi hálózatok, amiket a fordító ismer (követés és megosztás). */
    public const SOCIAL_NETWORKS = ['facebook', 'x', 'twitter', 'instagram', 'linkedin', 'youtube', 'tiktok'];

    /** A megosztásra alkalmas hálózatok — ezeknek van nyilvános megosztó URL-je. */
    public const SHARE_NETWORKS = ['facebook', 'x', 'twitter', 'linkedin'];

    /** A kérdőív válaszainak felső száma. */
    public const MAX_SURVEY_ANSWERS = 6;

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
