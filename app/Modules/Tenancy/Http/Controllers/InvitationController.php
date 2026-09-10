<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Actions\RedeemInvitations;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A MEGHÍVOTT SAJÁT képernyője: elfogadás vagy elutasítás.
 *
 * ## Miért nincs rajta `workspace` middleware
 *
 * Mert aki ide jön, jellemzően MÉG NEM TAGJA egyetlen workspace-nek sem — épp az itteni
 * döntés teszi azzá. A `ResolveWorkspace` tagság híján (helyesen) 403-at ad, tehát ez az
 * EGYETLEN belépés utáni képernyő, ami kontextus nélkül is elérhető.
 *
 * ## Miért nem automatikus a beváltás
 *
 * Mert a meghívó a MEGHÍVOTTRÓL is mond valamit: elfogadás után a neve, címe és utolsó
 * belépése megjelenik egy IDEGEN szervezet tagok-képernyőjén. Ehhez az ő döntése kell, nem
 * elég, hogy valaki beírta a címét egy űrlapba.
 */
final class InvitationController
{
    public function index(Request $request, RedeemInvitations $invitations): Response
    {
        $user = $request->user();

        $open = $user instanceof User ? $invitations->openFor($user) : collect();

        return Inertia::render('Tenancy/Invitations', [
            'invitations' => $open->map(fn (WorkspaceInvitation $invitation) => [
                'uuid' => (string) $invitation->uuid,
                // A WORKSPACE NEVE az egyetlen adat, amit a meghívottnak megmutatunk a
                // meghívó tenantról — a döntéshez ennyi kell, és ennyi is elég.
                'workspace' => (string) (Workspace::query()->whereKey($invitation->workspace_id)->value('name') ?? ''),
                'roles' => $invitation->roles(),
                'expiresAt' => $invitation->expires_at?->toIso8601String(),
            ])->values()->all(),

            // A MEGERŐSÍTETLEN cím esete külön üzenet: a meghívó létezhet, de amíg a
            // ventSSO nem igazolta a címet, nem váltható be — és a felhasználó ne
            // találgassa, miért üres a lista.
            'unverified' => $user instanceof User && $user->email_verified_at === null,
        ]);
    }

    public function accept(Request $request, string $uuid, RedeemInvitations $invitations): RedirectResponse
    {
        $user = $request->user();

        $invitation = WorkspaceInvitation::query()->withoutGlobalScopes()->where('uuid', $uuid)->first();

        // A HOZZÁRENDELÉST az action ellenőrzi (verifikált cím + pontos egyezés + nyitott
        // meghívó), nem ez a kontroller: a döntés egy helyen van.
        if (! $user instanceof User || $invitation === null || ! $invitations->accept($user, $invitation)) {
            return back()->with('error', __('ventmail.invitations.unavailable'));
        }

        // A FRISS TAGSÁGRA VÁLTUNK: a `workspace` middleware különben a régi (vagy hiányzó)
        // választással 403-at adna, és a most elfogadott meghívó után a felhasználó egy
        // hibaoldalon kötne ki.
        $request->session()->put(
            'workspace_uuid',
            (string) (Workspace::query()->whereKey($invitation->workspace_id)->value('uuid') ?? '')
        );

        return redirect('/dashboard')->with('success', __('ventmail.invitations.accepted'));
    }

    public function decline(Request $request, string $uuid, RedeemInvitations $invitations): RedirectResponse
    {
        $user = $request->user();

        $invitation = WorkspaceInvitation::query()->withoutGlobalScopes()->where('uuid', $uuid)->first();

        if (! $user instanceof User || $invitation === null || ! $invitations->decline($user, $invitation)) {
            return back()->with('error', __('ventmail.invitations.unavailable'));
        }

        return back()->with('success', __('ventmail.invitations.declined'));
    }
}
