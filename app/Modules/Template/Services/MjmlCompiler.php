<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

/**
 * MJML → RESZPONZÍV HTML (59. kritérium).
 *
 * ## Miért írjuk mi, és mit vállalunk vele
 *
 * A kanonikus MJML-fordító Node-alkalmazás. Meghívása a küldési útból külső folyamatot tenne
 * a kérés kritikus útjára, Node-futtatókörnyezetet a PHP-konténerbe, és — a legrosszabb —
 * egy olyan verziófüggést, ami a levél KINÉZETÉT csendben megváltoztathatja két deploy
 * között. Egy kampány kinézete nem függhet egy fordító patch-verziójától.
 *
 * Ezért a fordító a mienk, és SZŰK: pontosan azt a komponens-készletet érti, amit a
 * `MjmlWriter` előállít. Ez vállalt korlát — nem általános MJML-fordító, és nem is annak
 * adja ki magát.
 *
 * ## Amit az e-mail-HTML megkíván
 *
 * A levélkliensek HTML-motorja húsz évvel le van maradva: nincs flexbox, nincs grid, a
 * Gmail levágja a `<style>` blokk egy részét, az Outlook Wordrel rendel. Ezért:
 *
 *  - **TÁBLÁZATOS elrendezés.** Nem nosztalgia: ez az egyetlen, amit minden kliens ugyanúgy
 *    ért. A reszponzivitás egy `@media` szabállyal jön, amit a támogató kliensek
 *    alkalmaznak, a többi pedig a táblázat-alapú, egy hasábos alapesetet mutatja.
 *  - **INLINE stílusok.** Ami nem inline, azt a Gmail eldobhatja. A `<style>` blokk így
 *    csak a `@media` szabályt tartalmazza — ami inline nem is fejezhető ki.
 *  - **`role="presentation"`.** A képernyőolvasó különben elrendezési táblázatokat olvas
 *    fel adattáblázatként.
 */
final class MjmlCompiler
{
    /** A levél szélessége. 600 px az e-mail-világ de facto szabványa. */
    private const WIDTH = 600;

    public function compile(string $mjml): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        // A `LIBXML_NONET`: a bemenet a saját írónk kimenete, de a DTD-feldolgozás
        // kikapcsolása így is helyes — nincs okunk hálózatra menni egy sablon fordítása közben.
        /*
         | AZ XML-DEKLARÁCIÓ KELL, és ez nem formalitás.
         |
         | Nélküle a libxml a bemenetet latin-1-ként értelmezi, és a `saveHTML` az ékezetes
         | karaktereket SZÁMOS ENTITÁSSÁ írja át (`V&#xE1;s&#xE1;rl&#xF3;`). A HTML-részben ez
         | még helyesen jelenik meg, de a MERGE-TAG FALLBACK szövege így entitásokkal kerül a
         | sablonba — és a `text/plain` ág nem dekódol entitást, tehát a címzett a
         | plaintext levélben `V&#xE1;s&#xE1;rl&#xF3;`-t látna.
         */
        $loaded = $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>'.$mjml,
            LIBXML_NONET | LIBXML_NOBLANKS
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        /*
         | A PARSE-HIBA HANGOS, nem csendes üres levél.
         |
         | Ez mérve történt meg: egy `&middot;` entitás (HTML-ben definiált, XML-ben NEM) a
         | leiratkozó blokkban elbuktatta a parse-t, és a fordító egy ÉRVÉNYES, de TELJESEN
         | ÜRES HTML-dokumentumot adott vissza — a hibát semmi nem jelezte. A mentés
         | „sikeres" volt, a kampány elindult, és a címzettek üres levelet kaptak.
         |
         | A csendes üres kimenet a legrosszabb hibaosztály ebben a rendszerben, és ez a
         | packet már kétszer futott bele (A6: a kapu engedte, a levélbe nem jutott ki).
         */
        if ($loaded === false || $document->documentElement === null) {
            throw new RuntimeException(sprintf(
                'Az MJML nem értelmezhető: %s',
                implode('; ', array_map(static fn ($error) => trim((string) $error->message), $errors)) ?: 'ismeretlen hiba'
            ));
        }

        $preview = '';

        /*
         | A SÁVOK. Egy `full-width` szekció KILÉP a 600 pixeles középső táblából — ha nem
         | lép ki, a beállítás nem hat.
         |
         | Mérve pontosan ez volt a helyzet: a `full_width: true` és a nélküli fa fordított
         | HTML-je BITRE AZONOS volt, miközben a validátor engedte a mezőt és az író ki is
         | írta a `full-width="full-width"`-et. Ez az a „beállított valamit, ami nem hat"
         | hibaosztály, ami miatt a validátorban az ismeretlen-mező szabály egyáltalán van.
         |
         | @var list<array{full: bool, rows: string}>
         */
        $bands = [];

        foreach ($this->children($document->documentElement) as $node) {
            if ($node->nodeName === 'mj-head') {
                foreach ($this->children($node) as $head) {
                    if ($head->nodeName === 'mj-preview') {
                        $preview = (string) $head->textContent;
                    }
                }
            }

            if ($node->nodeName === 'mj-body') {
                foreach ($this->children($node) as $section) {
                    $rows = $this->section($section);

                    if ($rows === '') {
                        continue;
                    }

                    $full = $section instanceof DOMElement
                        && $section->getAttribute('full-width') !== '';

                    // A SZOMSZÉDOS, EGYFORMA szekciók egy sávba kerülnek: minden szekciónak
                    // saját táblát adni feleslegesen sokat hizlalna a levélen, és az
                    // Outlook a sok egymás utáni táblát rosszul is tördeli.
                    $lastIndex = count($bands) - 1;

                    if ($lastIndex >= 0 && $bands[$lastIndex]['full'] === $full) {
                        $bands[$lastIndex]['rows'] .= $rows;

                        continue;
                    }

                    $bands[] = ['full' => $full, 'rows' => $rows];
                }
            }
        }

        return MergeTag::restore($this->document($bands, $preview));
    }

    /** @param list<array{full: bool, rows: string}> $bands */
    private function document(array $bands, string $preview): string
    {
        // A PREHEADER rejtett szövegként: a postafiók-lista olvassa, a levél törzsében nem
        // látszik. A `mso-hide` az Outlookot is lefedi.
        $hidden = $preview === '' ? '' : sprintf(
            '<div style="display:none;font-size:1px;color:#ffffff;line-height:1px;'
            .'max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">%s</div>',
            htmlspecialchars($preview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );

        return '<!doctype html>'
            .'<html lang="hu" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
            .'<head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="x-apple-disable-message-reformatting">'
            // A `@media` az EGYETLEN dolog, ami inline nem fejezhető ki: a hasábok mobilon
            // egymás alá csúsznak. Ha egy kliens eldobja, a táblázat-alap marad — az
            // egyhasábos nézet minden kliensben működik.
            .'<style>@media only screen and (max-width:620px){'
            .'.vm-col{display:block !important;width:100% !important;max-width:100% !important}'
            .'.vm-wrap{width:100% !important}'
            .'}</style>'
            .'</head>'
            .'<body style="margin:0;padding:0;background-color:#f5f7fa;">'
            .$hidden
            .'<table role="presentation" class="vm-wrap" width="100%" cellpadding="0" cellspacing="0" border="0" '
            .'style="background-color:#f5f7fa;">'
            .$this->bands($bands)
            .'</table>'
            .'</body></html>';
    }

    /**
     * A SÁVOK kirajzolása a külső, 100%-os táblába.
     *
     * A NORMÁL sáv egy középre igazított, `WIDTH` pixeles belső táblát kap — ez a levél
     * olvasható szélessége. A `full-width` sáv sorai KÖZVETLENÜL a külső táblába kerülnek,
     * tehát a háttere a teljes szélességet kitölti.
     *
     * @param  list<array{full: bool, rows: string}>  $bands
     */
    private function bands(array $bands): string
    {
        $html = '';

        foreach ($bands as $band) {
            if ($band['full']) {
                $html .= '<tr><td align="center" style="padding:0;">'
                    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                    .'style="width:100%;">'
                    .$band['rows']
                    .'</table>'
                    .'</td></tr>';

                continue;
            }

            $html .= '<tr><td align="center" style="padding:0;">'
                .'<table role="presentation" class="vm-wrap" width="'.self::WIDTH.'" cellpadding="0" cellspacing="0" '
                .'border="0" style="width:'.self::WIDTH.'px;max-width:'.self::WIDTH.'px;">'
                .$band['rows']
                .'</table>'
                .'</td></tr>';
        }

        return $html;
    }

    private function section(DOMNode $section): string
    {
        if (! $section instanceof DOMElement || $section->nodeName !== 'mj-section') {
            return '';
        }

        $padding = $section->getAttribute('padding') ?: '16px 24px';
        $background = $section->getAttribute('background-color');

        $columns = [];

        foreach ($this->children($section) as $column) {
            if ($column instanceof DOMElement && $column->nodeName === 'mj-column') {
                $columns[] = $column;
            }
        }

        $count = max(1, count($columns));
        $cells = '';

        foreach ($columns as $column) {
            $width = $column->getAttribute('width') ?: round(100 / $count, 4).'%';
            $cellBackground = $column->getAttribute('background-color');
            $cellPadding = $column->getAttribute('padding') ?: '0px';

            $inner = '';

            foreach ($this->children($column) as $child) {
                $inner .= $this->content($child);
            }

            // A `vm-col` osztály a `@media` szabály fogantyúja: mobilon `display:block`.
            $cells .= sprintf(
                '<td class="vm-col" valign="top" width="%s" style="width:%s;padding:%s;%s">%s</td>',
                htmlspecialchars($width, ENT_QUOTES),
                htmlspecialchars($width, ENT_QUOTES),
                htmlspecialchars($cellPadding, ENT_QUOTES),
                $cellBackground === '' ? '' : 'background-color:'.htmlspecialchars($cellBackground, ENT_QUOTES).';',
                $inner
            );
        }

        return sprintf(
            '<tr><td style="padding:%s;%s">'
            .'<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0"><tr>%s</tr></table>'
            .'</td></tr>',
            htmlspecialchars($padding, ENT_QUOTES),
            $background === '' ? '' : 'background-color:'.htmlspecialchars($background, ENT_QUOTES).';',
            $cells
        );
    }

    private function content(DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            return '';
        }

        return match ($node->nodeName) {
            'mj-text' => $this->textBlock($node),
            'mj-image' => $this->imageBlock($node),
            'mj-button' => $this->buttonBlock($node),
            'mj-divider' => $this->dividerBlock($node),
            'mj-spacer' => sprintf(
                '<div style="height:%s;line-height:%s;font-size:1px;">&nbsp;</div>',
                htmlspecialchars($node->getAttribute('height') ?: '16px', ENT_QUOTES),
                htmlspecialchars($node->getAttribute('height') ?: '16px', ENT_QUOTES)
            ),
            'mj-social' => $this->socialBlock($node),
            // A `mj-raw` tartalma SZÁNDÉKOSAN nyersen megy át (a validátor kizárta a
            // `<script>`-et és az eseménykezelőket).
            // A `mj-raw` tartalma CDATA-ban jött (lásd `MjmlWriter`): a `textContent` adja
            // vissza nyersen, a `saveHTML` a CDATA-jelölést is kiírná.
            'mj-raw' => (string) $node->textContent,
            default => '',
        };
    }

    private function textBlock(DOMElement $node): string
    {
        $style = 'margin:0;padding:8px 0;'
            .'font-family:Helvetica,Arial,sans-serif;'
            .'font-size:'.($node->getAttribute('font-size') ?: '16px').';'
            .'line-height:'.($node->getAttribute('line-height') ?: '1.5').';'
            .'color:'.($node->getAttribute('color') ?: '#1f2933').';'
            .'text-align:'.($node->getAttribute('align') ?: 'left').';';

        if ($node->getAttribute('font-weight') !== '') {
            $style .= 'font-weight:'.$node->getAttribute('font-weight').';';
        }

        return '<div style="'.htmlspecialchars($style, ENT_QUOTES).'">'.$this->inner($node).'</div>';
    }

    private function imageBlock(DOMElement $node): string
    {
        $width = $node->getAttribute('width');

        $img = sprintf(
            '<img src="%s" alt="%s" %s style="display:block;border:0;outline:none;text-decoration:none;'
            .'max-width:100%%;height:auto;" />',
            htmlspecialchars($node->getAttribute('src'), ENT_QUOTES),
            htmlspecialchars($node->getAttribute('alt'), ENT_QUOTES),
            $width === '' ? '' : 'width="'.htmlspecialchars(rtrim($width, 'px'), ENT_QUOTES).'"'
        );

        if ($node->getAttribute('href') !== '') {
            $img = '<a href="'.htmlspecialchars($node->getAttribute('href'), ENT_QUOTES).'">'.$img.'</a>';
        }

        return sprintf(
            '<div style="padding:8px 0;text-align:%s;">%s</div>',
            htmlspecialchars($node->getAttribute('align') ?: 'center', ENT_QUOTES),
            $img
        );
    }

    private function buttonBlock(DOMElement $node): string
    {
        // A GOMB táblázat, nem `<a>` paddinggal: az Outlook a linken lévő paddinget
        // figyelmen kívül hagyja, és a „gomb" egy aláhúzott szöveggé zsugorodik.
        return sprintf(
            '<div style="padding:8px 0;text-align:%s;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;">'
            .'<tr><td align="center" bgcolor="%s" style="background-color:%s;border-radius:%s;padding:%s;">'
            .'<a href="%s" style="display:inline-block;font-family:Helvetica,Arial,sans-serif;font-size:16px;'
            .'font-weight:700;color:%s;text-decoration:none;">%s</a>'
            .'</td></tr></table></div>',
            htmlspecialchars($node->getAttribute('align') ?: 'center', ENT_QUOTES),
            htmlspecialchars($node->getAttribute('background-color') ?: '#1f6feb', ENT_QUOTES),
            htmlspecialchars($node->getAttribute('background-color') ?: '#1f6feb', ENT_QUOTES),
            htmlspecialchars($node->getAttribute('border-radius') ?: '6px', ENT_QUOTES),
            htmlspecialchars($node->getAttribute('padding') ?: '16px 24px', ENT_QUOTES),
            htmlspecialchars($node->getAttribute('href'), ENT_QUOTES),
            htmlspecialchars($node->getAttribute('color') ?: '#ffffff', ENT_QUOTES),
            $this->inner($node)
        );
    }

    private function dividerBlock(DOMElement $node): string
    {
        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" '
            .'style="padding:%s;"><tr><td style="border-top:1px solid %s;font-size:0;line-height:0;">&nbsp;</td></tr></table>',
            htmlspecialchars($node->getAttribute('padding') ?: '8px 0px', ENT_QUOTES),
            htmlspecialchars($node->getAttribute('border-color') ?: '#e4e7eb', ENT_QUOTES)
        );
    }

    private function socialBlock(DOMElement $node): string
    {
        $cells = '';

        foreach ($this->children($node) as $element) {
            if (! $element instanceof DOMElement || $element->nodeName !== 'mj-social-element') {
                continue;
            }

            $cells .= sprintf(
                '<td style="padding:0 6px;"><a href="%s" style="font-family:Helvetica,Arial,sans-serif;'
                .'font-size:13px;color:#52606d;text-decoration:none;">%s</a></td>',
                htmlspecialchars($element->getAttribute('href'), ENT_QUOTES),
                htmlspecialchars(ucfirst($element->getAttribute('name')), ENT_QUOTES)
            );
        }

        return sprintf(
            '<div style="padding:8px 0;text-align:%s;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;">'
            .'<tr>%s</tr></table></div>',
            htmlspecialchars($node->getAttribute('align') ?: 'center', ENT_QUOTES),
            $cells
        );
    }

    /** A csomópont BELSŐ HTML-je (a gyermek-csomópontokkal együtt). */
    private function inner(DOMElement $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    /** @return list<DOMNode> */
    private function children(?DOMNode $node): array
    {
        if ($node === null) {
            return [];
        }

        $children = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
