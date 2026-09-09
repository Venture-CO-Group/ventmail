<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Enums\Feature;
use App\Modules\SuperAdmin\Models\PlanOverride;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * PLAN-OVERRIDE (83. kritérium): a ventPay válaszára ÜLŐ, LEJÁRÓ kivétel.
 *
 * ## Az override HOZZÁAD, nem helyettesít
 *
 * Ha helyettesítené a csomagot, egy kvóta-emelés (ami funkciót nem érint) véletlenül
 * ELVENNE minden funkciót — a támogatás egy dolgot akar megváltoztatni, nem mindent. Ezért
 * a `features` lista TÖBBLET: ami benne van, azt engedjük; amiről nem mond semmit, arról a
 * ventPay dönt.
 *
 * ## A ventPay marad a csomag gazdája
 *
 * Az override a ventMail oldalán él (egy képesség, egy gazda). Ha a ventMail írná át a
 * csomagot, két rendszer mondaná meg, mit fizet a tenant — és a számla meg a felület
 * elcsúszna.
 */
final class PlanOverrides
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<Feature>  $features
     *
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function grant(
        User $admin,
        Workspace $workspace,
        array $features,
        ?int $sendQuota,
        string $reason,
        Carbon $expiresAt,
    ): PlanOverride {
        if (! Gate::forUser($admin)->allows('platform.admin')) {
            // AZ ELUTASÍTOTT PRÓBÁLKOZÁS IS AUDITÁLT (81. kritérium).
            $this->audit->log(
                'platform.denied',
                null,
                context: [
                    'operation' => 'plan_override_grant',
                    'admin_id' => (int) $admin->getKey(),
                    'workspace_uuid' => (string) $workspace->uuid,
                ],
                workspaceId: (int) $workspace->getKey(),
                actor: $admin,
            );

            throw new AuthorizationException('A plan-override platform-admin jogosultságot igényel.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Az override-hoz INDOK kötelező.');
        }

        if ($expiresAt->isPast()) {
            // A múltra adott override azonnal halott — ami vagy elgépelés, vagy az a
            // szándék, hogy „legyen nyoma, de ne hasson". Egyik sem működik csendben.
            throw new InvalidArgumentException('Az override lejárata a JÖVŐBEN kell legyen.');
        }

        $override = new PlanOverride([
            'features' => array_values(array_map(static fn (Feature $f) => $f->value, $features)),
            'send_quota' => $sendQuota,
            'reason' => mb_substr($reason, 0, 255),
            'expires_at' => $expiresAt,
        ]);

        // EXPLICIT: az érintett tenantot ÉS az adományozót a szolgáltatás dönti el, nem a
        // kérés. Egyik sem `fillable` — a „ki adta" válasza nem lehet hamisítható.
        $override->workspace_id = $workspace->getKey();
        $override->created_by = $admin->getKey();
        $override->save();

        $this->audit->log(
            'platform.plan_override_granted',
            $override,
            context: [
                'workspace_uuid' => (string) $workspace->uuid,
                'admin_id' => (int) $admin->getKey(),
                'features' => $override->features,
                'send_quota' => $sendQuota,
                'reason' => $override->reason,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            // A TENANT naplójába is: a csomagja megváltozott, és tudnia kell, ki tette.
            workspaceId: (int) $workspace->getKey(),
            actor: $admin,
        );

        return $override;
    }

    /**
     * @throws AuthorizationException
     */
    public function revoke(User $admin, PlanOverride $override): PlanOverride
    {
        if (! Gate::forUser($admin)->allows('platform.admin')) {
            $this->audit->log(
                'platform.denied',
                null,
                context: ['operation' => 'plan_override_revoke', 'admin_id' => (int) $admin->getKey()],
                workspaceId: (int) $override->workspace_id,
                actor: $admin,
            );

            throw new AuthorizationException('A visszavonás platform-admin jogosultságot igényel.');
        }

        $override->forceFill(['revoked_at' => now()])->save();

        $this->audit->log(
            'platform.plan_override_revoked',
            $override,
            context: ['admin_id' => (int) $admin->getKey()],
            workspaceId: (int) $override->workspace_id,
            actor: $admin,
        );

        return $override->fresh();
    }

    /**
     * ENGEDI-e valamelyik AKTÍV override ezt a funkciót?
     *
     * A SZÁMLÁZOTT workspace-t kérdezzük (sub-accountnál a parentet), ugyanaz a szabály,
     * mint a C1 `EntitlementChecker`-ében: a parent fizet, tehát a parentre adott kivétel a
     * childra is érvényes.
     */
    public function allows(Workspace $workspace, Feature $feature): bool
    {
        foreach ($this->activeFor($workspace) as $override) {
            if (in_array($feature->value, (array) $override->features, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A FELÜLÍRT kvóta — vagy `null`, ha egyik aktív override sem nyúlt hozzá.
     *
     * A LEGNAGYOBB érték nyer: két párhuzamos override közül a támogatás szándéka a
     * bővebb, és egy szűkebb override nem vehet el egy korábban adott bővebbet.
     */
    public function sendQuota(Workspace $workspace): ?int
    {
        $quotas = [];

        foreach ($this->activeFor($workspace) as $override) {
            if ($override->send_quota !== null) {
                $quotas[] = (int) $override->send_quota;
            }
        }

        return $quotas === [] ? null : max($quotas);
    }

    /**
     * @return Collection<int, PlanOverride>
     */
    private function activeFor(Workspace $workspace)
    {
        $billable = (int) ($workspace->parents()->first()?->getKey() ?? $workspace->getKey());

        return PlanOverride::query()
            ->where('workspace_id', $billable)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();
    }
}
