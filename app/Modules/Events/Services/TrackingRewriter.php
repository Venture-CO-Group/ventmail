<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

/**
 * A nyitás- és klikk-követés BEÉPÍTÉSE a levéltörzsbe (23. és 26. kritérium).
 *
 * A trace lánca a `delivered`-del nem ér véget: a support és az ügyfél a `opened`/`clicked`
 * eseményt is látni akarja, a tag-statisztika pedig ezekből számol arányt. Ezek az események
 * viszont nem keletkeznek maguktól — a levélbe bele kell írni azt, ami majd meghívja őket.
 *
 * A beavatkozás MINIMÁLIS, és ez szándékos: minden extra átírás egy újabb esély arra, hogy a
 * levél másképp jelenjen meg, mint ahogy az ügyfél megírta.
 *
 * Az `o:tracking-opens` / `o:tracking-clicks` vezérlők KIKAPCSOLHATJÁK (Mailgun-paritás), és
 * ez nem csak kényelmi kérdés: tranzakciós levélben (jelszó-visszaállítás, számla) a
 * nyomkövetés gyakran jogi vagy adatvédelmi okból nem kívánatos.
 */
final class TrackingRewriter
{
    public function __construct(private readonly TrackingTokens $tokens) {}

    public function rewrite(
        string $html,
        int $workspaceId,
        string $messageUuid,
        string $recipient,
        bool $opens,
        bool $clicks,
    ): string {
        if ($html === '') {
            return $html;
        }

        if ($clicks) {
            $html = $this->rewriteLinks($html, $workspaceId, $messageUuid, $recipient);
        }

        if ($opens) {
            $html = $this->injectPixel($html, $workspaceId, $messageUuid, $recipient);
        }

        return $html;
    }

    private function rewriteLinks(string $html, int $workspaceId, string $messageUuid, string $recipient): string
    {
        return (string) preg_replace_callback(
            '/href\s*=\s*(["\'])(.*?)\1/i',
            function (array $match) use ($workspaceId, $messageUuid, $recipient): string {
                $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (! $this->trackable($url)) {
                    return $match[0];
                }

                $token = $this->tokens->issue($workspaceId, $messageUuid, $recipient, $url);

                return 'href='.$match[1].e($this->url('c', $token)).$match[1];
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
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        return ! str_contains(strtolower($url), 'unsubscribe');
    }

    private function injectPixel(string $html, int $workspaceId, string $messageUuid, string $recipient): string
    {
        $token = $this->tokens->issue($workspaceId, $messageUuid, $recipient);

        // Nulla méretű, üres `alt`: a képernyőolvasó nem hirdeti ki, a levélkliens nem
        // rajzol helyet neki.
        $pixel = '<img src="'.e($this->url('o', $token)).'" width="1" height="1" alt="" style="display:none">';

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('#</body>#i', $pixel.'</body>', $html, 1);
        }

        return $html.$pixel;
    }

    private function url(string $kind, string $token): string
    {
        $base = rtrim((string) (config('sending.tracking_base_url') ?: config('app.url')), '/');

        return $base.'/t/'.$kind.'/'.$token;
    }
}
