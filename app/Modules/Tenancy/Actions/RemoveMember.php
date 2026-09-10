<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
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
            throw new AuthorizationException(__('ventmail.members.notAllowed'));
        }

        // A TENANT-HATÁR: a tagság-sor a `workspace_members`-ben nem hordoz global scope-ot
        // (nincs `workspace_id`-alapú modell-scope rajta), ezért a hovatartozást ITT kell
        // ellenőrizni. Enélkül egy idegen `id` egy MÁSIK workspace tagját törölné —
        // pontosan az a szivárgás, amit a golden rule 6 blokkolónak nevez.
        if ((int) $member->workspace_id !== $this->context->workspaceId()) {
            throw new AuthorizationException(__('ventmail.members.foreignMember'));
        }

        // SENKI NEM ZÁRHATJA KI MAGÁT. Eddig csak a Vue sablon rejtette el a gombot —
        // egy `DELETE /members/{sajátId}` átment, és az illető minden hozzáférését
        // elvesztette, önkiszolgáló visszaút nélkül.
        if ((int) $member->user_id === (int) $actor->getKey()) {
            throw new AuthorizationException(__('ventmail.members.selfRemoval'));
        }

        // NÁLAD ERŐSEBB TAGOT NEM TÁVOLÍTHATSZ EL — ugyanaz a szabály, mint a
        // lefokozásnál. Enélkül az `admin` egy kattintással kitehette az `owner`-t.
        $this->roles->assertTargetNotStronger($member);

        $this->roles->assertOwnerRemains($member, keepsOwner: false);

        // A TŐLE SZÁRMAZÓ NYITOTT MEGHÍVÓK is elesnek: aki már nincs bent, annak a
        // döntése ne lépjen hatályba 13 nappal később. A meghívó 14 napig él, a tagság
        // visszavonása pedig azonnal — a kettő között eddig rés volt.
        WorkspaceInvitation::query()
            ->where('workspace_id', $member->workspace_id)
            ->where('invited_by_user_id', $member->user_id)
            ->whereNull('accepted_at')
            ->get()
            ->each(function (WorkspaceInvitation $invitation) {
                $this->audit->log('member.invitation.revoked', $invitation, before: [
                    'email' => (string) $invitation->email,
                    'roles' => $invitation->roles(),
                    'reason' => 'inviter_removed',
                ]);

                $invitation->delete();
            });

        // A CHILD-TULAJDONOSSÁG is elesik: a parentből eltávolított tag ne maradjon
        // owner az al-fiókokon (ugyanaz a pillanatkép-probléma, mint a lefokozásnál).
        $target = User::query()->whereKey($member->user_id)->first();

        if ($target !== null) {
            $this->roles->dropChildOwnership($target);
        }

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
