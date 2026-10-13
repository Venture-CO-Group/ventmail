<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\Deliverability\Services\SpamScorer;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AZ OPTIMALIZÁLÁS PANEL (v1.1): linkek, képek, súly, spam-becslés, kötelező elemek.
 *
 * ## A FORRÁS a block-tree ÉS a fordított HTML
 *
 * A linkeket és a képeket a FÁBÓL szedjük (tudjuk, melyik blokkból jönnek — a felület oda
 * tud ugrani), a súlyt és a spam-becslést a FORDÍTOTT HTML-ből (az megy ki).
 *
 * ## Nincs kimenő hálózati kérés a linkekre
 *
 * A „törött link" itt STATIKUS ellenőrzés: üres, horgony-helyőrző, nem engedett séma, hibás
 * alak, példa-domain. Egy HTTP HEAD minden linkre a szerkesztő minden megnyitásakor (a) SSRF-
 * felület lenne a belső hálóra, (b) lassú, (c) a céloldal szemében bot-forgalom. A spam-
 * becslés a meglévő `SpamScorer` (A6) — a szabály-alapú pontozás és a domain-feketelista.
 */
final class TemplateOptimizer
{
    /** A példa- és helyőrző-domainek — élesben egy ide mutató link biztosan hiba. */
    private const PLACEHOLDER_HOSTS = ['example.com', 'example.org', 'example.net', 'pelda.hu', 'localhost'];

    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];

    private const DBL_RUNS_PER_HOUR = 30;

    public function __construct(private readonly SpamScorer $spam, private readonly TemplateCompliance $compliance) {}

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<string, mixed>
     */
    public function analyze(array $blocks, string $html, string $text, ?string $subject, bool $marketing): array
    {
        $links = [];
        $images = [];

        $this->collect($blocks, '', $links, $images);

        $linkReport = array_map(fn (array $link) => $link + ['issues' => $this->linkIssues($link['url'])], $links);
        $imageReport = $this->imageReport($images);

        $plainLinks = array_values(array_filter(
            array_column($links, 'url'),
            static fn (string $url) => stripos($url, 'http') === 0
        ));

        $bytes = strlen($html);
        $limit = 102 * 1024;

        return [
            'links' => [
                'items' => $linkReport,
                'total' => count($linkReport),
                'broken' => count(array_filter($linkReport, static fn ($l) => array_intersect($l['issues'], ['empty', 'unsafe', 'malformed', 'placeholder']) !== [])),
                'missingUtm' => count(array_filter($linkReport, static fn ($l) => in_array('missing_utm', $l['issues'], true))),
            ],
            'images' => $imageReport,
            'weight' => [
                'bytes' => $bytes,
                'limit' => $limit,
                'clipped' => $bytes > $limit,
            ],
            'spam' => $this->spam->score($subject, $html, $text, $plainLinks, $this->mayLookupDomains())->toArray(),
            'compliance' => [
                'required' => $marketing,
                'missing' => $marketing ? $this->compliance->missing($blocks) : [],
            ],
        ];
    }

    /**
     * A DBL (DNS) lekérdezés WORKSPACE-KERETE (SS4, D46 kiegészítés): óránként legfeljebb
     * `DBL_RUNS_PER_HOUR` optimalizálás kérdez külső feketelistát; felette a becslés a helyi
     * szabályokkal fut (a hoszt-szintű gyorsítótár a `SpamScorer`-ben van).
     */
    private function mayLookupDomains(): bool
    {
        $context = app(WorkspaceContext::class);
        $key = 'template-dbl:'.($context->hasWorkspace() ? $context->workspaceId() : 'none');

        if (RateLimiter::tooManyAttempts($key, self::DBL_RUNS_PER_HOUR)) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * A link problémái — üres lista, ha rendben.
     *
     * @return list<string>
     */
    public function linkIssues(string $url): array
    {
        $url = trim($url);

        if ($url === '' || $url === '#') {
            return ['empty'];
        }

        // A MERGE-TAG (`{{ unsubscribe_url }}`) a küldéskor áll elő — itt nincs mit nézni,
        // csak a fallbackjét (azt a `UrlPolicy` megnézi).
        if (str_starts_with($url, '{{')) {
            return UrlPolicy::violation($url) === null ? [] : ['unsafe'];
        }

        if (UrlPolicy::violation($url) !== null) {
            return ['unsafe'];
        }

        if (stripos($url, 'mailto:') === 0) {
            return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) === false ? ['malformed'] : [];
        }

        if (str_starts_with($url, '#')) {
            return [];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || preg_match('/\s/', $url) === 1 || ! str_contains($host, '.') && $host !== 'localhost'
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['malformed'];
        }

        $issues = [];

        foreach (self::PLACEHOLDER_HOSTS as $placeholder) {
            if ($host === $placeholder || str_ends_with($host, '.'.$placeholder)) {
                $issues[] = 'placeholder';

                break;
            }
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (array_diff(self::UTM_KEYS, array_keys($query)) !== []) {
            $issues[] = 'missing_utm';
        }

        return $issues;
    }

    /**
     * @param  list<array{url: string, path: string, alt: ?string, width: mixed}>  $images
     * @return array<string, mixed>
     */
    private function imageReport(array $images): array
    {
        $urls = array_values(array_unique(array_column($images, 'url')));

        // A SAJÁT feltöltéseink méretét ismerjük (a `template_assets` sorból); külső képet
        // nem töltünk le a méretéért (lásd az osztály docblockját).
        $sizes = $urls === [] ? [] : TemplateAsset::query()->whereIn('url', $urls)->pluck('size', 'url')->all();

        $items = array_map(static fn (array $image) => [
            'url' => $image['url'],
            'path' => $image['path'],
            'missingAlt' => $image['alt'] === null || trim($image['alt']) === '',
            'bytes' => isset($sizes[$image['url']]) ? (int) $sizes[$image['url']] : null,
        ], $images);

        $known = array_filter(array_column($items, 'bytes'), static fn ($bytes) => $bytes !== null);

        return [
            'items' => $items,
            'total' => count($items),
            'missingAlt' => count(array_filter($items, static fn ($image) => $image['missingAlt'])),
            'largestBytes' => $known === [] ? null : max($known),
            // 1 MB fölött egy levélkép mobil-adatkapcsolaton érezhetően lassú.
            'heavy' => count(array_filter($known, static fn ($bytes) => $bytes > 1024 * 1024)),
        ];
    }

    /**
     * A linkek és a képek begyűjtése a fából — a blokk ÚTVONALÁVAL (`0.1.2`).
     *
     * @param  array<int, mixed>  $blocks
     * @param  list<array{url: string, path: string}>  $links
     * @param  list<array{url: string, path: string, alt: ?string, width: mixed}>  $images
     */
    private function collect(array $blocks, string $prefix, array &$links, array &$images): void
    {
        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $path = $prefix === '' ? (string) $index : $prefix.'.'.$index;
            $attrs = (array) ($block['attrs'] ?? []);
            $type = (string) ($block['type'] ?? '');

            $add = static function (mixed $url) use (&$links, $path): void {
                if (is_string($url)) {
                    $links[] = ['url' => $url, 'path' => $path];
                }
            };

            foreach (['href', 'share_url', 'base_url'] as $field) {
                if (array_key_exists($field, $attrs)) {
                    $add($attrs[$field]);
                }
            }

            if ($type === 'social' && ($attrs['mode'] ?? 'follow') === 'follow') {
                foreach ((array) ($attrs['networks'] ?? []) as $network) {
                    if (is_array($network)) {
                        $add($network['href'] ?? '');
                    }
                }
            }

            foreach (['content', 'description', 'question'] as $field) {
                if (is_string($attrs[$field] ?? null) && preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $attrs[$field], $matches)) {
                    foreach ($matches[2] as $href) {
                        $add(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }
                }
            }

            $imageField = match ($type) {
                'image', 'logo' => 'src',
                'video' => 'thumbnail',
                'product' => 'image',
                default => null,
            };

            if ($imageField !== null && is_string($attrs[$imageField] ?? null) && $attrs[$imageField] !== '') {
                $images[] = [
                    'url' => $attrs[$imageField],
                    'path' => $path,
                    'alt' => is_string($attrs['alt'] ?? null) ? $attrs['alt'] : ($type === 'product' ? (string) ($attrs['name'] ?? '') : null),
                    'width' => $attrs['width'] ?? null,
                ];
            }

            $this->collect((array) ($block['children'] ?? []), $path, $links, $images);
        }
    }
}
