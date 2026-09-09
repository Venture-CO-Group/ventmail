<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * A NYITOTT MEGHÍVÓK BEVÁLTÁSA bejelentkezéskor.
 *
 * ## Miért VERIFIKÁLT címre és csak arra
 *
 * A meghívó egy CÍMHEZ szól. Ha a beváltás a `users.email`-t ellenőrzés nélkül elfogadná,
 * bárki, aki a ventSSO-ban egy idegen címmel regisztrál, belépne a meghívott workspace-be —
 * ez a klasszikus pre-registration account takeover. A `SyncSsoUser` pontosan ezért törli a
 * verifikáltságot cím-váltáskor: az `email_verified_at` az EGYETLEN jel, ami a cím
 * birtoklását bizonyítja, és a beváltás ezen a jelen áll vagy bukik.
 *
 * ## Miért fut a global scope KIKAPCSOLVA
 *
 * A bejelentkezés pillanatában még NINCS workspace-kontextus — épp ez a lépés dönti el,
 * milyen workspace-eket lát a belépő. A lekérdezés ezért scope nélkül megy, de EGYETLEN,
 * szűk feltételre: pontos cím-egyezés, nyitott és le nem járt meghívó.
 */
final class RedeemInvitations
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return int a beváltott meghívók száma */
    public function __invoke(User $user): int
    {
        if ($user->email_verified_at === null) {
            return 0;
        }

        $email = DomainNormalizer::listKey(trim((string) $user->email));

        if ($email === '') {
            return 0;
        }

        $invitations = WorkspaceInvitation::query()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->get();

        $redeemed = 0;

        foreach ($invitations as $invitation) {
            $roles = $invitation->roles();

            if ($roles === []) {
                continue;
            }

            DB::transaction(function () use ($invitation, $user, $roles): void {
                // A MEGHÍVÓ NEM ÍRJA FELÜL a meglévő tagságot: aki már tag (mert időközben
                // kézzel felvették), annak a szerepeit egy régi meghívó nem rendezheti át —
                // akár lefelé sem.
                $member = WorkspaceMember::query()
                    ->where('workspace_id', $invitation->workspace_id)
                    ->where('user_id', $user->getKey())
                    ->first();

                if ($member === null) {
                    WorkspaceMember::create([
                        'workspace_id' => $invitation->workspace_id,
                        'user_id' => $user->getKey(),
                        'roles' => $roles,
                    ]);
                }

                $invitation->forceFill([
                    'accepted_at' => now(),
                    'accepted_user_id' => $user->getKey(),
                ])->save();
            });

            // A BEVÁLTÁS is auditált, és a MEGHÍVOTT workspace-ébe kerül: a naplóban a
            // tagság keletkezésének teljes láncolata (meghívás → beváltás) legyen meg.
            $this->audit->log(
                'member.invitation.redeemed',
                $invitation,
                after: ['roles' => $roles],
                context: ['target_user_id' => $user->getKey()],
                workspaceId: (int) $invitation->workspace_id,
                actor: $user,
            );

            $redeemed++;
        }

        return $redeemed;
    }
}
