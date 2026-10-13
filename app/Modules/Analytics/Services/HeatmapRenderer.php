<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateVersion;
use App\Modules\Template\Services\MergeTag;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\TemplateVersions;
use DOMDocument;
use DOMElement;

/**
 * KATTINTÁSI HŐTÉRKÉP — a kampány sablonjának kirenderelt HTML-je, minden `<a href>`-en a
 * térkép-kulccsal (`data-vm-link`), amire a szülő-oldal a kattintás-arányokat rajzolja.
 *
 * ## Melyik sablonverzió
 *
 * A küldéskori: a `template_versions` legutolsó, a kampány indulása (`started_at`) előtt vagy
 * akkor készült pillanatképe; ha ilyen nincs, az aktuális sablon. Ha a választott verzió
 * tartalom-lenyomata eltér a mostanitól, a felület jelzi: „a sablon a küldés óta változott".
 *
 * ## A kulcs ugyanaz, mint a rögzítésnél
 *
 * `ClickMap::linkKey()` — ugyanaz a normalizálás (PII-paraméterek levágva) és ugyanaz a kulcsolt
 * hash, mint a kattintás rögzítésekor. A merge-tagek az ALAPÉRTÉKÜKKEL renderelődnek (mint a
 * webes nézetben), így a személyre szabott linkek a gyűjtő-sorba esnek, nem hamis kulcsra.
 *
 * ## Biztonság
 *
 * A HTML a tenant tartalma. A válasz `sandbox` CSP-vel megy, a szülő pedig szkript nélküli,
 * `sandbox="allow-same-origin"` iframe-ben mutatja (`srcdoc`) — a dokumentum nem futtathat kódot.
 */
final class HeatmapRenderer
{
    public function __construct(
        private readonly MjmlWriter $writer,
        private readonly MjmlCompiler $compiler,
        private readonly TemplateRenderer $renderer,
    ) {}

    /** @return array{html: ?string, templateChanged: bool, versionNumber: ?int} */
    public function render(Campaign $campaign): array
    {
        $template = $campaign->template_id !== null ? Template::query()->find($campaign->template_id) : null;

        if ($template === null) {
            return ['html' => null, 'templateChanged' => false, 'versionNumber' => null];
        }

        $version = $campaign->started_at === null ? null : TemplateVersion::query()
            ->where('template_id', $template->getKey())
            ->where('created_at', '<=', $campaign->started_at)
            ->orderByDesc('created_at')
            ->orderByDesc('number')
            ->first();

        $source = $version ?? $template;
        $blocks = (array) $source->blocks;

        if ($blocks === []) {
            return ['html' => null, 'templateChanged' => false, 'versionNumber' => $version?->number];
        }

        $styles = is_array($source->styles) ? $source->styles : null;
        $html = $this->compiler->compile($this->writer->write($blocks, $source->preheader, $styles));
        $html = $this->renderer->render(MergeTag::restore($html), [], conditions: true);

        return [
            'html' => $this->annotate($html),
            'templateChanged' => $version !== null && $version->content_hash !== TemplateVersions::hash($template),
            'versionNumber' => $version?->number,
        ];
    }

    /** Minden `<a href>` kap egy `data-vm-link` térkép-kulcsot; szkript és eseménykezelő kiesik. */
    private function annotate(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($document->getElementsByTagName('script')) as $script) {
            $script->parentNode?->removeChild($script);
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            /** @var DOMElement $element */
            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                if (str_starts_with(strtolower($attribute->nodeName), 'on')) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }
        }

        foreach ($document->getElementsByTagName('a') as $anchor) {
            /** @var DOMElement $anchor */
            $href = trim($anchor->getAttribute('href'));

            if ($href === '' || preg_match('#^https?://#i', $href) !== 1) {
                continue;
            }

            $anchor->setAttribute('data-vm-link', ClickMap::linkKey($href));
        }

        $out = (string) $document->saveHTML();

        return (string) preg_replace('/^<\?xml encoding="UTF-8">/', '', $out);
    }
}
