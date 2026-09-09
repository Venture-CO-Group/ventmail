<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Audit-sor. MEGVÁLTOZTATHATATLAN: aki egyszer beírt, azt nem lehet átírni vagy törölni —
 * különben a napló pont akkor lenne hamisítható, amikor a legfontosabb (golden rule 11).
 *
 * Szándékosan NEM használ `BelongsToWorkspace`-t: a naplónak akkor is íródnia kell, amikor
 * nincs workspace-kontextus (pl. rendszerszintű művelet), és a super-admin (C2)
 * tenant-független kereséssel dolgozik.
 *
 * EZÉRT: minden tenant-felületű olvasás KÖTELEZŐEN a `forWorkspace()` scope-on megy —
 * enélkül az első napló-képernyő minden tenant `before`/`after` payloadját visszaadná.
 */
final class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'user_id', 'actor_email', 'actor_sso_subject',
        'action', 'auditable_type', 'auditable_id',
        'before', 'after', 'context', 'ip_hash', 'user_agent',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * Számláló, nem bool: egymásba ágyazott hívásnál a belső blokk nem kapcsolhatja
     * vissza a külsőt a közepén (ugyanaz a minta, mint a `onPlatform()`-nál).
     */
    private static int $pruningDepth = 0;

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Az audit-napló nem módosítható.');
        });

        self::deleting(function (): void {
            if (self::$pruningDepth === 0) {
                throw new RuntimeException(
                    'Az audit-napló nem törölhető — az EGYETLEN törlési út a retenciós '
                    .'lejárat (AuditLog::whileRetentionPruning).'
                );
            }
        });
    }

    /**
     * A RETENCIÓS LEJÁRAT szándéknyilvánító hívása — az egyetlen út, amin audit-sor törölhető.
     *
     * ## Miért kell egyáltalán törlési út
     *
     * A napló megváltoztathatatlansága és a tárolási korlátozás (GDPR 5(1)(e)) EGYSZERRE
     * igaz: a sor nem írható át, de nem is állhat ott örökre. A `context` szabad szöveges
     * mezőin (támogatói jegyzet) olyan személyes adat is bekerülhet, amit az `AuditLogger`
     * minta-alapú pszeudonimizálása nem ismer fel — arra a lejárat a válasz.
     *
     * ## Miért nem elég ez a PHP-oldali kapu
     *
     * Nem is akar elég lenni. A builder-úton (`AuditLog::query()->delete()`) a modell-event
     * NEM fut le, ezért a valódi határ a DB-triggerben van: az `ventmail.audit_prune`
     * session-változót ez a metódus állítja be, és a trigger ezen KÍVÜL még egy 365 napos
     * alsó időhatárt is követel. Egy elgépelt retenciós nap-szám így sem törölhet friss sort.
     *
     * A `set local` TRANZAKCIÓT igényel: tranzakció nélkül a beállítás elveszik, a trigger
     * pedig elutasít — vagyis a hívási hiba fail-closed, nem csendes törlés.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileRetentionPruning(callable $callback): mixed
    {
        self::$pruningDepth++;

        DB::statement("set local ventmail.audit_prune = 'on'");

        try {
            return $callback();
        } finally {
            self::$pruningDepth--;

            if (self::$pruningDepth === 0) {
                /*
                 | A JELZŐ VISSZAVÉTELE — a `set local` a TRANZAKCIÓ végéig élne, nem a
                 | callbackéig. Enélkül ugyanabban a tranzakcióban minden későbbi
                 | builder-DELETE átmenne a triggeren (a modell-event a builder-úton nem
                 | fut), tehát a „szűk ajtó" a valóságban tranzakció-szintű lenne, nem
                 | hívás-szintű. A 365 napos alsó határ tartaná a kárt, de a szándék-jelzés
                 | pont attól jelzés, hogy pontosan addig tart, ameddig a szándék.
                 */
                DB::statement("set local ventmail.audit_prune = 'off'");
            }
        }
    }

    /**
     * Tenant-felületű olvasás. Argumentum nélkül az aktuális kontextust használja;
     * kontextus nélkül egyetlen sort sem ad vissza (fail-closed).
     */
    public function scopeForWorkspace(Builder $query, Workspace|int|null $workspace = null): Builder
    {
        $workspaceId = match (true) {
            $workspace instanceof Workspace => $workspace->getKey(),
            is_int($workspace) => $workspace,
            default => app(WorkspaceContext::class)->hasWorkspace()
                ? app(WorkspaceContext::class)->workspaceId()
                : null,
        };

        if ($workspaceId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('workspace_id', $workspaceId);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
