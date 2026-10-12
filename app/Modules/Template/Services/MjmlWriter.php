<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\StoredTemplates\Services\ConditionalContent;
use App\Modules\Template\Data\BlockSchema;
use App\Modules\Template\Data\TemplateStyles;

/**
 * BLOCK-TREE → MJML (59. kritérium).
 *
 * ## Miért az MJML a KÖZÉPSŐ alak, és miért nem a végső
 *
 * Az MJML az e-mail-világ de facto elrendezési nyelve: a `mj-section`/`mj-column` páros az,
 * ami mobilon egymás alá csúszik anélkül, hogy a fejlesztőnek táblázatokat kellene
 * kézzel egymásba írnia. A block-tree-t ezért ELŐSZÖR MJML-re fordítjuk — ez az alak
 * OLVASHATÓ, verzionálható, és a ventStudio előnézete is ezt tudja értelmezni.
 *
 * ## Amit itt ki kell mondani: NEM hívunk Node-ot
 *
 * A kanonikus MJML-fordító Node-alkalmazás. Egy `mjml` bináris meghívása a küldési útból
 * három dolgot jelentene: külső folyamat a kérés kritikus útján, egy Node-futtatókörnyezet a
 * PHP-konténerben, és egy verzió, ami a HTML kimenetét csendben megváltoztathatja két deploy
 * között. Az utolsó a legrosszabb: a levél kinézete a fordító patch-verziójától függne.
 *
 * Ezért az MJML-t MI fordítjuk le HTML-re (`MjmlCompiler`), a saját, SZŰK
 * komponens-készletünkre — pontosan arra, amit ez az író előállít. A kimenet így
 * determinisztikus (a snapshot-teszt ezt őrzi), és a fordító a mi verziónk alatt van.
 *
 * A korlát ennek az árnyoldala, és vállalt: a felhasználó nem írhat be BÁRMILYEN MJML-t —
 * csak azt, amit a block editor előállít. Aki nyers HTML-t akar, a `html` blokkot használja.
 */
final class MjmlWriter
{
    /**
     * A GLOBÁLIS stílus (v1.1), a `write()` idejére. `null` = a v1.1 előtti alapértékek,
     * bitre azonos kimenettel.
     *
     * @var array<string, mixed>|null
     */
    private ?array $styles = null;

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, mixed>|null  $styles  a `TemplateStyles` alakja
     */
    public function write(array $blocks, ?string $preheader = null, ?array $styles = null): string
    {
        $this->styles = $styles === null || $styles === [] ? null : TemplateStyles::resolve($styles);

        $body = '';

        foreach ($blocks as $block) {
            $body .= $this->section(is_array($block) ? $block : []);
        }

        // A PREHEADER az a szöveg, amit a postafiók-lista a tárgy MELLETT mutat. Ha nincs,
        // a kliens a levél első sorát veszi — ami gyakran a „ha nem jelenik meg helyesen"
        // sor. Az `mj-preview` pontosan ezt a helyet szabályozza.
        $preview = $preheader === null || trim($preheader) === ''
            ? ''
            // A PREHEADER SIMA SZÖVEG: a postafiók-lista nem rendel HTML-t, a beszúrt
            // tagek ott NYERSEN látszanának. Ezért itt `plain()`, nem `text()`.
            : '<mj-preview>'.$this->plain($preheader).'</mj-preview>';

        return '<mjml><mj-head>'.$preview.$this->head().$this->styleHead().'</mj-head>'
            .$this->bodyOpen().$body.'</mj-body></mjml>';
    }

    /**
     * A GLOBÁLIS stílus jelölője — CSAK ha van stílus. A fordító ebből veszi a betűcsaládot
     * és a link-színt (ezek nem elemszintű attribútumok).
     */
    private function styleHead(): string
    {
        if ($this->styles === null) {
            return '';
        }

        return '<mj-vm-styles font-family="'.$this->plain(TemplateStyles::fontStack((string) $this->styles['font'])).'"'
            .' link-color="'.$this->colour($this->styles['link_color']).'" />';
    }

    private function bodyOpen(): string
    {
        if ($this->styles === null) {
            return '<mj-body>';
        }

        return '<mj-body width="'.(int) $this->styles['width'].'px"'
            .' background-color="'.$this->colour($this->styles['background']).'">';
    }

    /** A stílus egy értéke — vagy `null`, ha nincs globális stílus. */
    private function style(string $key): mixed
    {
        return $this->styles[$key] ?? null;
    }

    private function head(): string
    {
        // A TIPOGRÁFIA egy helyen. A levélklienseknek nincs közös alapértelmezésük, tehát
        // ami nincs kimondva, az kliensenként más — a `mj-attributes` az MJML módja
        // arra, hogy egyszer mondjuk ki.
        return '<mj-attributes>'
            .'<mj-all font-family="Helvetica, Arial, sans-serif" />'
            .'<mj-text font-size="16px" line-height="1.5" color="#1f2933" />'
            .'<mj-section padding="0px" />'
            .'</mj-attributes>';
    }

    /** @param array<string, mixed> $block */
    private function section(array $block): string
    {
        $attrs = (array) ($block['attrs'] ?? []);

        $parts = ['padding="'.$this->padding($attrs['padding'] ?? '16px 24px').'"'];

        if (isset($attrs['background'])) {
            $parts[] = 'background-color="'.$this->colour($attrs['background']).'"';
        } elseif ($this->style('content_background') !== null) {
            $parts[] = 'background-color="'.$this->colour($this->style('content_background')).'"';
        }

        if (($attrs['full_width'] ?? false) === true) {
            $parts[] = 'full-width="full-width"';
        }

        $parts = array_merge($parts, $this->decorations($attrs));

        $children = '';

        foreach ((array) ($block['children'] ?? []) as $column) {
            $children .= $this->column(is_array($column) ? $column : []);
        }

        // ÜRES szakasz: az MJML üres `mj-section`-t nem szeret, és a kimenetben egy
        // értelmetlen, láthatatlan táblázat lenne. Egy oszlopot adunk, hogy a szerkesztőben
        // hozzáadott, még üres szakasz is renderelhető legyen.
        if ($children === '') {
            $children = '<mj-column></mj-column>';
        }

        return '<mj-section '.implode(' ', $parts).'>'.$children.'</mj-section>';
    }

    /** @param array<string, mixed> $block */
    private function column(array $block): string
    {
        $attrs = (array) ($block['attrs'] ?? []);

        $parts = [];

        if (isset($attrs['width'])) {
            $parts[] = 'width="'.$this->dimension($attrs['width']).'"';
        }

        if (isset($attrs['background'])) {
            $parts[] = 'background-color="'.$this->colour($attrs['background']).'"';
        }

        if (isset($attrs['padding'])) {
            $parts[] = 'padding="'.$this->padding($attrs['padding']).'"';
        }

        $children = '';

        foreach ((array) ($block['children'] ?? []) as $child) {
            $children .= $this->content(is_array($child) ? $child : []);
        }

        return '<mj-column'.($parts === [] ? '' : ' '.implode(' ', $parts)).'>'.$children.'</mj-column>';
    }

    /** @param array<string, mixed> $block */
    private function content(array $block): string
    {
        $attrs = (array) ($block['attrs'] ?? []);
        $mjml = $this->element($block);
        $decorations = $this->decorations($attrs);

        // A FELTÉTEL és a mobil-rejtés a BLOKK egészére vonatkozik, akkor is, ha a blokk
        // több MJML-elemből áll (termék, videó). Ezért egy csoport-burok, nem attribútum.
        if ($decorations === [] || $mjml === '') {
            return $mjml;
        }

        return '<mj-vm-group '.implode(' ', $decorations).'>'.$mjml.'</mj-vm-group>';
    }

    /**
     * A feltétel és a mobil-rejtés jelölői.
     *
     * @param  array<string, mixed>  $attrs
     * @return list<string>
     */
    private function decorations(array $attrs): array
    {
        $parts = [];

        if (isset($attrs['condition']) && is_array($attrs['condition'])
            && ConditionalContent::violation($attrs['condition']) === null) {
            $parts[] = 'vm-if="'.ConditionalContent::encode($attrs['condition']).'"';
        }

        if (($attrs['hide_on_mobile'] ?? false) === true) {
            $parts[] = 'css-class="vm-hide-mobile"';
        }

        return $parts;
    }

    /** @param array<string, mixed> $block */
    private function element(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $attrs = (array) ($block['attrs'] ?? []);

        return match ($type) {
            'heading' => $this->heading($attrs),
            'text' => '<mj-text'.$this->textAttrs($attrs).'>'.$this->rich($attrs['content'] ?? '').'</mj-text>',
            'image' => $this->image($attrs),
            'button' => $this->button($attrs),
            'divider' => '<mj-divider border-color="'.$this->colour($attrs['color'] ?? '#e4e7eb')
                .'" padding="'.$this->padding($attrs['padding'] ?? '8px 0px').'" />',
            'spacer' => '<mj-spacer height="'.$this->dimension($attrs['height'] ?? 16, 'px').'" />',
            // A LEIRATKOZÓ BLOKK helyőrzőt ír: a valódi URL címzettenként más, és a küldés
            // pillanatában áll elő (a `Message::preferenceCenterUrl()` és a `/u/` token).
            // A sablonban tárolt konkrét URL egy másik ember tokenjét vinné.
            'unsubscribe' => '<mj-text'.$this->textAttrs($attrs).'>'
                .'<a href="{{ unsubscribe_url }}">'.$this->text($attrs['content'] ?? 'Leiratkozás').'</a>'
                // SZÁMOS entitás, nem `&middot;`: az MJML-t XML-ként dolgozzuk fel, és a
                // HTML-nevű entitások (`&middot;`, `&nbsp;`) az XML-ben NEM definiáltak. Egy
                // ilyen karakter a teljes parse-t elbuktatta, és a levél ÜRESEN jött ki —
                // csendben, mert a fordító a hibát elnyelte.
                .' &#183; <a href="{{ preferences_url }}">Levélbeállítások</a>'
                .'</mj-text>',
            'social' => $this->social($attrs),
            /*
             | A NYERS HTML az ALLOWLIST-SANITIZEREN megy át, nem „szűk kivételként".
             |
             | Korábban itt a tartalom érintetlenül került a CDATA-ba, mert „a validátor
             | kizárta a `<script>`-et és az eseménykezelőket". Mérve NEM zárta ki:
             | `<img/onerror=…>`, `<base href=…>`, `<form action="http://…">`,
             | `<iframe srcdoc=…>` és a `</td></tr></table>` elrendezés-kitörés mind
             | nyersen benne volt a fordított HTML-ben. Egy validátor amúgy sem lehet a
             | garancia: ő a MENTÉST nézi, a kimenetért ez a sor felel.
             |
             | CDATA marad: a megtisztított tartalom is tartalmazhat HTML-nevű entitást
             | (`&nbsp;`), ami az XML-parse-t elbuktatná — és a levél ÜRESEN jönne ki.
             */
            'html' => '<mj-raw><![CDATA['
                .str_replace(']]>', ']]]]><![CDATA[>', $this->sanitizer->block((string) ($attrs['content'] ?? '')))
                .']]></mj-raw>',
            'logo' => $this->image($attrs + ['width' => 120]),
            'video' => $this->video($attrs),
            'product' => $this->product($attrs),
            'survey' => $this->survey($attrs),
            'footer' => $this->footer($attrs),
            default => '',
        };
    }

    /**
     * VIDEÓ: bélyegkép a videó linkjével + lejátszás-sor.
     *
     * A levélkliensek nem játszanak le videót; a de facto minta a kattintható bélyegkép. A
     * lejátszás-jel (`&#9654;`) SZÖVEG, nem a képre rajzolt overlay: képblokkolásnál is
     * látszik, hogy itt egy videó van.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function video(array $attrs): string
    {
        $image = $this->image([
            'src' => $attrs['thumbnail'] ?? '',
            'alt' => $attrs['alt'] ?? '',
            'href' => $attrs['href'] ?? '',
            'width' => $attrs['width'] ?? null,
            'align' => $attrs['align'] ?? 'center',
        ]);

        $label = $this->plain($attrs['play_label'] ?? 'Lejátszás');

        return $image.'<mj-text align="'.$this->keyword($attrs['align'] ?? 'center', ['left', 'center', 'right'], 'center').'">'
            .'<a href="'.$this->url($attrs['href'] ?? '').'">&#9654; '.$label.'</a></mj-text>';
    }

    /**
     * TERMÉK: kép, név, leírás, ár (a régi ár áthúzva) és gomb.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function product(array $attrs): string
    {
        $align = $this->keyword($attrs['align'] ?? 'center', ['left', 'center', 'right'], 'center');
        $out = '';

        if (isset($attrs['image']) && is_string($attrs['image']) && $attrs['image'] !== '') {
            $out .= $this->image([
                'src' => $attrs['image'],
                'alt' => $attrs['alt'] ?? $attrs['name'] ?? '',
                'href' => $attrs['href'] ?? '',
                'align' => $align,
            ]);
        }

        $out .= '<mj-text font-size="18px" font-weight="700" align="'.$align.'"'.$this->textColour([]).'>'
            .$this->text($attrs['name'] ?? '').'</mj-text>';

        if (isset($attrs['description']) && trim((string) $attrs['description']) !== '') {
            $out .= '<mj-text align="'.$align.'"'.$this->textColour([]).'>'.$this->text($attrs['description']).'</mj-text>';
        }

        if (isset($attrs['price']) && trim((string) $attrs['price']) !== '') {
            $old = isset($attrs['old_price']) && trim((string) $attrs['old_price']) !== ''
                ? '<s>'.$this->plain($attrs['old_price']).'</s> '
                : '';

            $out .= '<mj-text font-size="18px" align="'.$align.'"'.$this->textColour([]).'>'.$old
                .'<strong>'.$this->plain($attrs['price']).'</strong></mj-text>';
        }

        return $out.$this->button([
            'content' => $attrs['button_label'] ?? 'Megnézem',
            'href' => $attrs['href'] ?? '',
            'background' => $attrs['button_background'] ?? null,
            'color' => $attrs['button_color'] ?? null,
            'align' => $align,
        ]);
    }

    /**
     * KÉRDŐÍV: a kérdés és az egykattintásos válasz-linkek.
     *
     * A válasz a LINKBEN van (`answer=<n>`, 1-től), tehát a címzettnek nem kell űrlapot
     * kitöltenie — egy kattintás. A sorszám (nem a felirat) azért, mert a felirat
     * fordítható és szerkeszthető, a feldolgozó oldalnak viszont stabil kulcs kell.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function survey(array $attrs): string
    {
        $align = $this->keyword($attrs['align'] ?? 'center', ['left', 'center', 'right'], 'center');
        $background = $this->colour($attrs['button_background'] ?? $this->style('button_background') ?? '#1f6feb');
        $colour = $this->colour($attrs['button_color'] ?? $this->style('button_color') ?? '#ffffff');

        $links = [];

        foreach (array_values((array) ($attrs['answers'] ?? [])) as $index => $answer) {
            $links[] = '<a href="'.$this->url(self::answerUrl((string) ($attrs['base_url'] ?? ''), $index + 1)).'"'
                .' style="display:inline-block;margin:4px;padding:10px 18px;border-radius:999px;'
                .'background-color:'.$background.';color:'.$colour.';text-decoration:none;font-weight:700;">'
                .$this->plain($answer).'</a>';
        }

        return '<mj-text font-size="18px" font-weight="700" align="'.$align.'"'.$this->colourAttr($attrs).'>'
            .$this->text($attrs['question'] ?? '').'</mj-text>'
            .'<mj-text align="'.$align.'">'.implode(' ', $links).'</mj-text>';
    }

    /** A kérdőív-válasz URL-je: a `base_url` + `answer=<n>`. A merge-tag alak érintetlen marad. */
    public static function answerUrl(string $base, int $answer): string
    {
        $base = trim($base);

        if ($base === '' || UrlPolicy::violation($base) !== null) {
            return '';
        }

        // MERGE-TAG alap (`{{ survey_url }}`): a paraméter a behelyettesítés UTÁN fűződik — a
        // `?` vagy `&` választás ott nem dönthető el, ezért a merge-tag alakhoz `?` kerül.
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.'answer='.$answer;
    }

    /**
     * LÁBLÉC a kötelező elemekkel: cég, postai cím, opcionális szöveg, leiratkozó és
     * beállítás-link. A két link NEM kapcsolható ki — ez a blokk lényege.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function footer(array $attrs): string
    {
        $lines = [];

        if (isset($attrs['company']) && trim((string) $attrs['company']) !== '') {
            $lines[] = '<strong>'.$this->plain($attrs['company']).'</strong>';
        }

        $lines[] = $this->multiline($attrs['address'] ?? '');

        if (isset($attrs['content']) && trim((string) $attrs['content']) !== '') {
            $lines[] = $this->text($attrs['content']);
        }

        $link = isset($attrs['link_color']) ? ' style="color:'.$this->colour($attrs['link_color']).';"' : '';

        $lines[] = '<a href="{{ unsubscribe_url }}"'.$link.'>'.$this->plain($attrs['unsubscribe_label'] ?? 'Leiratkozás').'</a>'
            .' &#183; <a href="{{ preferences_url }}"'.$link.'>'.$this->plain($attrs['preferences_label'] ?? 'Levélbeállítások').'</a>';

        $parts = ' align="'.$this->keyword($attrs['align'] ?? 'center', ['left', 'center', 'right'], 'center').'"'
            .' font-size="'.(int) ($attrs['font_size'] ?? 12).'px"'
            .' color="'.$this->colour($attrs['color'] ?? '#52606d').'"';

        return '<mj-text'.$parts.'>'.implode('<br/>', $lines).'</mj-text>';
    }

    /** Többsoros sima szöveg (postai cím): a sortörés `<br/>`, minden más escape-elve. */
    private function multiline(mixed $value): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $value)) ?: [];

        return implode('<br/>', array_map(fn ($line) => $this->plain(trim($line)), $lines));
    }

    /** @param array<string, mixed> $attrs */
    private function heading(array $attrs): string
    {
        $level = max(1, min(4, (int) ($attrs['level'] ?? 2)));
        $size = (int) ($attrs['font_size'] ?? [0, 30, 24, 20, 18][$level]);

        $colour = $this->colourAttr($attrs);

        if ($colour === '' && $this->style('heading_color') !== null) {
            $colour = ' color="'.$this->colour($this->style('heading_color')).'"';
        }

        return '<mj-text font-size="'.$size.'px" font-weight="700"'
            .$this->align($attrs).$colour.$this->paddingAttr($attrs)
            .'>'.$this->text($attrs['content'] ?? '').'</mj-text>';
    }

    /** @param array<string, mixed> $attrs */
    private function image(array $attrs): string
    {
        $parts = ['src="'.$this->url($attrs['src'] ?? '').'"'];

        // AZ ALT-SZÖVEG nem opcionális jó szokás: a levélkliensek TÖBBSÉGE alapból
        // blokkolja a képeket, tehát az alt az, amit a címzett először látni fog. Ha nincs,
        // üresen hagyjuk (nem a fájlnevet írjuk be) — de a szerkesztő jelzi.
        // AZ `alt` ATTRIBÚTUM: itt a HTML nem rendelődik, tehát `plain()`. `text()`-tel egy
        // `<strong>` a `alt="…"` belsejébe írna tageket, és kitörhetne az attribútumból.
        $parts[] = 'alt="'.$this->plain($attrs['alt'] ?? '').'"';

        if (isset($attrs['href'])) {
            $parts[] = 'href="'.$this->url($attrs['href']).'"';
        }

        if (isset($attrs['width'])) {
            $parts[] = 'width="'.$this->dimension($attrs['width'], 'px').'"';
        }

        if (isset($attrs['align'])) {
            $parts[] = 'align="'.$this->keyword($attrs['align'], ['left', 'center', 'right'], 'center').'"';
        }

        return '<mj-image '.implode(' ', $parts).' />';
    }

    /** @param array<string, mixed> $attrs */
    private function button(array $attrs): string
    {
        $parts = [
            'href="'.$this->url($attrs['href'] ?? '').'"',
            'background-color="'.$this->colour($attrs['background'] ?? $this->style('button_background') ?? '#1f6feb').'"',
            'color="'.$this->colour($attrs['color'] ?? $this->style('button_color') ?? '#ffffff').'"',
            'border-radius="'.$this->dimension($attrs['border_radius'] ?? $this->style('button_radius') ?? 6, 'px').'"',
            'align="'.$this->keyword($attrs['align'] ?? 'center', ['left', 'center', 'right'], 'center').'"',
            'padding="16px 24px"',
        ];

        return '<mj-button '.implode(' ', $parts).'>'.$this->text($attrs['content'] ?? '').'</mj-button>';
    }

    /** @param array<string, mixed> $attrs */
    private function social(array $attrs): string
    {
        $size = (int) ($attrs['icon_size'] ?? 24);
        $align = $this->keyword($attrs['align'] ?? 'center', ['left', 'center', 'right'], 'center');

        $elements = '';
        $share = ($attrs['mode'] ?? 'follow') === 'share';

        foreach ((array) ($attrs['networks'] ?? []) as $network) {
            // MEGOSZTÁS (v1.1): a hálózat neve elég, a linket a `share_url`-ből MI építjük.
            if ($share) {
                $name = is_array($network) ? (string) ($network['name'] ?? '') : (string) $network;

                if (! in_array($name, BlockSchema::SHARE_NETWORKS, true)) {
                    continue;
                }

                $elements .= '<mj-social-element name="'.$name.'" href="'
                    .$this->url(self::shareUrl($name, (string) ($attrs['share_url'] ?? ''))).'" />';

                continue;
            }

            if (! is_array($network)) {
                continue;
            }

            $name = $this->keyword(
                $network['name'] ?? '',
                BlockSchema::SOCIAL_NETWORKS,
                'facebook'
            );

            $elements .= '<mj-social-element name="'.$name.'" href="'.$this->url($network['href'] ?? '').'" />';
        }

        return '<mj-social icon-size="'.$size.'px" mode="horizontal" align="'.$align.'">'.$elements.'</mj-social>';
    }

    /** A hálózat nyilvános megosztó URL-je. */
    public static function shareUrl(string $network, string $target): string
    {
        $target = trim($target);

        if ($target === '' || UrlPolicy::violation($target) !== null) {
            return '';
        }

        $encoded = rawurlencode($target);

        return match ($network) {
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u='.$encoded,
            'x', 'twitter' => 'https://twitter.com/intent/tweet?url='.$encoded,
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url='.$encoded,
            default => '',
        };
    }

    /** @param array<string, mixed> $attrs */
    private function textAttrs(array $attrs): string
    {
        $parts = $this->align($attrs).$this->textColour($attrs).$this->paddingAttr($attrs);

        if (isset($attrs['font_size'])) {
            $parts .= ' font-size="'.(int) $attrs['font_size'].'px"';
        } elseif ($this->style('font_size') !== null) {
            $parts .= ' font-size="'.(int) $this->style('font_size').'px"';
        }

        if (isset($attrs['line_height'])) {
            $parts .= ' line-height="'.(float) $attrs['line_height'].'"';
        }

        return $parts;
    }

    /** @param array<string, mixed> $attrs */
    private function align(array $attrs): string
    {
        return isset($attrs['align'])
            ? ' align="'.$this->keyword($attrs['align'], ['left', 'center', 'right', 'justify'], 'left').'"'
            : '';
    }

    /** @param array<string, mixed> $attrs */
    private function colourAttr(array $attrs): string
    {
        return isset($attrs['color']) ? ' color="'.$this->colour($attrs['color']).'"' : '';
    }

    /**
     * A szöveg színe: a blokké, különben a globális stílusé, különben nincs (a fordító
     * alapértéke).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function textColour(array $attrs): string
    {
        $colour = $this->colourAttr($attrs);

        if ($colour === '' && $this->style('text_color') !== null) {
            return ' color="'.$this->colour($this->style('text_color')).'"';
        }

        return $colour;
    }

    /** @param array<string, mixed> $attrs */
    private function paddingAttr(array $attrs): string
    {
        return isset($attrs['padding']) ? ' padding="'.$this->padding($attrs['padding']).'"' : '';
    }

    /**
     * A `content` KONTRAKTUSA: szűk inline HTML.
     *
     * Ez korábban ELLENTMONDÁSOS volt, és a levélben látszott. Az MJML-író
     * `htmlspecialchars`-olt, a `PlainTextWriter` viszont HTML-ként értelmezte ugyanazt a
     * mezőt (link-kiemelés, `strip_tags`, `html_entity_decode`). A packet saját
     * fixture-ével (`'Ez a <strong>hírlevél</strong>.'`) mérve:
     *   HTML törzs → `Ez a &lt;strong&gt;hírlevél&lt;/strong&gt;.` — a címzett a NYERS TAGET látta
     *   plaintext  → `Ez a hírlevél.`
     * Az 59. kritérium EGY fából kér HTML-t ÉS plaintextet; a kettő mást mondott, és a
     * HTML törzs formázott szövegre használhatatlan volt.
     *
     * A DÖNTÉS: a `content` inline HTML-t tartalmazhat (`<strong>`, `<em>`, `<a>`, `<br>`,
     * …), és mindkét író így kezeli. Az allowlisten kívüli tag KIBONTÓDIK (a szöveg
     * megmarad), a tiltott elem eltűnik — nem escape-elődik látható szemétté.
     *
     * A `{{ nev }}` átmegy: a behelyettesítés a küldés pillanatában történik, és AKKOR
     * escape-elődik az ÉRTÉK (`TemplateRenderer`, A1).
     */
    private function text(mixed $value): string
    {
        return $this->sanitizer->inline((string) $value);
    }

    /**
     * FORMÁZOTT szöveg (v1.1 inline szerkesztő): az inline profil + bekezdés, lista,
     * igazítás. Csak a `text` blokkban — a címsor egy sor marad.
     */
    private function rich(mixed $value): string
    {
        return $this->sanitizer->rich((string) $value);
    }

    /**
     * SIMA SZÖVEG — ott, ahol a HTML nem rendelődik (preheader, `alt`).
     *
     * A merge-tag határolóit itt is visszaállítjuk: escape-elve a behelyettesítés soha nem
     * találná meg, és a címzett `{{ nev }}`-et látna.
     */
    private function plain(mixed $value): string
    {
        $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(['&#123;&#123;', '&#125;&#125;'], ['{{', '}}'], $escaped);
    }

    /**
     * URL egy attribútumban — a SZABÁLYT ITT IS megkérdezzük.
     *
     * „A validátor már kizárta" nem elég: a validátor a mentést nézi, ez a sor a kimenetet
     * írja, és a kettő közé bekerülhet egy nyers query-builder-es vagy `forceFill` úton
     * írt fa is. Ami nem megy át a `UrlPolicy`-n, az ÜRES `href`-et kap — a link nem
     * navigál, de nem is visz `javascript:`-re.
     */
    private function url(mixed $value): string
    {
        $value = (string) $value;

        if (! UrlPolicy::isAllowed($value)) {
            $value = '';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function colour(mixed $value): string
    {
        $value = trim((string) $value);

        // CSAK hex vagy nevesített alap-szín. Egy szabad CSS-érték (`url(...)`,
        // `expression(...)`) a levélklienseknél is és a webes előnézetben is felület.
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1
            || preg_match('/^[a-z]{3,20}$/', $value) === 1
            ? $value
            : '#1f2933';
    }

    private function padding(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^(\d{1,3}px\s*){1,4}$/', $value) === 1 ? $value : '16px 24px';
    }

    private function dimension(mixed $value, string $unit = ''): string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{1,4}(px|%)$/', $value) === 1) {
            return $value;
        }

        return is_numeric($value) ? ((int) $value).$unit : '100%';
    }

    /** @param list<string> $allowed */
    private function keyword(mixed $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
