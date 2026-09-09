<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\SuperAdmin\Models\ImpersonationSession;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * AUDITÁLT IMPERSONATION (82. kritérium).
 *
 * ## A támogatás LÁT, nem módosít
 *
 * Ez a kritérium magja. Egy támogatási munkamenet célja a MEGÉRTÉS: „mit lát a kliens?"
 * Az írás jogával viszont a platform-admin a tenant NEVÉBEN módosíthatna — az audit-nyom
 * az ő nevén lenne, a tenant pedig azt látná, hogy „valaki" átírta a kampányát. A
 * megértéshez nem kell írás; az írás jogát a tenant adja meg, nem mi vesszük el.
 *
 * ## A TENANT IS LÁTJA
 *
 * Az audit-sor a MEGNÉZETT workspace-hez tartozik, nem a platform „belső" naplójába megy.
 * Egy idegen ember megnyitotta a fiókját — ez az ő ügye, nem a miénk. Ez a különbség
 * dönti el, hogy egy support-hozzáférés bizalomépítő vagy bizalomromboló.
 *
 * ## IDŐKORLÁT az adatbázisban
 *
 * A támogatás percekig néz egy fiókot, nem napokig. A korlát nélkül egy elfelejtett
 * impersonation hetekig élne, és a tenant adatai nyitva állnának — anélkül, hogy bárki
 * szándékosan hibázott volna.
 */
final class Impersonation
{
    /** Egy munkamenet felső hossza percben. */
    public const MAX_MINUTES = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function start(User $admin, Workspace $workspace, string $reason): ImpersonationSession
    {
        if (! Gate::forUser($admin)->allows('platform.admin')) {
            // AZ ELUTASÍTOTT PRÓBÁLKOZÁS IS AUDITÁLT (81. kritérium): egy visszavont admin
            // kísérlete egy idegen fiók megnyitására a napló legérdekesebb sora.
            $this->audit->log(
                'platform.denied',
                null,
                context: [
                    'operation' => 'impersonation',
                    'admin_id' => (int) $admin->getKey(),
                    'workspace_uuid' => (string) $workspace->uuid,
                ],
                workspaceId: (int) $workspace->getKey(),
                actor: $admin,
            );

            throw new AuthorizationException('Az impersonation platform-admin jogosultságot igényel.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            // A „csak megnéztem" nem elfogadható indok egy idegen fiók megnyitásához. A
            // támogatási ügy száma az, ami egy későbbi vizsgálatnál megmondja, MIÉRT.
            throw new InvalidArgumentException(
                'Az impersonationhoz INDOK kötelező (támogatási ügy száma vagy leírás).'
            );
        }

        /*
         | A KORÁBBI munkamenetek LEZÁRÁSA.
         |
         | Egy adminnak egyszerre EGY nézete van. Enélkül két párhuzamos munkamenet mellett
         | az `isReadOnly()` és a `active()` nem tudná megmondani, MELYIK tenant nézetében
         | van — és egy régi, elfelejtett munkamenet írás-tilalmat tartana fenn egy új
         | munka fölött.
         */
        foreach ($this->openSessions($admin) as $open) {
            $this->end($open);
        }

        $session = new ImpersonationSession([
            'user_id' => $admin->getKey(),
            'reason' => mb_substr($reason, 0, 255),
            'started_at' => now(),
            'expires_at' => now()->addMinutes(self::MAX_MINUTES),
        ]);

        // EXPLICIT, nem tömeges hozzárendelésből: a `workspace_id` a MEGNÉZETT tenantra
        // mutat, és azt a szolgáltatás dönti el, nem a kérés.
        $session->workspace_id = $workspace->getKey();
        $session->save();

        $this->audit->log(
            'platform.impersonation_started',
            $session,
            context: [
                'workspace_uuid' => (string) $workspace->uuid,
                'admin_id' => (int) $admin->getKey(),
                'reason' => $session->reason,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
            // A MEGNÉZETT tenant naplójába: neki kell látnia, hogy megtörtént.
            workspaceId: (int) $workspace->getKey(),
            actor: $admin,
        );

        return $session;
    }

    public function end(ImpersonationSession $session): ImpersonationSession
    {
        if ($session->ended_at !== null) {
            return $session;
        }

        $session->forceFill(['ended_at' => now()])->save();

        $this->audit->log(
            'platform.impersonation_ended',
            $session,
            context: [
                'admin_id' => (int) $session->user_id,
                'duration_seconds' => (int) $session->started_at->diffInSeconds(now()),
            ],
            workspaceId: (int) $session->workspace_id,
            // AZ AKTOR A MUNKAMENET GAZDÁJA, nem a bejelentkezett felhasználó: a lezárás
            // futhat ütemezett parancsból (lejárat-takarítás) is, ahol nincs session — a
            // rendszer legerősebb műveletének a nyoma nem lehet aktor nélküli.
            actor: $session->user,
        );

        return $session->fresh();
    }

    /**
     * AZ AKTÍV munkamenet — vagy `null`.
     *
     * A LEJÁRT munkamenet `null`-t ad, `ended_at` nélkül is: a lejárat magától megszünteti
     * a nézetet, nem kell hozzá külön művelet. Ez az, ami az „elfelejtett impersonation"
     * osztályt kizárja.
     */
    public function active(User $admin): ?ImpersonationSession
    {
        return ImpersonationSession::query()
            ->where('user_id', $admin->getKey())
            ->whereNull('ended_at')
            ->where('expires_at', '>', now())
            ->latest('started_at')
            ->first();
    }

    /**
     * ÍRÁS-TILALOM: aktív impersonation alatt a platform-admin csak OLVAS.
     *
     * A lezárás (vagy a lejárat) UTÁN a platform-admin a SAJÁT nevében újra dolgozhat — az
     * írás-tilalom a munkamenetre szól, nem a személyre.
     */
    public function isReadOnly(User $admin): bool
    {
        return $this->active($admin) !== null;
    }

    /**
     * @return Collection<int, ImpersonationSession>
     */
    private function openSessions(User $admin)
    {
        return ImpersonationSession::query()
            ->where('user_id', $admin->getKey())
            ->whereNull('ended_at')
            ->get();
    }
}
