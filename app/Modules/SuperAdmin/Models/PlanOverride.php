<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy csomag-felülírás (83. kritérium).
 *
 * ## Az override KIVÉTEL, nem állapot
 *
 * Ezért van LEJÁRATA. A támogatás megemeli egy tenant kvótáját egy kampány idejére, vagy
 * bekapcsol egy funkciót próbaidőre — és utána MAGÁTÓL vissza kell állnia. A visszavonás
 * nélküli override az, amit senki nem vesz vissza: fél év múlva már senki nem tudja, miért
 * van egy tenantnak ingyen dedikált IP-je.
 *
 * ## A ventPay-t NEM írja át
 *
 * Az override a ventMail oldalán ÜL RÁ a ventPay válaszára; a csomag gazdája a ventPay
 * marad (egy képesség, egy gazda). Ha a ventMail írná át a csomagot, két rendszer mondaná
 * meg, mit fizet a tenant.
 *
 * Nem `BelongsToWorkspace`: ez platform-adat a tenantRÓL, nem a tenantÉ — a tenant nem
 * adhat magának override-ot, és nem is törölheti.
 */
final class PlanOverride extends Model
{
    use HasUuids;

    /*
     | A `workspace_id` SZÁNDÉKOSAN nem `fillable` — lásd a `TenantModelGuardTest`
     | szabályát: a tenant-azonosító sosem jöhet tömeges hozzárendelésből. Itt az ÉRINTETT
     | tenantra mutat, tehát egy kérésből érkező érték azt jelentené, hogy a kérő választja
     | meg, kinek a csomagját írja felül.
     */
    /*
     | A `created_by` SEM `fillable` — ugyanaz az érv, mint a `workspace_id`-nél.
     |
     | Ez a mező a „KI ADTA" válasza, és a review megmérte, hogy tömeges hozzárendelésből
     | hamisítható volt: `new PlanOverride(['created_by' => $tenantId])` → a sor egy tenant
     | felhasználóját nevezte meg az adományozónak. Egy jogosultság-emelés
     | elszámoltathatósága nem függhet attól, hogy a jövőben melyik kontroller mit ad át:
     | a `PlanOverrides::grant()` EXPLICITEN, a gate-elt `$admin`-ból írja.
     */
    protected $fillable = [
        'features', 'send_quota',
        'reason', 'expires_at', 'revoked_at',
    ];

    protected $attributes = ['features' => '[]'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'send_quota' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
