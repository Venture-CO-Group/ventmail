<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * TAGSÁG VISSZAVONÁSA.
 *
 * A `workspace_members` sor törlése — a `users` soré NEM. A felhasználó a ventSSO-é
 * (golden rule 1); innen csak a hozzáférését vesszük el, más workspace-ekben tag maradhat.
 */
final class RemoveMember
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly AssignWorkspaceRoles $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(WorkspaceMember $member): void
    {
        $actor = Auth::user();

        if ($actor === null || ! Gate::forUser($actor)->allows('member.manage')) {
            throw new AuthorizationException('Nincs jogosultságod tagságot kezelni.');
        }

        // A TENANT-HATÁR: a tagság-sor a `workspace_members`-ben nem hordoz global scope-ot
        // (nincs `workspace_id`-alapú modell-scope rajta), ezért a hovatartozást ITT kell
        // ellenőrizni. Enélkül egy idegen `id` egy MÁSIK workspace tagját törölné —
        // pontosan az a szivárgás, amit a golden rule 6 blokkolónak nevez.
        if ((int) $member->workspace_id !== $this->context->workspaceId()) {
            throw new AuthorizationException('A tagság nem ehhez a workspace-hez tartozik.');
        }

        $this->roles->assertOwnerRemains($member, keepsOwner: false);

        $before = $member->roles();

        $this->audit->log(
            'member.removed',
            $member,
            before: ['roles' => $before],
            context: ['target_user_id' => $member->user_id],
        );

        $member->delete();
    }
}
