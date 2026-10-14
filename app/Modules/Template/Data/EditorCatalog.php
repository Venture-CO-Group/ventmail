<?php

declare(strict_types=1);

namespace App\Modules\Template\Data;

use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\Segment;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;

/**
 * A SZERKESZTŐ KATALÓGUSA (v1.1): paletta, elrendezések, minták, merge-tagek, színminták.
 *
 * ## Miért a SZERVEREN
 *
 * 1. A blokkok ALAPÉRTÉKE (szín, méret, helyőrző kép) a levélbe kerül — ez tartalom, nem
 *    felület. A frontend nem tartalmazhat beégetett színt (a `SmokePageTest` őrzi), és nem
 *    is kell: a katalógus a `BlockSchema` mellett él, ugyanabból a forrásból, mint a
 *    validátor. Egy kézzel karbantartott TS-másolat az első új blokknál elcsúszna.
 * 2. A szövegek a felhasználó NYELVÉN jönnek (`lang/<locale>/editor.php`).
 */
final class EditorCatalog
{
    /** A színminták a színválasztóhoz — a levél színei, nem a felületé. */
    public const SWATCHES = [
        '#0f172a', '#334155', '#64748b', '#ffffff', '#f1f5f9', '#eef2ff',
        '#4f46e5', '#6366f1', '#0ea5e9', '#0d9488', '#16a34a', '#ea580c', '#dc2626', '#db2777',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(Workspace $workspace): array
    {
        return [
            'blocks' => self::blocks($workspace),
            'layouts' => self::layouts(),
            'patterns' => self::patterns($workspace),
            'mergeTags' => self::mergeTags(),
            'conditionFields' => self::conditionFields(),
            'segments' => Segment::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Segment $segment) => ['uuid' => (string) $segment->uuid, 'name' => (string) $segment->name])
                ->values()->all(),
            'fonts' => array_keys(TemplateStyles::FONTS),
            'fontStacks' => TemplateStyles::FONTS,
            'styleDefaults' => TemplateStyles::DEFAULTS,
            'styleRanges' => TemplateStyles::RANGES,
            'swatches' => self::SWATCHES,
            'socialNetworks' => BlockSchema::SOCIAL_NETWORKS,
            'shareNetworks' => BlockSchema::SHARE_NETWORKS,
            'maxSurveyAnswers' => BlockSchema::MAX_SURVEY_ANSWERS,
            // A KÉP-ALAP: a saját originünk https-alakja. A vászon az ide mutató képet
            // relatív úton tölti be (fejlesztésben a szerver `http`-n fut).
            'assetBase' => self::httpsBase(),
            'gmailClipBytes' => 102 * 1024,
        ];
    }

    /** @return list<array{id: string, type: string, icon: string, defaults: array<string, mixed>}> */
    public static function blocks(Workspace $workspace): array
    {
        return [
            ['id' => 'heading', 'type' => 'heading', 'icon' => 'heading', 'defaults' => ['content' => __('editor.defaults.heading'), 'level' => 2]],
            ['id' => 'text', 'type' => 'text', 'icon' => 'paragraph', 'defaults' => ['content' => __('editor.defaults.paragraph')]],
            ['id' => 'button', 'type' => 'button', 'icon' => 'button', 'defaults' => ['content' => __('editor.defaults.button'), 'href' => 'https://example.com']],
            ['id' => 'divider', 'type' => 'divider', 'icon' => 'divider', 'defaults' => ['color' => '#e2e8f0']],
            ['id' => 'spacer', 'type' => 'spacer', 'icon' => 'spacer', 'defaults' => ['height' => 24]],
            ['id' => 'image', 'type' => 'image', 'icon' => 'image', 'defaults' => ['src' => self::placeholder('image'), 'alt' => __('editor.defaults.imageAlt')]],
            ['id' => 'logo', 'type' => 'logo', 'icon' => 'logo', 'defaults' => ['src' => self::placeholder('logo'), 'alt' => (string) $workspace->name, 'width' => 120, 'align' => 'left']],
            ['id' => 'social_follow', 'type' => 'social', 'icon' => 'social', 'defaults' => ['mode' => 'follow', 'networks' => [
                ['name' => 'facebook', 'href' => 'https://facebook.com/'],
                ['name' => 'instagram', 'href' => 'https://instagram.com/'],
                ['name' => 'linkedin', 'href' => 'https://linkedin.com/'],
            ]]],
            ['id' => 'social_share', 'type' => 'social', 'icon' => 'share', 'defaults' => ['mode' => 'share', 'share_url' => 'https://example.com', 'networks' => ['facebook', 'x', 'linkedin']]],
            ['id' => 'video', 'type' => 'video', 'icon' => 'video', 'defaults' => ['thumbnail' => self::placeholder('video'), 'href' => 'https://example.com/video', 'alt' => __('editor.defaults.videoAlt'), 'play_label' => __('editor.defaults.play')]],
            ['id' => 'html', 'type' => 'html', 'icon' => 'code', 'defaults' => ['content' => '<p>'.e(__('editor.defaults.code')).'</p>']],
            ['id' => 'product', 'type' => 'product', 'icon' => 'product', 'defaults' => [
                'name' => __('editor.defaults.productName'),
                'description' => __('editor.defaults.productDescription'),
                'price' => __('editor.defaults.productPrice'),
                'image' => self::placeholder('product'),
                'href' => 'https://example.com/product',
                'button_label' => __('editor.defaults.productButton'),
            ]],
            ['id' => 'survey', 'type' => 'survey', 'icon' => 'survey', 'defaults' => [
                'question' => __('editor.defaults.surveyQuestion'),
                'base_url' => 'https://example.com/survey',
                'answers' => [__('editor.defaults.surveyYes'), __('editor.defaults.surveyMaybe'), __('editor.defaults.surveyNo')],
            ]],
            ['id' => 'footer', 'type' => 'footer', 'icon' => 'footer', 'defaults' => self::footerDefaults($workspace)],
        ];
    }

    /**
     * A TRANZAKCIÓS sablon lábléce (UI-polírozás · 7. pont, D303): cégnév + postai cím, egy
     * szöveg-blokkban. A `footer` blokk itt NEM jó: az a leiratkozó és a beállítás-linket
     * feltétel nélkül kiírja (ez a lényege), a tranzakciós levélnek pedig nincs leiratkozója.
     *
     * @return array<string, mixed>
     */
    public static function transactionalFooter(Workspace $workspace): array
    {
        $defaults = self::footerDefaults($workspace);
        // Soronként egy bekezdés (a szöveg-blokk tisztítója a `<br>`-t nem XML-alakban adja vissza).
        $lines = array_filter(array_map('trim', preg_split('/\R/', (string) $defaults['address']) ?: []), static fn (string $line) => $line !== '');
        $address = implode('', array_map(static fn (string $line) => '<p>'.e($line).'</p>', $lines));

        return ['type' => 'section', 'attrs' => ['background' => '#f1f5f9'], 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => [
                'align' => 'center',
                'color' => '#52606d',
                'content' => '<p><strong>'.e((string) $defaults['company']).'</strong></p>'.$address,
            ]],
        ]]]];
    }

    /** @return array<string, mixed> */
    public static function footerDefaults(Workspace $workspace): array
    {
        $address = trim((string) (($workspace->settings ?? [])['postal_address'] ?? ''));

        return [
            'company' => (string) $workspace->name,
            'address' => $address !== '' ? $address : __('editor.defaults.footerAddress'),
            'content' => __('editor.defaults.footerContent'),
            'unsubscribe_label' => __('editor.defaults.unsubscribe'),
            'preferences_label' => __('editor.defaults.preferences'),
        ];
    }

    /**
     * Az OSZLOP-ELRENDEZÉSEK (az oszlopok szélessége százalékban).
     *
     * @return list<array{key: string, columns: list<string>, padding: string}>
     */
    public static function layouts(): array
    {
        return [
            ['key' => '1', 'columns' => ['100%'], 'padding' => '16px 24px'],
            ['key' => '2', 'columns' => ['50%', '50%'], 'padding' => '16px 16px'],
            ['key' => '3', 'columns' => ['33.33%', '33.33%', '33.34%'], 'padding' => '16px 16px'],
            ['key' => '4', 'columns' => ['25%', '25%', '25%', '25%'], 'padding' => '16px 12px'],
            ['key' => '1-2', 'columns' => ['33.33%', '66.67%'], 'padding' => '16px 16px'],
            ['key' => '2-1', 'columns' => ['66.67%', '33.33%'], 'padding' => '16px 16px'],
        ];
    }

    /**
     * A SZAKASZ-MINTÁK: fejléc, hero, lábléc.
     *
     * @return list<array{key: string, section: array<string, mixed>}>
     */
    public static function patterns(Workspace $workspace): array
    {
        return [
            ['key' => 'header', 'section' => [
                'type' => 'section',
                'attrs' => ['background' => '#ffffff', 'padding' => '16px 24px'],
                'children' => [
                    ['type' => 'column', 'attrs' => ['width' => '40%'], 'children' => [
                        ['type' => 'logo', 'attrs' => ['src' => self::placeholder('logo'), 'alt' => (string) $workspace->name, 'width' => 120, 'align' => 'left']],
                    ]],
                    ['type' => 'column', 'attrs' => ['width' => '60%'], 'children' => [
                        ['type' => 'text', 'attrs' => ['align' => 'right', 'font_size' => 14, 'content' => __('editor.patterns.headerLinks')]],
                    ]],
                ],
            ]],
            ['key' => 'hero', 'section' => [
                'type' => 'section',
                'attrs' => ['background' => '#4f46e5', 'padding' => '40px 32px'],
                'children' => [['type' => 'column', 'children' => [
                    ['type' => 'heading', 'attrs' => ['level' => 1, 'align' => 'center', 'color' => '#ffffff', 'content' => __('editor.patterns.heroTitle')]],
                    ['type' => 'text', 'attrs' => ['align' => 'center', 'color' => '#e0e7ff', 'content' => __('editor.patterns.heroText')]],
                    ['type' => 'button', 'attrs' => ['content' => __('editor.patterns.heroButton'), 'href' => 'https://example.com', 'background' => '#ffffff', 'color' => '#4f46e5']],
                ]]],
            ]],
            ['key' => 'footer', 'section' => [
                'type' => 'section',
                'attrs' => ['background' => '#f1f5f9', 'padding' => '24px 24px'],
                'children' => [['type' => 'column', 'children' => [
                    ['type' => 'social', 'attrs' => ['mode' => 'follow', 'networks' => [
                        ['name' => 'facebook', 'href' => 'https://facebook.com/'],
                        ['name' => 'instagram', 'href' => 'https://instagram.com/'],
                    ]]],
                    ['type' => 'footer', 'attrs' => self::footerDefaults($workspace)],
                ]]],
            ]],
        ];
    }

    /**
     * A MERGE-TAGEK — a SAJÁT szintaxisunk (`{{ kulcs || alapérték }}`), amit a
     * `TemplateRenderer` ért. A `fallback` a javasolt alapérték, a választóban átírható.
     *
     * @return list<array{key: string, group: string, link: bool, fallback: string}>
     */
    public static function mergeTags(): array
    {
        return [
            ['key' => 'contact.first_name', 'group' => 'contact', 'link' => false, 'fallback' => __('editor.defaults.firstNameFallback')],
            ['key' => 'contact.last_name', 'group' => 'contact', 'link' => false, 'fallback' => ''],
            ['key' => 'contact.company', 'group' => 'contact', 'link' => false, 'fallback' => ''],
            ['key' => 'contact.email', 'group' => 'contact', 'link' => false, 'fallback' => ''],
            ['key' => 'unsubscribe_url', 'group' => 'links', 'link' => true, 'fallback' => ''],
            ['key' => 'preferences_url', 'group' => 'links', 'link' => true, 'fallback' => ''],
            ['key' => 'browser_url', 'group' => 'links', 'link' => true, 'fallback' => ''],
            ['key' => 'current_year', 'group' => 'workspace', 'link' => false, 'fallback' => ''],
            ['key' => 'workspace.name', 'group' => 'workspace', 'link' => false, 'fallback' => ''],
            ['key' => 'workspace.address', 'group' => 'workspace', 'link' => false, 'fallback' => ''],
        ];
    }

    /** @return list<string> a feltételes blokk választható mezői */
    public static function conditionFields(): array
    {
        $fields = ['contact.first_name', 'contact.last_name', 'contact.email', 'contact.company'];

        foreach (ContactField::query()->orderBy('key')->pluck('key') as $key) {
            if (preg_match('/^[a-z0-9_]{1,64}$/', (string) $key) === 1) {
                $fields[] = 'contact.attributes.'.$key;
            }
        }

        return $fields;
    }

    /** A saját helyőrző képeink (a `public/editor/` alatt) — nincs harmadik féltől származó kép. */
    public static function placeholder(string $kind): string
    {
        return self::httpsBase().'/editor/placeholder-'.$kind.'.png';
    }

    public static function httpsBase(): string
    {
        return (string) preg_replace('#^http://#i', 'https://', rtrim((string) config('app.url'), '/'));
    }

    /**
     * A lábléc FALLBACK felirata (leiratkozás / beállítások) a workspace nyelvén — ha a
     * workspace nem mond nyelvet, az aktuálisén. A felhasználó által megadott felirat
     * mindig elsőbbséget élvez; ez csak az üres mező helyére kerül.
     */
    public static function footerLabel(string $key): string
    {
        $context = app(WorkspaceContext::class);
        $locale = $context->hasWorkspace() ? (($context->workspace()->settings ?? [])['locale'] ?? null) : null;
        $locale = is_string($locale) && in_array($locale, (array) config('app.supported_locales'), true) ? $locale : app()->getLocale();

        return (string) __('editor.defaults.'.$key, [], $locale);
    }
}
