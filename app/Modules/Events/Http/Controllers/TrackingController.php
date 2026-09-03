<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Data\TrackingClaim;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A nyitás- és klikk-események FOGADÁSA (23. és 26. kritérium).
 *
 * Ezek az ÜZENET LÁNCÁNAK utolsó szemei: enélkül a trace a `delivered`-nél megáll, és a
 * tag-statisztika open/click aránya sosem tudna mit mutatni.
 *
 * A végpontok NYILVÁNOSAK — a levélkliens hívja őket, nem az ügyfél böngészője —, ezért:
 *  - a token titkosított, tehát a címzett címe nem utazik olvashatóan (proxy, előzmények);
 *  - a hamis token UGYANAZT a választ kapja, mint a jó: a különbség orákulum lenne;
 *  - egy percen belüli ismétlés EGY eseménynek számít, különben egy újratöltő levélkliens
 *    (vagy egy unatkozó támadó) korlátlanul hizlalná az eseménytáblát.
 *
 * FIGYELEM a számok olvasásakor: az Apple Mail adatvédelmi funkciója (MPP) a leveleket a
 * címzett helyett tölti be, tehát a NYITÁS felfelé torzít. A KLIKK az, ami emberi szándékot
 * mér — a felületen is ezt hangsúlyozzuk.
 */
final class TrackingController
{
    /** Egy 1×1 átlátszó GIF — a legkisebb kép, amit minden levélkliens megjelenít. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(Request $request, string $token, TrackingTokens $tokens, WorkspaceContext $context): Response
    {
        $this->record($request, $token, 'opened', $tokens, $context);

        return response(base64_decode(self::PIXEL, true), 200, [
            'Content-Type' => 'image/gif',
            // A böngésző/levélkliens ne cache-elje: a második nyitás is nyitás.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function click(Request $request, string $token, TrackingTokens $tokens, WorkspaceContext $context): RedirectResponse
    {
        $claim = $tokens->parse($token);

        // A cél a TOKENEN BELÜL van, tehát nem írható át kívülről; a séma-ellenőrzés a
        // második zár. A HARMADIK — és a legfontosabb — a hoszt-kötés: ez a token CSAK azon a
        // nyomkövetési hoszton érvényes, amelyikre kiadtuk. Enélkül ugyanez az átirányító az
        // ALKALMAZÁS domainjén is működne, azon az originen, ami a bejelentkezett session
        // cookie-ját szolgálja ki — és bármelyik tenant kiválthatna magának egy tetszőleges
        // célra mutató linket.
        if ($claim === null || ! $this->hostMatches($request, $claim) || ! self::safeDestination($claim->destination)) {
            throw new NotFoundHttpException;
        }

        $this->record($request, $token, 'clicked', $tokens, $context);

        return redirect()->away($claim->destination, 302);
    }

    /**
     * A klikk CÉLJA — a `Location` fejlécbe menő érték.
     *
     * A `^https?://` prefix-ellenőrzés önmagában KEVÉS. Az ügyfél `href`-jét entitás-dekódolás
     * után tesszük a tokenbe, tehát egy `&#13;&#10;` valódi CR/LF-fé válik — és a
     * `https://jo.example/` prefix mögött ott ülhet egy `X-Injektalt: 1` sor, ami a
     * válaszban KÜLÖN FEJLÉCKÉNT jelenne meg. Egy kívülről jövő, vezérlőkaraktereket
     * tartalmazó érték nem érhet el ellenőrzés nélkül egy `Location` fejlécig.
     *
     * Ezért: séma-allowlist, teljes URL-validáció, és MINDEN vezérlőkarakter tiltása —
     * a becsomagoláskor ÉS itt, a kibontáskor is.
     */
    public static function safeDestination(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        // Vezérlőkarakter (CR, LF, TAB, NUL, …) sehol, még százalék-kódolatlanul sem.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        return ($parts['host'] ?? '') !== '';
    }

    /** A token CSAK azon a hoszton érvényes, amelyikre kiadtuk. */
    private function hostMatches(Request $request, TrackingClaim $claim): bool
    {
        return strcasecmp($request->getHost(), $claim->host) === 0;
    }

    private function record(Request $request, string $token, string $type, TrackingTokens $tokens, WorkspaceContext $context): void
    {
        $claim = $tokens->parse($token);

        if ($claim === null || ! $this->hostMatches($request, $claim)) {
            return;
        }

        // Egy percen belüli ismétlés EGY esemény. A pixelt egy levélkliens minden
        // megjelenítéskor újratölti; enélkül egyetlen megnyitott levél percenként több száz
        // sort írna a hypertable-be.
        // A kulcs az IGÉNY, nem a token bájtjai. A base64 nem-szigorú dekódolása miatt
        // egyetlen elkapott tokenből több ezer olyan variáns gyártható, ami ugyanarra az
        // igényre fejtődik meg — token-kulccsal a fék percenként korlátlanul megkerülhető
        // lenne, és minden injektált esemény kimenő webhookot is indítana.
        if (! Cache::add('tracking:'.$type.':'.$tokens->fingerprint($claim), true, 60)) {
            return;
        }

        // NEM `withTrashed()`: egy offboardolt tenant üzeneteire nem keletkezik ÚJ, PII-t
        // tartalmazó esemény. A régi levelek a postafiókokban maradnak, a pixeljük viszont
        // innentől néma.
        $workspace = Workspace::query()->whereKey($claim->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $message = Message::query()->where('uuid', $claim->messageUuid)->first();

            if ($message === null) {
                return;
            }

            // TÖRLÉSI KÉRÉS UTÁN NINCS ÚJ ESEMÉNY. A már kiküldött levélben ott a pixel, ami
            // titkosítva hordozza a címzettet — enélkül egyetlen betöltés visszaírná a
            // törölt címet, és a `MessageEvent::created` hookja a webhookon harmadik félhez
            // is kiküldené. Az Apple MPP és a Gmail képproxyja ezt felhasználói szándék
            // nélkül, magától megteszi: a törlés csak addig tartana, amíg a levél a
            // postafiókban van.
            if (ErasedRecipient::erased($claim->recipient)) {
                return;
            }

            MessageEvent::create([
                'message_uuid' => $message->uuid,
                'type' => $type,
                'recipient' => $claim->recipient,
                'channel' => $message->channel,
                'tags' => $message->tags,
                'variables' => collect($message->variables)->except('recipient-variables')->all(),
                // A KATTINTOTT CÍM nem kerül az eseménybe: a webhook harmadik félhez viszi
                // ki, és egy link gyakran tokent vagy azonosítót hordoz.
                'payload' => [],
            ]);
        } finally {
            // A végpont NYILVÁNOS: a bent felejtett kontextus a következő, akár egészen más
            // tenanthoz tartozó kérést szennyezné. A hívó korábbi kontextusát viszont nem a
            // mi dolgunk eldobni.
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
