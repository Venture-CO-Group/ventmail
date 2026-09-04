<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Modules\Events\Http\Controllers\TrackingController;

/**
 * A nyitás- és klikk-követés BEÉPÍTÉSE a levéltörzsbe (23. és 26. kritérium).
 *
 * A trace lánca a `delivered`-del nem ér véget: a support és az ügyfél a `opened`/`clicked`
 * eseményt is látni akarja, a tag-statisztika pedig ezekből számol arányt. Ezek az események
 * viszont nem keletkeznek maguktól — a levélbe bele kell írni azt, ami majd meghívja őket.
 *
 * A beavatkozás MINIMÁLIS, és ez szándékos: minden extra átírás egy újabb esély arra, hogy a
 * levél másképp jelenjen meg, mint ahogy az ügyfél megírta. Ezért az átírás KIZÁRÓLAG `<a>`
 * elemek `href`-jét érinti — a dokumentum egészére engedett csere egy HTML-példakódot mutató
 * hírlevélben a látható szöveget is átírná.
 *
 * Az `o:tracking-opens` / `o:tracking-clicks` vezérlők KIKAPCSOLHATJÁK (Mailgun-paritás), és
 * ez nem csak kényelmi kérdés: tranzakciós levélben (jelszó-visszaállítás, számla) a
 * nyomkövetés gyakran jogi vagy adatvédelmi okból nem kívánatos.
 */
final class TrackingRewriter
{
    public function __construct(private readonly TrackingTokens $tokens) {}

    /**
     * @param  string  $baseUrl  A TENANT SAJÁT nyomkövetési hosztja (`email.<domain>`). Üres
     *                           string esetén NINCS nyomkövetés — lásd `TrackingBaseUrl`.
     */
    public function rewrite(
        string $html,
        int $workspaceId,
        string $messageUuid,
        string $recipient,
        string $baseUrl,
        bool $opens,
        bool $clicks,
    ): string {
        if ($html === '' || $baseUrl === '') {
            return $html;
        }

        $host = (string) parse_url($baseUrl, PHP_URL_HOST);

        if ($host === '') {
            return $html;
        }

        if ($clicks) {
            $html = $this->rewriteLinks($html, $workspaceId, $messageUuid, $recipient, $baseUrl, $host);
        }

        if ($opens) {
            $html = $this->injectPixel($html, $workspaceId, $messageUuid, $recipient, $baseUrl, $host);
        }

        return $html;
    }

    /**
     * Az átírás `<a …>` NYITÓ TAGEKRE szorítva.
     *
     * A `href="…"` minta a dokumentum egészén cserélve olyan helyeket is eltalál, ahol a
     * szöveg csak VÉLETLENÜL néz ki linknek: egy `<pre>` blokkban mutatott HTML-példa, egy
     * `alt` attribútum szövege, vagy egy `data-*` attribútum. Ezek egyike sem link, és
     * átírva az ügyfél levele MÁSKÉPP jelenne meg, mint ahogy megírta.
     */
    private function rewriteLinks(string $html, int $workspaceId, string $messageUuid, string $recipient, string $baseUrl, string $host): string
    {
        return (string) preg_replace_callback(
            '/<a\b[^>]*>/i',
            function (array $anchor) use ($workspaceId, $messageUuid, $recipient, $baseUrl, $host): string {
                // Idézőjeles ÉS idézőjel nélküli alak: a kézzel írt HTML és sok sablonmotor
                // kimenete `<a href=https://…>` formában jön, és az csendben kimaradna a
                // klikk-statisztikából.
                return (string) preg_replace_callback(
                    '/(\bhref\s*=\s*)(?:(["\'])(.*?)\2|([^\s"\'>]+))/i',
                    function (array $match) use ($workspaceId, $messageUuid, $recipient, $baseUrl, $host): string {
                        $quote = $match[2] ?? '"';
                        $raw = ($match[2] ?? '') !== '' ? $match[3] : $match[4];

                        $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                        if (! $this->trackable($url)) {
                            return $match[0];
                        }

                        $token = $this->tokens->issue($workspaceId, $messageUuid, $recipient, $host, $url);

                        return $match[1].$quote.e($this->url($baseUrl, 'c', $token)).$quote;
                    },
                    $anchor[0],
                );
            },
            $html,
        );
    }

    /**
     * Követhető-e ez a link?
     *
     * A `mailto:`, `tel:` és a horgony nem kattintás-esemény. A `javascript:` pedig eleve nem
     * megy át a tartalom-szűrőnkön — itt sem csomagoljuk be, mert a becsomagolt alak
     * elrejtené a szűrő elől.
     *
     * A LEIRATKOZÁSI linket SZÁNDÉKOSAN nem írjuk át: ha a nyomkövetés bármikor elromlana, a
     * leiratkozásnak akkor is működnie kell. Egy nem működő leiratkozás panaszt szül, a panasz
     * pedig a küldő reputációját rombolja — pont azt, amit védeni akarunk.
     */
    private function trackable(string $url): bool
    {
        // UGYANAZ az ellenőrzés, mint a kibontáskor — itt a BECSOMAGOLÁSKOR. A `href`-et
        // entitás-dekódoljuk, tehát egy `&#13;&#10;` valódi sortöréssé válik: ellenőrzés
        // nélkül az a `Location` fejlécben külön fejlécsorként jelenne meg.
        if (! TrackingController::safeDestination($url)) {
            return false;
        }

        return ! str_contains(strtolower($url), 'unsubscribe');
    }

    private function injectPixel(string $html, int $workspaceId, string $messageUuid, string $recipient, string $baseUrl, string $host): string
    {
        $token = $this->tokens->issue($workspaceId, $messageUuid, $recipient, $host);

        // Nulla méretű, üres `alt`: a képernyőolvasó nem hirdeti ki, a levélkliens nem
        // rajzol helyet neki.
        $pixel = '<img src="'.e($this->url($baseUrl, 'o', $token)).'" width="1" height="1" alt="" style="display:none">';

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('#</body>#i', $pixel.'</body>', $html, 1);
        }

        return $html.$pixel;
    }

    private function url(string $baseUrl, string $kind, string $token): string
    {
        return rtrim($baseUrl, '/').'/t/'.$kind.'/'.$token;
    }
}
