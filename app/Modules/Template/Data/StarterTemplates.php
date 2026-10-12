<?php

declare(strict_types=1);

namespace App\Modules\Template\Data;

use App\Modules\Tenancy\Models\Workspace;

/**
 * A HAT INDULÓSABLON (v1.1 · sablonkönyvtár): hírlevél, akció, termékbemutató,
 * eseménymeghívó, köszönő, újraaktiváló.
 *
 * ## Saját tartalom, a felhasználó nyelvén
 *
 * Minden szöveg a `lang/<locale>/editor.php`-ból jön (7 nyelv), harmadik fél márkája,
 * képe vagy szövege nincs benne — a helyőrző képek a sajátjaink (`public/editor/`). A
 * sablon a LÉTREHOZÁS pillanatában, a felhasználó nyelvén áll össze, és onnantól a
 * workspace saját sablonja: az indulósablon maga nem módosul.
 *
 * ## A Venture designnyelv a levélben
 *
 * Indigó/ég-kék hangsúly, sötét tinta-szöveg, világos szürke-kék háttér, lekerekített gombok.
 * A színek a LEVÉL színei (e-mail-kliensek nem ismernek CSS-tokent), ezért itt, a szerveren
 * élnek, nem a felületen.
 *
 * Minden indulósablon marketing-kész: tartalmazza a kötelező láblécet (postai cím,
 * leiratkozó, beállítások) — a `TemplateCompliance` teszt őrzi.
 */
final class StarterTemplates
{
    public const KEYS = ['newsletter', 'promotion', 'product', 'event', 'thanks', 'reengage'];

    private const INK = '#0f172a';

    private const TEXT = '#334155';

    private const MUTED = '#64748b';

    private const INDIGO = '#4f46e5';

    private const INDIGO_SOFT = '#e0e7ff';

    private const SKY = '#0ea5e9';

    private const TEAL = '#0d9488';

    private const ORANGE = '#ea580c';

    private const LIGHT = '#f1f5f9';

    private const WHITE = '#ffffff';

    /**
     * A könyvtár listája (név, leírás, hangsúlyszín az előnézeti bélyeghez).
     *
     * @return list<array{key: string, name: string, description: string, accent: string}>
     */
    public static function catalog(): array
    {
        $accents = [
            'newsletter' => self::INDIGO,
            'promotion' => self::ORANGE,
            'product' => self::SKY,
            'event' => self::INDIGO,
            'thanks' => self::TEAL,
            'reengage' => self::INK,
        ];

        return array_map(static fn (string $key) => [
            'key' => $key,
            'name' => __("editor.starters.$key.name"),
            'description' => __("editor.starters.$key.description"),
            'accent' => $accents[$key],
        ], self::KEYS);
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * Az indulósablon tartalma a felhasználó nyelvén.
     *
     * @return array{subject: string, preheader: string, blocks: list<array<string, mixed>>, styles: array<string, mixed>}
     */
    public static function build(string $key, Workspace $workspace): array
    {
        $blocks = match ($key) {
            'newsletter' => self::newsletter(),
            'promotion' => self::promotion(),
            'product' => self::product(),
            'event' => self::event(),
            'thanks' => self::thanks(),
            'reengage' => self::reengage(),
            default => throw new \InvalidArgumentException("Ismeretlen indulósablon: {$key}"),
        };

        return [
            'subject' => __("editor.starters.$key.subject"),
            'preheader' => __("editor.starters.$key.preheader"),
            'blocks' => [self::header($workspace), ...$blocks, self::footer($workspace)],
            'styles' => [
                'font' => 'sans',
                'text_color' => self::TEXT,
                'heading_color' => self::INK,
                'link_color' => self::INDIGO,
                'background' => '#eef2ff',
                'content_background' => self::WHITE,
                'button_background' => self::INDIGO,
                'button_color' => self::WHITE,
                'button_radius' => 8,
                'font_size' => 16,
                'width' => 600,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function newsletter(): array
    {
        $t = static fn (string $k) => __("editor.starters.newsletter.$k");

        return [
            self::hero(self::INDIGO, $t('title'), $t('text'), $t('button')),
            self::section([
                self::column([
                    self::block('image', ['src' => EditorCatalog::placeholder('image'), 'alt' => $t('article1Title'), 'href' => 'https://example.com/blog/1']),
                    self::block('heading', ['level' => 3, 'content' => $t('article1Title')]),
                    self::block('text', ['content' => $t('article1Text')]),
                    self::block('text', ['content' => '<a href="https://example.com/blog/1">'.e($t('readMore')).'</a>']),
                ], '50%'),
                self::column([
                    self::block('image', ['src' => EditorCatalog::placeholder('image'), 'alt' => $t('article2Title'), 'href' => 'https://example.com/blog/2']),
                    self::block('heading', ['level' => 3, 'content' => $t('article2Title')]),
                    self::block('text', ['content' => $t('article2Text')]),
                    self::block('text', ['content' => '<a href="https://example.com/blog/2">'.e($t('readMore')).'</a>']),
                ], '50%'),
            ], ['padding' => '24px 16px']),
            self::section([self::column([
                self::block('divider', ['color' => '#e2e8f0']),
                self::block('heading', ['level' => 3, 'content' => $t('notesTitle')]),
                self::block('text', ['content' => '<ul><li>'.e($t('note1')).'</li><li>'.e($t('note2')).'</li><li>'.e($t('note3')).'</li></ul>']),
            ])]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function promotion(): array
    {
        $t = static fn (string $k) => __("editor.starters.promotion.$k");

        return [
            self::section([self::column([
                self::block('heading', ['level' => 1, 'align' => 'center', 'color' => self::WHITE, 'font_size' => 56, 'content' => $t('badge')]),
                self::block('heading', ['level' => 2, 'align' => 'center', 'color' => self::WHITE, 'content' => $t('title')]),
                self::block('text', ['align' => 'center', 'color' => '#ffedd5', 'content' => $t('text')]),
            ])], ['background' => self::ORANGE, 'padding' => '40px 32px']),
            self::section([self::column([
                self::block('text', ['align' => 'center', 'color' => self::MUTED, 'font_size' => 14, 'content' => $t('codeLabel')]),
                self::block('heading', ['level' => 2, 'align' => 'center', 'color' => self::INK, 'content' => $t('code')]),
                self::block('button', ['content' => $t('button'), 'href' => 'https://example.com/shop', 'background' => self::ORANGE]),
                self::block('text', ['align' => 'center', 'color' => self::MUTED, 'font_size' => 12, 'content' => $t('terms')]),
            ])], ['background' => self::LIGHT, 'padding' => '32px 32px']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function product(): array
    {
        $t = static fn (string $k) => __("editor.starters.product.$k");
        $product = static fn (int $n) => self::block('product', [
            'name' => $t("p{$n}Name"),
            'description' => $t("p{$n}Text"),
            'price' => $t("p{$n}Price"),
            'image' => EditorCatalog::placeholder('product'),
            'href' => 'https://example.com/product/'.$n,
            'button_label' => $t('view'),
        ]);

        return [
            self::hero(self::SKY, $t('title'), $t('text'), null),
            self::section([self::column([$product(1)], '50%'), self::column([$product(2)], '50%')], ['padding' => '24px 16px']),
            self::section([self::column([$product(3)], '50%'), self::column([$product(4)], '50%')], ['padding' => '8px 16px']),
            self::section([self::column([
                self::block('button', ['content' => $t('all'), 'href' => 'https://example.com/shop', 'background' => self::SKY]),
            ])]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function event(): array
    {
        $t = static fn (string $k) => __("editor.starters.event.$k");

        return [
            self::section([self::column([
                self::block('text', ['align' => 'center', 'color' => self::INDIGO_SOFT, 'font_size' => 14, 'content' => '<strong>'.e($t('date')).'</strong>']),
                self::block('heading', ['level' => 1, 'align' => 'center', 'color' => self::WHITE, 'content' => $t('title')]),
                self::block('text', ['align' => 'center', 'color' => self::INDIGO_SOFT, 'content' => $t('text')]),
                self::block('button', ['content' => $t('button'), 'href' => 'https://example.com/register', 'background' => self::WHITE, 'color' => self::INDIGO]),
            ])], ['background' => self::INDIGO, 'padding' => '40px 32px']),
            self::section([
                self::column([
                    self::block('heading', ['level' => 4, 'content' => $t('whenTitle')]),
                    self::block('text', ['content' => $t('when')]),
                ], '33.33%'),
                self::column([
                    self::block('heading', ['level' => 4, 'content' => $t('whereTitle')]),
                    self::block('text', ['content' => $t('where')]),
                ], '33.33%'),
                self::column([
                    self::block('heading', ['level' => 4, 'content' => $t('whatTitle')]),
                    self::block('text', ['content' => $t('what')]),
                ], '33.34%'),
            ], ['padding' => '24px 16px']),
            self::section([self::column([
                self::block('video', [
                    'thumbnail' => EditorCatalog::placeholder('video'),
                    'href' => 'https://example.com/event/teaser',
                    'alt' => $t('videoAlt'),
                    'play_label' => $t('play'),
                ]),
            ])]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function thanks(): array
    {
        $t = static fn (string $k) => __("editor.starters.thanks.$k");

        return [
            self::hero(self::TEAL, $t('title'), $t('text'), $t('button')),
            self::section([self::column([
                self::block('survey', [
                    'question' => $t('question'),
                    'base_url' => 'https://example.com/feedback',
                    'answers' => [$t('answer1'), $t('answer2'), $t('answer3')],
                    'button_background' => self::TEAL,
                ]),
            ])], ['padding' => '32px 24px']),
            self::section([self::column([
                self::block('text', ['align' => 'center', 'content' => $t('share')]),
                self::block('social', ['mode' => 'share', 'share_url' => 'https://example.com', 'networks' => ['facebook', 'x', 'linkedin']]),
            ])], ['background' => self::LIGHT, 'padding' => '24px 24px']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function reengage(): array
    {
        $t = static fn (string $k) => __("editor.starters.reengage.$k");
        $benefit = static fn (int $n) => self::column([
            self::block('heading', ['level' => 4, 'align' => 'center', 'content' => $t("b{$n}Title")]),
            self::block('text', ['align' => 'center', 'font_size' => 14, 'content' => $t("b{$n}Text")]),
        ], $n === 3 ? '33.34%' : '33.33%');

        return [
            self::hero(self::INK, $t('title'), $t('text'), $t('button')),
            self::section([$benefit(1), $benefit(2), $benefit(3)], ['padding' => '24px 16px']),
            self::section([self::column([
                self::block('text', ['align' => 'center', 'color' => self::MUTED, 'font_size' => 14, 'content' => $t('prefs')]),
                self::block('button', ['content' => $t('prefsButton'), 'href' => '{{ preferences_url }}', 'background' => self::WHITE, 'color' => self::INDIGO]),
            ])], ['background' => self::LIGHT, 'padding' => '24px 24px']),
        ];
    }

    /** @return array<string, mixed> */
    private static function header(Workspace $workspace): array
    {
        return self::section([
            self::column([
                self::block('logo', ['src' => EditorCatalog::placeholder('logo'), 'alt' => (string) $workspace->name, 'width' => 120, 'align' => 'left']),
            ], '50%'),
            self::column([
                self::block('text', ['align' => 'right', 'font_size' => 12, 'color' => self::MUTED,
                    'content' => '<a href="{{ browser_url }}">'.e(__('editor.starters.common.viewInBrowser')).'</a>']),
            ], '50%'),
        ], ['padding' => '16px 24px']);
    }

    /** @return array<string, mixed> */
    private static function footer(Workspace $workspace): array
    {
        return self::section([self::column([
            self::block('footer', EditorCatalog::footerDefaults($workspace)),
        ])], ['background' => self::LIGHT, 'padding' => '24px 24px']);
    }

    /** @return array<string, mixed> */
    private static function hero(string $background, string $title, string $text, ?string $button): array
    {
        $children = [
            self::block('heading', ['level' => 1, 'align' => 'center', 'color' => self::WHITE, 'content' => $title]),
            self::block('text', ['align' => 'center', 'color' => self::INDIGO_SOFT, 'content' => $text]),
        ];

        if ($button !== null) {
            $children[] = self::block('button', ['content' => $button, 'href' => 'https://example.com', 'background' => self::WHITE, 'color' => $background]);
        }

        return self::section([self::column($children)], ['background' => $background, 'padding' => '40px 32px']);
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function section(array $columns, array $attrs = []): array
    {
        return ['type' => 'section', 'attrs' => $attrs === [] ? [] : $attrs, 'children' => $columns];
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private static function column(array $children, ?string $width = null): array
    {
        return ['type' => 'column', 'attrs' => $width === null ? [] : ['width' => $width], 'children' => $children];
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function block(string $type, array $attrs): array
    {
        return ['type' => $type, 'attrs' => $attrs];
    }
}
