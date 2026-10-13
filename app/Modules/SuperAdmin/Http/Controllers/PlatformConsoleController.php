<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Controllers;

use App\Models\User;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\SuperAdmin\Models\ImpersonationSession;
use App\Modules\SuperAdmin\Models\PlanOverride;
use App\Modules\SuperAdmin\Services\AuditSearch;
use App\Modules\SuperAdmin\Services\ClusterHealth;
use App\Modules\SuperAdmin\Services\GlobalSuppressions;
use App\Modules\SuperAdmin\Services\Impersonation;
use App\Modules\SuperAdmin\Services\IpSupervision;
use App\Modules\SuperAdmin\Services\PlanOverrides;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * PLATFORM-KONZOL (81–88. kritérium) — a C2 backendjének a felülete.
 *
 * ## Ez NEM tenant-felület, és a különbség szerkezeti
 *
 * Minden művelet a `platform.admin` képességhez kötött, amit a ventSSO KÜLÖN kliens-claimje
 * ad — nem a workspace-tagság. A képernyők tenanteken átnyúlva olvasnak, ezért itt nincs
 * és nem is lehet workspace-scope: az `AuditLog`, a `ImpersonationSession` és a
 * `PlanOverride` platform-adat.
 *
 * ## A TÁMOGATÓI NÉZET írás-tilalma két rétegben áll
 *
 * A `EnforceImpersonation` middleware minden nem biztonságos HTTP-metódust tilt aktív
 * munkamenet alatt (a nézet LEZÁRÁSA az egyetlen kivétel — enélkül nem lehetne kilépni
 * belőle), a `ImpersonationReadOnlyGuard` pedig a modell-mentéseket. Ez a képernyő
 * egyiket sem kerüli meg: a gombok ott vannak, a szerver dönt.
 */
final class PlatformConsoleController
{
    public function index(ClusterHealth $health, IpSupervision $ips, ?User $user = null): Response
    {
        $admin = $this->admin();

        return Inertia::render('Platform/Console', [
            // A CLUSTER-ÁLLAPOT: az „ismeretlen" NEM zöld, és a riport ezt meg is mutatja.
            'health' => $health->report($admin),
            'warmup' => $ips->warmupOverview($admin),
            'impersonation' => $this->activeSession($admin),
            'workspaces' => Workspace::query()
                ->withoutGlobalScopes()
                ->orderBy('name')
                ->limit(200)
                ->get(['uuid', 'name'])
                ->map(fn (Workspace $workspace) => [
                    'uuid' => (string) $workspace->uuid,
                    'name' => (string) $workspace->name,
                ])->values()->all(),
            'overrides' => PlanOverride::query()
                ->with('workspace')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->get()
                ->map(fn (PlanOverride $override) => [
                    'uuid' => (string) $override->uuid,
                    'workspace' => (string) ($override->workspace?->name ?? '—'),
                    'features' => array_values((array) $override->features),
                    'sendQuota' => $override->send_quota === null ? null : (int) $override->send_quota,
                    'reason' => (string) $override->reason,
                    'expiresAt' => $override->expires_at?->toIso8601String(),
                ])->values()->all(),
            'globalSuppressions' => Suppression::query()
                ->withoutGlobalScopes()
                ->whereNull('workspace_id')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (Suppression $suppression) => [
                    'uuid' => (string) $suppression->uuid,
                    'email' => (string) $suppression->email,
                    'reason' => (string) $suppression->reason,
                    'detail' => $suppression->detail,
                    'createdAt' => $suppression->created_at?->toIso8601String(),
                ])->values()->all(),
            'features' => array_map(fn (Feature $feature) => $feature->value, Feature::cases()),
            'reasons' => array_map(fn (SuppressionReason $reason) => $reason->value, SuppressionReason::cases()),
        ]);
    }

    /**
     * KÜLDÉSI INFRASTRUKTÚRA (v1.1 · 7. modul, D176) — CSAK OLVASÁS, csak platform-adminnak.
     *
     * Poolok (tenanteken átnyúlva), IP-k reputációval és warmuppal, a legutóbbi blocklist-
     * állapot IP-nként és zónánként, a rate limitek és a küldési mód kapcsolói. Itt SEMMI nem
     * állítható: a karantén a konzolon, a küldési mód (`SENDING_LIVE`, allowlist, marketing)
     * a tulajdonosé (RUNBOOK-mail §13) — a felület csak megmutatja.
     */
    public function infrastructure(IpSupervision $ips): Response
    {
        $admin = $this->admin();

        $pools = IpPool::query()->withoutGlobalScopes()->orderBy('name')->limit(500)->get();
        $tenants = Workspace::query()->withoutGlobalScopes()->whereIn('id', $pools->pluck('workspace_id')->filter())->pluck('name', 'id');
        $addresses = collect($ips->warmupOverview($admin));

        // A LEGUTÓBBI ellenőrzés címenként és zónánként — `distinct on`, 30 napos ablakban.
        $blocklist = BlocklistCheck::query()
            ->where('checked_at', '>=', now()->subDays(30))
            ->selectRaw('distinct on (target, zone) target, zone, listed, detail, checked_at')
            ->orderBy('target')
            ->orderBy('zone')
            ->orderByDesc('checked_at')
            ->limit(2000)
            ->get()
            ->groupBy('target');

        return Inertia::render('Platform/Infrastructure', [
            'pools' => $pools->map(fn (IpPool $pool) => [
                'uuid' => (string) $pool->uuid,
                'name' => (string) $pool->name,
                'type' => (string) $pool->type,
                'channel' => $pool->channel,
                'workspace' => $pool->workspace_id === null ? null : ($tenants[$pool->workspace_id] ?? '—'),
                'addresses' => $addresses->where('pool', $pool->name)->count(),
            ])->values()->all(),
            'addresses' => $addresses->map(fn (array $ip) => [
                ...collect($ip)->only(['address', 'hostname', 'pool', 'pool_type', 'status', 'warmup_state', 'daily_limit', 'sent_today', 'utilisation', 'reputation_score', 'quarantined_at', 'quarantine_reason'])->all(),
                'blocklist' => collect($blocklist[$ip['address']] ?? [])->map(fn (BlocklistCheck $check) => [
                    'zone' => (string) $check->zone,
                    'listed' => (bool) $check->listed,
                    'checkedAt' => $check->checked_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'rateLimits' => [
                'workspacePerMinute' => (int) config('sending.rate_limit.per_minute'),
                'smtpCredentialPerMinute' => (int) config('smtp.rate_limit.per_credential_per_minute'),
                'receiverDefault' => (int) config('sending.domain_throttle.default'),
                'receivers' => collect((array) config('sending.domain_throttle.limits'))->map(fn ($limit, $domain) => ['domain' => (string) $domain, 'perMinute' => (int) $limit])->values()->all(),
            ],
            // A KÜLDÉSI MÓD kapcsolói — CSAK megjelenítés; az allowlist TARTALMA nem kerül ki.
            'sendingMode' => [
                'live' => (bool) config('sending.live'),
                'marketingEnabled' => (bool) config('sending.marketing_enabled'),
                'allowlistEnforced' => (bool) config('sending.allowlist_enforced'),
                'allowlistSize' => count(array_filter((array) config('sending.allowlist', []))),
            ],
        ]);
    }

    public function audit(Request $request, AuditSearch $search): Response
    {
        $admin = $this->admin();

        $filters = array_filter([
            'action' => $request->string('action')->toString() ?: null,
            'actor' => $request->string('actor')->toString() ?: null,
            'workspace_id' => $request->integer('workspace_id') ?: null,
            'cursor' => $request->integer('cursor') ?: null,
        ], static fn ($value) => $value !== null);

        // A KERESÉS MAGA IS AUDITÁLT — a szolgáltatás írja a sort, nem a kontroller.
        $result = $search->search($admin, $filters);

        return Inertia::render('Platform/Audit', [
            'filters' => $filters,
            'items' => $result['items'],
            'nextCursor' => $result['next_cursor'],
        ]);
    }

    public function startImpersonation(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $admin = $this->admin();

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            // AZ INDOK KÖTELEZŐ: a „csak megnéztem" nem elfogadható indok egy idegen fiók
            // megnyitásához, és egy későbbi vizsgálatnál ez mondja meg, MIÉRT történt.
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $workspace = Workspace::query()->withoutGlobalScopes()->where('uuid', $validated['workspace'])->firstOrFail();

        $impersonation->start($admin, $workspace, $validated['reason']);

        // A NÉZET A TENANT KEZDŐKÉPERNYŐJÉRE visz: a támogatás onnan indul, ahonnan az
        // ügyfél is látja a rendszert.
        return redirect('/dashboard');
    }

    public function endImpersonation(Impersonation $impersonation): RedirectResponse
    {
        $admin = $this->admin();

        $session = $impersonation->active($admin);

        if ($session !== null) {
            $impersonation->end($session);
        }

        return redirect('/platform')->with('success', __('ventmail.platform.impersonationEnded'));
    }

    public function grantOverride(Request $request, PlanOverrides $overrides): RedirectResponse
    {
        $admin = $this->admin();

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'features' => ['array'],
            'features.*' => ['string', Rule::in(array_map(fn (Feature $feature) => $feature->value, Feature::cases()))],
            'sendQuota' => ['nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'expiresAt' => ['required', 'date', 'after:now'],
        ]);

        $workspace = Workspace::query()->withoutGlobalScopes()->where('uuid', $validated['workspace'])->firstOrFail();

        $overrides->grant(
            $admin,
            $workspace,
            array_map(fn (string $value) => Feature::from($value), array_values($validated['features'] ?? [])),
            $validated['sendQuota'] === null ? null : (int) $validated['sendQuota'],
            $validated['reason'],
            Carbon::parse($validated['expiresAt']),
        );

        return back()->with('success', __('ventmail.platform.overrideGranted'));
    }

    public function revokeOverride(string $uuid, PlanOverrides $overrides): RedirectResponse
    {
        $admin = $this->admin();

        $override = PlanOverride::query()->where('uuid', $uuid)->firstOrFail();

        $overrides->revoke($admin, $override);

        return back()->with('success', __('ventmail.platform.overrideRevoked'));
    }

    public function addSuppression(Request $request, GlobalSuppressions $suppressions): RedirectResponse
    {
        $admin = $this->admin();

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:320'],
            'reason' => ['required', 'string', Rule::in(array_map(fn (SuppressionReason $reason) => $reason->value, SuppressionReason::cases()))],
            // AZ INDOKLÁS KÖTELEZŐ: a tiltás MINDEN tenantra hat, és egy későbbi
            // reputáció-vizsgálatnál tudni kell, miért került fel.
            'detail' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $suppressions->add($admin, $validated['email'], SuppressionReason::from($validated['reason']), $validated['detail']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.platform.suppressionAdded'));
    }

    public function removeSuppression(Request $request, string $uuid, GlobalSuppressions $suppressions): RedirectResponse
    {
        $admin = $this->admin();

        $validated = $request->validate(['detail' => ['required', 'string', 'min:3', 'max:500']]);

        $suppression = Suppression::query()->withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        try {
            $suppressions->remove($admin, $suppression, $validated['detail']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.platform.suppressionRemoved'));
    }

    /**
     * A BEJELENTKEZETT PLATFORM-ADMIN — vagy 403.
     *
     * A szolgáltatások mind `User $admin`-t kapnak és ARRA kapuznak (`Gate::forUser`), az
     * audit pedig ugyanazt az aktort rögzíti. A kontroller ezért nem a `Gate::authorize()`
     * kényelmi alakját használja: a felhasználót EXPLICIT adja tovább, hogy a
     * jogosultság-ellenőrzés és a napló ugyanarról az emberről szóljon.
     */
    private function admin(): User
    {
        $user = request()->user();

        if (! $user instanceof User || ! Gate::forUser($user)->allows('platform.admin')) {
            throw new AuthorizationException('Ez a felület platform-admin jogosultságot igényel.');
        }

        return $user;
    }

    /** @return array{workspace: string, reason: string, expiresAt: string}|null */
    private function activeSession(User $admin): ?array
    {
        $session = app(Impersonation::class)->active($admin);

        if ($session === null) {
            return null;
        }

        return [
            'workspace' => (string) ($session->workspace?->name ?? ''),
            'reason' => (string) $session->reason,
            'expiresAt' => $session->expires_at->toIso8601String(),
        ];
    }
}
