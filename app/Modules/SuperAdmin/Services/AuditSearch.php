<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * AUDIT-KERESŐ (85. kritérium) — és a keresés MAGA is auditált.
 *
 * ## Miért auditáljuk a keresést
 *
 * Mert az audit-napló a rendszer legérzékenyebb olvasható adata: minden admin-művelet
 * nyoma, minden tenantnál. Aki ezt böngészi, az a platform teljes történetét látja — és
 * ennek a hozzáférésnek MAGÁNAK is nyomot kell hagynia. A „ki nézte meg, mit" kérdés egy
 * incidensnél pontosan olyan fontos, mint a „ki írta át".
 *
 * A keresés-audit ezért a SZŰRŐKET rögzíti, nem a találatokat: a találatok maguk
 * audit-sorok (megvannak), a szűrő viszont a SZÁNDÉKOT mutatja („X tenant Y műveletét
 * kereste Z időszakban").
 *
 * ## Nyers személyes adat NEM megy ki
 *
 * Az audit-sorok `before`/`after`/`context` mezőit az `AuditLogger` már írás közben
 * redaktálja. A kereső a TÁROLT alakot adja tovább — nem fejt vissza semmit, és nem is
 * tudna: a titkok helyén `[redacted]` áll, az e-mail-címek helyén pedig
 * `[pii:email:<hash>]` (a `AuditLogger::pseudonymiseEmails()` a choke pointon cseréli).
 *
 * Ez az állítás korábban HAMIS volt: a redakció kizárólag titok-MEZŐNEVEKRE ment, a
 * `context` szabad szöveges mezőin (`note`, `recipient`) a nyers cím átment — a
 * security-review meg is mérte. A hash a beírás pillanatában készül, tehát a kereső
 * valóban nem tud nyers címet visszaadni; a szabad szöveges, minta nélküli PII-re pedig a
 * retenció (`audit:prune`) a válasz.
 */
final class AuditSearch
{
    /** Egy lapon ennyi találat. */
    public const PER_PAGE = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{action?: ?string, workspace_id?: ?int, actor?: ?string, from?: ?Carbon, to?: ?Carbon, cursor?: ?int}  $filters
     * @return array{items: list<array<string, mixed>>, next_cursor: ?int}
     *
     * @throws AuthorizationException
     */
    public function search(User $admin, array $filters = []): array
    {
        if (! Gate::forUser($admin)->allows('platform.admin')) {
            // AZ ELUTASÍTOTT PRÓBÁLKOZÁS IS AUDITÁLT (81. kritérium): aki a MÁSOK naplójában
            // próbál keresni jogosultság nélkül, arról legyen sor.
            $this->audit->log(
                'platform.denied',
                null,
                context: ['operation' => 'audit_search', 'admin_id' => (int) $admin->getKey()],
                workspaceId: AuditLogger::NO_WORKSPACE,
                actor: $admin,
            );

            throw new AuthorizationException('Az audit-kereső platform-admin jogosultságot igényel.');
        }

        /*
         | NINCS scope-kikapcsolás, mert nincs mit kikapcsolni: az `AuditLog`
         | SZÁNDÉKOSAN nem `BelongsToWorkspace` (a naplónak akkor is íródnia kell, amikor
         | nincs kontextus). A tenant-szűrő ezért itt EXPLICIT `where`, amit a hívó ad meg —
         | a platform-admin épp azért kérdez, hogy tenanteken átnyúlva lásson.
         */
        $query = AuditLog::query();

        if (($action = $filters['action'] ?? null) !== null && $action !== '') {
            /*
             | ELŐTAG-egyezés: a `platform.` prefix az összes platform-műveletet adja, a
             | teljes név pedig egyet. A `like`-ba menő érték kötött paraméter.
             |
             | A `%` és a `_` ESCAPE-ELVE: enélkül a `%` szűrő MINDEN sort ad (mérve), a `_`
             | pedig bármelyik karakterre illeszt. Nem injekció (kötött paraméter) és nem is
             | szivárgás (a platform-admin szűrő nélkül is mindent lát) — de egy szűrő, ami
             | csendben mást keres, mint amit beírtak, hibás találati listát ad egy
             | incidens-vizsgálatban.
             */
            $query->where('action', 'like', addcslashes($action, '%_\\').'%');
        }

        if (($workspaceId = $filters['workspace_id'] ?? null) !== null) {
            $query->where('workspace_id', (int) $workspaceId);
        }

        if (($actor = $filters['actor'] ?? null) !== null && $actor !== '') {
            // AZ AKTOR a denormalizált `actor_email`: a felhasználó törlése után is
            // azonosítható marad, ki végezte a műveletet.
            $query->where('actor_email', $actor);
        }

        if (($from = $filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $filters['to'] ?? null) !== null) {
            $query->where('created_at', '<=', $to);
        }

        /*
         | KURZOROS lapozás, csökkenő id szerint.
         |
         | Az audit-tábla FOLYAMATOSAN NŐ, és offset-lapozásnál a második oldal lekérésekor
         | a sorok elcsúsznak — a kereső ugyanazt kétszer adná, vagy sosem látna egy sort.
         | Ugyanaz az érv, mint az A3 Events API-jánál.
         */
        if (($cursor = $filters['cursor'] ?? null) !== null) {
            $query->where('id', '<', (int) $cursor);
        }

        $rows = $query->orderByDesc('id')->limit(self::PER_PAGE + 1)->get();

        $hasMore = $rows->count() > self::PER_PAGE;
        $items = $rows->take(self::PER_PAGE);

        $this->audit->log(
            'platform.audit_searched',
            null,
            context: [
                'filters' => array_filter([
                    'action' => $filters['action'] ?? null,
                    'workspace_id' => $filters['workspace_id'] ?? null,
                    'actor' => $filters['actor'] ?? null,
                    'from' => ($filters['from'] ?? null)?->toIso8601String(),
                    'to' => ($filters['to'] ?? null)?->toIso8601String(),
                ], static fn ($value) => $value !== null && $value !== ''),
                'results' => $items->count(),
                'admin_id' => (int) $admin->getKey(),
            ],
            // PLATFORM-SZINTŰ olvasás: a nyoma NEM tartozik egyetlen tenanthoz sem. A
            // kontextusból örökölt `workspace_id` mérve egy VÉLETLEN tenant naplójába
            // vitte a tenanteken átnyúló keresést.
            workspaceId: AuditLogger::NO_WORKSPACE,
            actor: $admin,
        );

        return [
            'items' => $items->map(static fn (AuditLog $log): array => [
                'id' => (int) $log->getKey(),
                'action' => (string) $log->action,
                'workspace_id' => $log->workspace_id === null ? null : (int) $log->workspace_id,
                'actor_email' => $log->actor_email,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                // A TÁROLT alak megy tovább: az `AuditLogger` már írás közben redaktált.
                'before' => (array) $log->before,
                'after' => (array) $log->after,
                'context' => (array) $log->context,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $hasMore ? (int) $items->last()?->getKey() : null,
        ];
    }
}
