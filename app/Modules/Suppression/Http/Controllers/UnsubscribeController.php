<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Http\Controllers;

use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Response;

/**
 * ONE-CLICK LEIRATKOZÁS (RFC 8058, 47. kritérium).
 *
 * A `POST` a szabvány szerinti út: a levélkliens (Gmail, Yahoo, Apple Mail) EGY KATTINTÁSSAL
 * hívja meg, megerősítő oldal nélkül. A `GET` az ember számára van — aki a linkre kattint a
 * levélben, annak mutatunk egy oldalt.
 *
 * A LEIRATKOZÁS NEM KÉRDEZ VISSZA. Ha megerősítést kérnénk, a kattintás fele elveszne, és az
 * olvasó a spam-gombot nyomná helyette — a panasz pedig a küldő reputációját viszi, nem a
 * levélét. Ez a végpont ezért ír, nem kérdez.
 *
 * A HITELESÍTÉS a token: titkosított, tehát hamisíthatatlan, és a tenant-kontextus is belőle
 * jön. Ugyanaz a minta, mint a nyomkövetésnél — és ugyanaz a zár: hoszt-kötés nincs, mert a
 * leiratkozás bármely úton legitim, de a token nélkül a végpont nem csinál semmit.
 */
final class UnsubscribeController
{
    public function __construct(private readonly UnsubscribeTokens $tokens) {}

    /** RFC 8058: a levélkliens POST-ol, `List-Unsubscribe=One-Click` törzzsel. */
    public function post(string $token, WorkspaceContext $context): Response
    {
        $this->suppress($token, $context);

        // A szabvány szerint a válasz tartalma közömbös; a 200 a lényeg. A levélkliens ebből
        // tudja, hogy sikerült — és a felhasználónak azt mutatja, hogy leiratkozott.
        return response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Emberi kattintás a levélben. */
    public function get(string $token, WorkspaceContext $context): Response
    {
        $done = $this->suppress($token, $context);

        // MINDIG ugyanaz a válasz, akkor is, ha a token érvénytelen: a különbség orákulum
        // lenne arra, hogy egy cím szerepel-e a rendszerben.
        return response(view('suppression.unsubscribed', ['done' => $done])->render(), 200);
    }

    private function suppress(string $token, WorkspaceContext $context): bool
    {
        $claim = $this->tokens->parse($token);

        if ($claim === null) {
            return false;
        }

        $workspace = Workspace::query()->whereKey($claim['workspaceId'])->first();

        if ($workspace === null) {
            return false;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            // `applies_to = marketing`: a leiratkozás a MARKETINGRE szól. A tranzakciós
            // levél (számla, jelszó-visszaállítás) ettől függetlenül mehet — golden rule 4.
            Suppression::query()->firstOrCreate(
                ['email' => $claim['recipient'], 'applies_to' => 'marketing'],
                ['reason' => 'unsubscribe', 'source' => 'one_click'],
            );

            return true;
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
