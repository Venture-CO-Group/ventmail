<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Models\User;
use App\Modules\SuperAdmin\Services\Impersonation;
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
 *
 * EGYETLEN KIVÉTEL: az aktív, IDŐKORLÁTOS és ÍRÁSRA TILTOTT impersonation-munkamenet
 * (C2 · 82. kritérium). Az sem a tokenből jön, hanem egy auditált adatbázis-sorból, amit
 * kizárólag platform-admin hozhat létre, indokkal — és a tenant a saját naplójában látja.
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

        /*
         | AKTÍV IMPERSONATION: a platform-admin a MEGNÉZETT tenant nézetét kapja.
         |
         | Ez a 82. kritérium másik fele. A `Impersonation::start()` eddig CSAK egy sort írt
         | és auditált — a platform-admin utána semmit nem látott (`hasWorkspace()` false
         | maradt), mert ez a middleware kizárólag a `workspace_members` tagságból oldja fel
         | a kontextust. A funkció tehát egy audit-sor volt, ami nem tesz semmit.
         |
         | A TAGSÁG ITT NEM KÖVETELMÉNY, és épp ez a lényeg: a támogatás olyan fiókot néz,
         | aminek nem tagja. A hozzáférést három dolog határolja: a `platform.admin`
         | képesség (a `start()` ellenőrizte), a 30 perces LEJÁRAT, és az
         | ÍRÁS-TILALOM (`ImpersonationReadOnlyGuard`) — a támogatás LÁT, nem módosít.
         |
         | A LEJÁRT munkamenet magától megszűnik: az `active()` `null`-t ad, és a
         | felhasználó a saját tagságaira esik vissza.
         */
        $impersonated = $this->impersonatedWorkspace($user);

        if ($impersonated !== null) {
            $this->context->set($impersonated);

            return $next($request);
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

    /**
     * A MEGNÉZETT workspace, ha van aktív impersonation — különben `null`.
     *
     * Csak platform-adminra fut le: egy tenant-felhasználónak nem lehet aktív
     * munkamenete (a `start()` `platform.admin`-t követel), de a `platform_admin` flag
     * ellenőrzése így is itt van — a hozzáférési döntés nem támaszkodhat arra, hogy
     * MÁSHOL már ellenőriztük.
     */
    private function impersonatedWorkspace(mixed $user): ?Workspace
    {
        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            return null;
        }

        $session = app(Impersonation::class)->active($user);

        if ($session === null) {
            return null;
        }

        return Workspace::query()->whereKey($session->workspace_id)->first();
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
