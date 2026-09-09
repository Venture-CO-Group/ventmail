<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy platform-admin impersonation-munkamenete (82. kritérium).
 *
 * ## Miért NEM `BelongsToWorkspace`
 *
 * Mert ez a PLATFORM adata, nem a tenanté: a `workspace_id` itt a MEGNÉZETT tenantra
 * mutat, nem a sor tulajdonosára. A tenant a tényt a SAJÁT audit-naplójában látja — ott
 * viszont látnia KELL, mert egy idegen ember megnyitotta a fiókját.
 *
 * ## Nincs `Auditable`
 *
 * Az auditálást az `Impersonation` szolgáltatás írja EXPLICITEN, a kezdésre és a
 * lezárásra külön sorral — a modell `created`/`updated` hookja nem tudná megmondani,
 * hogy egy `ended_at` írás lezárás volt-e vagy lejárat-korrekció, és a tenant
 * audit-naplójába sem tudná a helyes `workspace_id`-t tenni.
 */
final class ImpersonationSession extends Model
{
    use HasUuids;

    /*
     | A `workspace_id` SZÁNDÉKOSAN nem `fillable`.
     |
     | A platform-szabály az egész kódbázisban ez (a `TenantModelGuardTest` őrzi): a
     | tenant-azonosító SOHA nem jöhet tömeges hozzárendelésből. Itt a mező a MEGNÉZETT
     | tenantra mutat, tehát egy `$request->all()`-ból érkező érték azt jelentené, hogy a
     | kérő választja meg, kinek a fiókját nyitja meg.
     */
    protected $fillable = ['user_id', 'reason', 'started_at', 'expires_at', 'ended_at'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * AKTÍV-e ez a munkamenet?
     *
     * KÉT feltétel, és mindkettő kell: nincs lezárva ÉS nem járt le. A lejárt munkamenet
     * `ended_at` nélkül is halott — enélkül egy elfelejtett impersonation hetekig élne.
     */
    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
