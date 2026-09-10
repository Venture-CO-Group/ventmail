<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Actions\AssignWorkspaceRoles;
use App\Modules\Tenancy\Actions\CreateSubAccount;
use App\Modules\Tenancy\Actions\InviteMember;
use App\Modules\Tenancy\Actions\RemoveMember;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Policies\AbilityMap;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * TAGOK, MEGHÍVÓK ÉS SUB-ACCOUNTOK képernyője (spec 3.2).
 *
 * ## Miért egy képernyőn
 *
 * Mert ugyanaz a kérdés három alakja: KI FÉR HOZZÁ. A sub-account nem „másik termék", hanem
 * a hozzáférés-kezelés ügynökségi esete — külön menüpontban a kettő elfedné, hogy a child
 * workspace tagsága a childban dől el, nem a parent-kapcsolatból.
 *
 * ## A NYERS `workspace_members` sorok nem hordoznak global scope-ot
 *
 * A tagság-tábla a scope FORRÁSA, nem az alanya — ezért minden lekérdezés EXPLICIT
 * `workspace_id`-szűrést kap, a törlés pedig az actionben újra ellenőrzi a hovatartozást.
 */
final class MemberController
{
    public function index(WorkspaceContext $context): Response
    {
        Gate::authorize('member.manage');

        $workspace = $context->workspace();

        $members = WorkspaceMember::query()
            ->where('workspace_id', $workspace->getKey())
            ->with('user')
            ->get()
            ->sortBy(fn (WorkspaceMember $member) => (string) $member->user?->email)
            ->values();

        $actorRoles = $members->first(
            fn (WorkspaceMember $member) => (int) $member->user_id === (int) Auth::id()
        )?->roles() ?? [];

        return Inertia::render('Tenancy/Members', [
            'members' => $members->map(fn (WorkspaceMember $member) => [
                'id' => (int) $member->getKey(),
                'name' => (string) ($member->user?->name ?? ''),
                'email' => (string) ($member->user?->email ?? ''),
                'roles' => $member->roles(),
                'lastLoginAt' => $member->user?->last_login_at?->toIso8601String(),
                'self' => (int) $member->user_id === (int) Auth::id(),
            ])->values()->all(),

            'invitations' => WorkspaceInvitation::query()
                ->whereNull('accepted_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (WorkspaceInvitation $invitation) => [
                    'uuid' => (string) $invitation->uuid,
                    'email' => (string) $invitation->email,
                    'roles' => $invitation->roles(),
                    'expiresAt' => $invitation->expires_at?->toIso8601String(),
                    'expired' => ! $invitation->isOpen(),
                ])->values()->all(),

            /*
             | A KIOSZTHATÓ SZEREPEK listája a SZERVEREN dől el, nem a felületen.
             |
             | A felület csak ELREJTI, amit a hívó nem oszthat ki — a döntést az
             | `AssignWorkspaceRoles::assertGrantable()` hozza meg minden kérésnél. A lista
             | célja az, hogy a felhasználó ne egy hibaüzenetből tudja meg, mit nem tehet.
             */
            'assignableRoles' => array_values(array_filter(
                array_map(fn (Role $role) => $role->value, Role::cases()),
                fn (string $role) => $this->canGrant($actorRoles, $role)
            )),

            'subAccounts' => [
                'canManage' => Gate::allows('subAccount.manage'),
                // A CHILD nem hozhat létre újabb childot (egyszintű hierarchia, DB-trigger).
                'isChild' => $workspace->parents()->exists(),
                'parent' => $workspace->parent() === null ? null : [
                    'name' => (string) $workspace->parent()?->name,
                ],
                // A TAGLÉTSZÁM EGY lekérdezésből (`withCount`), nem child-enként egyből:
                // egy húsz al-fiókos ügynökségnél az N+1 húsz külön kört jelentett.
                'children' => $workspace->children()->withCount('members')->orderBy('name')->get()
                    ->map(fn (Workspace $child) => [
                        'uuid' => (string) $child->uuid,
                        'name' => (string) $child->name,
                        'plan' => (string) $child->plan,
                        // A TAGLÉTSZÁM az egyetlen child-adat, ami ide kerül: a child
                        // TARTALMA (kontaktok, naplók) a child izolációjába tartozik, és
                        // a parent felületén nem jelenhet meg.
                        'members' => (int) $child->members_count,
                    ])->values()->all(),
            ],
        ]);
    }

    public function assignRoles(Request $request, int $member, AssignWorkspaceRoles $assign, WorkspaceContext $context): RedirectResponse
    {
        Gate::authorize('member.manage');

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'max:40'],
        ]);

        $row = WorkspaceMember::query()
            ->where('workspace_id', $context->workspaceId())
            ->whereKey($member)
            ->first();

        if ($row === null) {
            return back()->with('error', __('ventmail.members.notFound'));
        }

        $target = User::query()->whereKey($row->user_id)->first();

        if ($target === null) {
            return back()->with('error', __('ventmail.members.notFound'));
        }

        try {
            $assign($target, array_values($validated['roles']));
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException $e) {
            // Az `InvalidArgumentException` NEM leszármazottja a `RuntimeException`-nek:
            // enélkül egy ismeretlen szerep-név (a kérés szabad bemenete) NYERS 500-at
            // adott, nem hibaüzenetet.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.members.rolesUpdated'));
    }

    public function invite(Request $request, InviteMember $invite): RedirectResponse
    {
        Gate::authorize('member.manage');

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:320'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'max:40'],
        ]);

        try {
            $invite($validated['email'], array_values($validated['roles']));
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException) {
            // VERSENYHELYZET: két párhuzamos meghívó ugyanarra a címre a részleges unique
            // indexbe ütközik. Ez nem a felhasználó hibája, és nem 500 — a MÁSIK kérés
            // épp elvégezte, amit ő kért.
            return back()->with('error', __('ventmail.members.inviteConflict'));
        }

        return back()->with('success', __('ventmail.members.invited'));
    }

    public function revokeInvitation(string $uuid, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('member.manage');

        $invitation = WorkspaceInvitation::query()->where('uuid', $uuid)->whereNull('accepted_at')->first();

        if ($invitation === null) {
            return back()->with('error', __('ventmail.members.notFound'));
        }

        // Golden rule 11: a visszavonás is jogosultsági döntés — a naplóból ki kell
        // derülnie, hogy a meghívó nem lejárt, hanem valaki elvette.
        $audit->log('member.invitation.revoked', $invitation, before: [
            'email' => (string) $invitation->email,
            'roles' => $invitation->roles(),
        ]);

        $invitation->delete();

        return back()->with('success', __('ventmail.members.invitationRevoked'));
    }

    public function remove(int $member, RemoveMember $remove, WorkspaceContext $context): RedirectResponse
    {
        Gate::authorize('member.manage');

        $row = WorkspaceMember::query()
            ->where('workspace_id', $context->workspaceId())
            ->whereKey($member)
            ->first();

        if ($row === null) {
            return back()->with('error', __('ventmail.members.notFound'));
        }

        try {
            $remove($row);
        } catch (AuthorizationException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.members.removed'));
    }

    public function storeSubAccount(Request $request, CreateSubAccount $create): RedirectResponse
    {
        Gate::authorize('subAccount.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        try {
            $create($validated['name']);
        } catch (AuthorizationException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.members.subAccountCreated'));
    }

    /**
     * FAIL-CLOSED: üres képesség-listájú (vagy ismeretlen) szerep NEM osztható ki.
     *
     * A ciklus önmagában `true`-t adott volna üres listára — „amit nem tudunk, azt szabad".
     * Ma egyik szerep sem üres, de a szemantikának a szigorúbb irányba kell dőlnie: egy
     * jövőbeli, képesség nélküli szerep ne váljon automatikusan mindenki által
     * kioszthatóvá.
     *
     * @param  list<string>  $actorRoles
     */
    private function canGrant(array $actorRoles, string $role): bool
    {
        $case = Role::tryFromClaim($role);

        if ($case === null) {
            return false;
        }

        $granted = AbilityMap::abilitiesOf($case);

        if ($granted === []) {
            return false;
        }

        foreach ($granted as $ability) {
            if (! AbilityMap::allows($actorRoles, $ability)) {
                return false;
            }
        }

        return true;
    }
}
