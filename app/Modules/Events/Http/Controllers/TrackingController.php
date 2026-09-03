<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Events\Services\TrackingTokens;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
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

    public function open(string $token, TrackingTokens $tokens, WorkspaceContext $context): Response
    {
        $this->record($token, 'opened', $tokens, $context);

        return response(base64_decode(self::PIXEL, true), 200, [
            'Content-Type' => 'image/gif',
            // A böngésző/levélkliens ne cache-elje: a második nyitás is nyitás.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function click(string $token, TrackingTokens $tokens, WorkspaceContext $context): RedirectResponse
    {
        $claim = $tokens->parse($token);

        // A cél a TOKENEN BELÜL van, tehát nem írható át kívülről: ez a végpont nem lehet
        // nyílt átirányító. A séma-ellenőrzés a második zár, ha a token valaha máshonnan jönne.
        if ($claim?->destination === null || ! preg_match('#^https?://#i', $claim->destination)) {
            throw new NotFoundHttpException;
        }

        $this->record($token, 'clicked', $tokens, $context);

        return redirect()->away($claim->destination, 302);
    }

    private function record(string $token, string $type, TrackingTokens $tokens, WorkspaceContext $context): void
    {
        $claim = $tokens->parse($token);

        if ($claim === null) {
            return;
        }

        // Egy percen belüli ismétlés EGY esemény. A pixelt egy levélkliens minden
        // megjelenítéskor újratölti; enélkül egyetlen megnyitott levél percenként több száz
        // sort írna a hypertable-be.
        if (! Cache::add('tracking:'.$type.':'.hash('sha256', $token), true, 60)) {
            return;
        }

        $workspace = Workspace::query()->withTrashed()->whereKey($claim->workspaceId)->first();

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
