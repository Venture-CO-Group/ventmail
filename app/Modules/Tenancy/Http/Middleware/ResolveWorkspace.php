<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A kérés workspace-kontextusának feloldása és a TAGSÁG ellenőrzése.
 *
 * A tagság forrása kizárólag a `workspace_members` tábla — a ventSSO tokenje sosem dönt
 * hozzáférésről (golden rule 1). Idegen workspace-re 403, nem néma üres lista: a hallgatag
 * kudarc elrejtené a jogosultsági hibát.
 */
final class ResolveWorkspace
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('auth.redirect'));
        }

        // Explicit váltási kérés (query/route paraméter) SOSEM eshet vissza csendben:
        // ha nincs rá tagság, az 403. A session-ben tárolt választás viszont elavulhat.
        $explicit = (string) ($request->route('workspace') ?? $request->query('workspace', ''));

        if ($explicit !== '') {
            $membership = $this->membershipFor($user->getKey(), $explicit);

            if ($membership === null) {
                abort(403, 'Nincs hozzáférésed ehhez a workspace-hez.');
            }

            return $this->withWorkspace($request, $next, $membership);
        }

        $requested = (string) $request->session()->get('workspace_uuid', '');

        // A tagsági sor a workspace soft delete-je után is megmarad (a cascade csak hard
        // delete-re fut), ezért a törölt workspace-eket ki kell zárni — különben egy régi
        // tagság 404-be vinné a felhasználót minden kérésen.
        $membership = $this->membershipFor($user->getKey(), $requested);

        if ($membership === null && $requested !== '') {
            // Elavult választás (pl. időközben eltávolították a workspace-ből): a session-t
            // takarítjuk, és visszaesünk az első érvényes tagságra — enélkül a felhasználó
            // tartósan kizárná magát, kijelentkezés nélkül.
            $request->session()->forget('workspace_uuid');
            $membership = $this->membershipFor($user->getKey(), '');
        }

        if ($membership === null) {
            abort(403, 'Nincs hozzáférésed ehhez a workspace-hez.');
        }

        return $this->withWorkspace($request, $next, $membership);
    }

    private function withWorkspace(Request $request, Closure $next, WorkspaceMember $membership): Response
    {
        $workspace = Workspace::query()->whereKey($membership->workspace_id)->first();

        if ($workspace === null) {
            abort(403, 'A workspace nem érhető el.');
        }

        $this->context->set($workspace);
        $request->session()->put('workspace_uuid', $workspace->uuid);

        return $next($request);
    }

    private function membershipFor(mixed $userId, string $requestedUuid): ?WorkspaceMember
    {
        return WorkspaceMember::query()
            ->where('user_id', $userId)
            ->whereIn('workspace_id', Workspace::query()
                ->when($requestedUuid !== '', fn ($query) => $query->where('uuid', $requestedUuid))
                ->select('id'))
            ->orderBy('id')
            ->first();
    }
}
