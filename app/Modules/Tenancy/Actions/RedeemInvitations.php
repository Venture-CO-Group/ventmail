<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A MEGHÍVÓK BEVÁLTÁSA — a MEGHÍVOTT KIFEJEZETT ELFOGADÁSÁVAL.
 *
 * ## Miért nem automatikus a bejelentkezéskor
 *
 * Az első változat belépéskor magától beváltott mindent. Mérve, mit jelentett: „A" tenant
 * meghívja `ceo@bigcorp.com`-ot; az illető KÖVETKEZŐ, teljesen független ventMail-belépésén
 * csendben tagja lesz „A" workspace-ének, és a NEVE, e-mail-címe, utolsó belépése megjelenik
 * „A" tagok-képernyőjén. Vagyis egy idegen szervezet PII-t szerez valakiről, aki soha semmit
 * nem tett — elég volt beírni a címét egy űrlapba.
 *
 * A beváltás ezért kifejezett elfogadáshoz kötött (`/invitations`), és az elutasítás
 * TÖRLI a sort — a meghívott dönthet úgy, hogy nyoma se maradjon.
 *
 * ## Miért CSAK VERIFIKÁLT címre
 *
 * A meghívó egy CÍMHEZ szól. Ellenőrzés nélkül bárki, aki a ventSSO-ban egy idegen címmel
 * regisztrál, belépne a meghívott workspace-be — ez a klasszikus pre-registration account
 * takeover. A `SyncSsoUser` pontosan ezért törli a verifikáltságot cím-váltáskor: az
 * `email_verified_at` az EGYETLEN jel, ami a cím birtoklását bizonyítja.
 *
 * ## Miért fut a global scope KIKAPCSOLVA
 *
 * Mert a meghívott még NEM tagja egyik workspace-nek sem — épp a meghívó mondja meg, melyik
 * workspace-ről van szó. A lekérdezés ezért scope nélkül megy, de EGYETLEN, szűk feltételre:
 * pontos (`authKey`) cím-egyezés, nyitott és le nem járt meghívó.
 */
final class RedeemInvitations
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * A meghívott NYITOTT, le nem járt meghívói.
     *
     * @return Collection<int, WorkspaceInvitation>
     */
    public function openFor(User $user): Collection
    {
        // HOZZÁFÉRÉSI kulcs, nem lista-kulcs: lásd a `DomainNormalizer::authKey()`
        // indoklását — a `listKey()` helyi része nem injektív, és itt a téves egyezés
        // IDEGEN workspace-be adna tagságot.
        $email = $user->email_verified_at === null
            ? ''
            : DomainNormalizer::authKey((string) $user->email);

        if ($email === '') {
            return collect();
        }

        return WorkspaceInvitation::query()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->get();
    }

    /**
     * EGY meghívó elfogadása.
     *
     * A hívó a MEGHÍVOTT maga (a `/invitations` képernyőről) — a jogosultságot az adja,
     * hogy a meghívó az ő VERIFIKÁLT címére szól. Ezt itt ellenőrizzük újra, nem a
     * kontrollerben: a döntés nem támaszkodhat arra, hogy máshol már ellenőriztük.
     */
    public function accept(User $user, WorkspaceInvitation $invitation): bool
    {
        if (! $this->belongsTo($user, $invitation)) {
            return false;
        }

        $roles = $invitation->roles();

        if ($roles === []) {
            return false;
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

        // A BEVÁLTÁS a MEGHÍVÓ workspace-ének naplójába kerül — nem a „nincs workspace"
        // gyűjtőbe: a tenant ott keresi, hogyan keletkezett a tagság.
        $this->audit->log(
            'member.invitation.redeemed',
            $invitation,
            after: ['roles' => $roles],
            context: ['target_user_id' => $user->getKey()],
            workspaceId: (int) $invitation->workspace_id,
            actor: $user,
        );

        return true;
    }

    /**
     * EGY meghívó elutasítása — a sor TÖRLÉSÉVEL.
     *
     * A meghívott joga, hogy ne maradjon nyoma egy idegen szervezet tábláiban. A tenant
     * felé a tény nem vész el: az audit-sor (`member.invitation.declined`) marad.
     */
    public function decline(User $user, WorkspaceInvitation $invitation): bool
    {
        if (! $this->belongsTo($user, $invitation)) {
            return false;
        }

        $this->audit->log(
            'member.invitation.declined',
            $invitation,
            before: ['roles' => $invitation->roles()],
            workspaceId: (int) $invitation->workspace_id,
            actor: $user,
        );

        $invitation->delete();

        return true;
    }

    private function belongsTo(User $user, WorkspaceInvitation $invitation): bool
    {
        if ($user->email_verified_at === null || ! $invitation->isOpen()) {
            return false;
        }

        $email = DomainNormalizer::authKey((string) $user->email);

        return $email !== '' && $email === (string) $invitation->email;
    }
}
