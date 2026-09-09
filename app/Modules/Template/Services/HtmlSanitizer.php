<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * ALLOWLIST-ALAPÚ HTML-tisztítás — a nyers HTML blokkhoz és a formázott szöveghez.
 *
 * Miért nem regex: a blokk korábban KÉT mintával „védte" magát (`<script` és `\son[a-z]+=`).
 * Mérve mind ÁTMENT rajtuk, és nyersen benne volt a fordított HTML-ben:
 *   `<img/onerror=alert(1) src=x>`   — a `/` separator kikerülte a `\son…` mintát
 *   `<a href="javascript:alert(1)">` — a `href`-allowlist ide nem is látott el
 *   `<iframe srcdoc="…">`, `<style>…url(javascript:)…</style>`, `<meta http-equiv=refresh>`
 *   `<base href="https://evil.example/">`, `<form action="http://evil.example">`
 *   `</td></tr></table>`            — kitört a levél elrendezés-táblájából
 * A `<base>`/`<form>`/refresh a MI DKIM-ünkkel aláírt levélben adathalászat a saját
 * domainünkön. Egy denylist ezt nem tudja lezárni: minden új HTML-elem új rés. Ezért itt
 * allowlist van, és a kimenetet a DOM SZERIALIZÁLJA — a le nem zárt vagy túl korán zárt
 * tagok így nem tudnak kitörni a befoglaló táblából, mert a szerializálás újraírja őket.
 *
 * KÉT PROFIL, mert a két hívó igénye más:
 * - `inline()` — a `text`/`heading`/`button` tartalma. Itt a KONTRAKTUS a lényeg: az
 *   MJML-író korábban `htmlspecialchars`-olt, a plaintext-író viszont HTML-ként értelmezte
 *   ugyanazt a mezőt. A packet saját fixture-ével mérve a HTML törzsben a címzett `&lt;strong&gt;`
 *   helyett a NYERS TAGET látta, a plaintextben pedig a formázást — vagyis a két kimenet
 *   MÁST mondott. A szabály most: a `content` szűk inline HTML, mindkét író így kezeli.
 * - `block()` — a nyers HTML blokk. Több elem, de ugyanaz az elv.
 */
final class HtmlSanitizer
{
    /** Formázás, ami egy levélkliensben mindenhol működik. */
    private const INLINE_TAGS = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'span', 'br'];

    /** @var list<string> */
    private const BLOCK_TAGS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'hr', 'img',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'small', 'blockquote', 'center',
    ];

    /**
     * Amit TARTALMÁVAL EGYÜTT dobunk, nem kibontunk.
     *
     * A `<script>` és a `<style>` szövegtartalma KÓD: kibontva („unwrap") a forráskód
     * látható szövegként jelenne meg a levélben. A `<base>` és a `<meta refresh>` pedig
     * nem is tartalmaz semmit — a puszta jelenléte a támadás.
     */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'base', 'meta', 'link',
        'form', 'input', 'button', 'select', 'textarea', 'frame', 'frameset',
        'noscript', 'svg', 'math', 'template', 'applet', 'audio', 'video', 'source',
    ];

    /** @var array<string, list<string>> tag → engedett attribútumok (a `*` mindenkire) */
    private const ATTRIBUTES = [
        '*' => ['style', 'class', 'title', 'dir', 'lang'],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'border'],
        'td' => ['colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'bgcolor'],
        'th' => ['colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'bgcolor'],
        'tr' => ['align', 'valign', 'bgcolor'],
        'table' => ['width', 'align', 'border', 'cellpadding', 'cellspacing', 'bgcolor', 'role'],
        'p' => ['align'],
        'div' => ['align'],
    ];

    /** @var list<string> URL-t hordozó attribútumok — ezekre a `UrlPolicy` érvényes */
    private const URL_ATTRIBUTES = ['href', 'src'];

    /**
     * A `style` deklarációból kizárt minták.
     *
     * Az `url(` teljes tiltása szándékosan durva: a `url(javascript:…)` és a
     * `url(data:text/html,…)` egyaránt kiesik vele, és a levélben a háttérkép amúgy sem
     * hordoz olyan értéket, amiért ezt a felületet nyitva tartanánk.
     */
    private const FORBIDDEN_STYLE = ['expression', 'javascript', 'vbscript', 'behavior', '@import', 'url(', '\\'];

    public function inline(string $html): string
    {
        return $this->clean($html, self::INLINE_TAGS);
    }

    public function block(string $html): string
    {
        return $this->clean($html, array_merge(self::INLINE_TAGS, self::BLOCK_TAGS));
    }

    /** @param list<string> $allowedTags */
    private function clean(string $html, array $allowedTags): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        // A HIBÁKAT ELNYELJÜK, de a kimenetet nem a bemenet érvényessége adja: az ügyfél
        // HTML-je definíció szerint törött lehet, és a DOM azt is beolvassa. A libxml
        // globális hibapufferét visszaállítjuk, különben egy másik hívó hibáit örökölnénk.
        $previous = libxml_use_internal_errors(true);

        // A `<?xml encoding>` prológ nélkül a libxml latin-1-nek veszi a bemenetet, és az
        // ékezetek elromlanak. A `<div id="vm-root">` a horgony: a `saveHTML` a GYEREKEIT
        // írja ki, tehát a burkoló nem kerül a kimenetbe.
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="vm-root">'.$html.'</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $this->root($document);

        if ($root === null) {
            return '';
        }

        $this->walk($root, $allowedTags);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= (string) $document->saveHTML($child);
        }

        // A HATÁROLÓK helyreállítása: a libxml az URI-attribútumokban percent-kódol, és
        // hogy mit, az a verziójától függ. A `MergeTag` mindkét alakot ismeri.
        return MergeTag::restore($out);
    }

    private function root(DOMDocument $document): ?DOMElement
    {
        foreach ($document->getElementsByTagName('div') as $div) {
            if ($div instanceof DOMElement && $div->getAttribute('id') === 'vm-root') {
                return $div;
            }
        }

        return null;
    }

    /**
     * A fa bejárása HÁTULRÓL ELŐRE.
     *
     * Miért visszafelé: a törlés és a kibontás MENET KÖZBEN módosítja a `childNodes`
     * élő listáját. Előrefelé haladva egy törlés után a következő gyerek átcsúszik a már
     * feldolgozott indexre, és VIZSGÁLATLANUL marad a kimenetben — pont a tiltott elem
     * mellett álló szomszédja.
     *
     * @param  list<string>  $allowedTags
     */
    private function walk(DOMNode $node, array $allowedTags): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, $allowedTags, true)) {
                // KIBONTÁS, nem törlés: az ügyfél szövegét megtartjuk, csak a burkoló
                // elemet dobjuk. A gyerekeket előbb megtisztítjuk, hogy a felmozgatott
                // részfa ne kerüljön vizsgálat nélkül a szülőbe.
                $this->walk($child, $allowedTags);
                $this->unwrap($child);

                continue;
            }

            $this->cleanAttributes($child, $tag);
            $this->walk($child, $allowedTags);
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = array_merge(self::ATTRIBUTES['*'], self::ATTRIBUTES[$tag] ?? []);

        // MÁSOLAT: az `attributes` élő gyűjtemény, a törlés közben indexet csúsztat.
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            if ($attribute instanceof DOMAttr) {
                $attributes[] = $attribute;
            }
        }

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) && ! UrlPolicy::isAllowed($attribute->value)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'style') {
                $style = $this->style($attribute->value);

                $style === '' ? $element->removeAttribute($attribute->name) : $element->setAttribute('style', $style);
            }

            // A `target="_blank"` `rel` nélkül a megnyíló lapnak `window.opener`-t ad —
            // a webes előnézetből ez a MI oldalunkra visszanyúló hivatkozás.
            if ($name === 'target' && $tag === 'a') {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    private function style(string $value): string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '' || ! str_contains($declaration, ':')) {
                continue;
            }

            $lower = strtolower($declaration);
            $bad = false;

            foreach (self::FORBIDDEN_STYLE as $needle) {
                if (str_contains($lower, $needle)) {
                    $bad = true;

                    break;
                }
            }

            if (! $bad) {
                $kept[] = $declaration;
            }
        }

        return implode('; ', $kept);
    }
}
