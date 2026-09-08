<?php

declare(strict_types=1);

namespace App\Modules\Audience\Models;

use App\Modules\Audience\Jobs\SyncContactToIndex;
use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Egy kontakt a közönségben (49. kritérium).
 *
 * A CÍM az azonosító, és mindig NORMALIZÁLVA tárolódik — ugyanazzal a `listKey()`-jel, amit a
 * suppression és a consent használ. Enélkül a `Anna@pelda.hu` és az `anna@pelda.hu` két
 * kontakt lenne, két külön consent-állapottal, és a marketing kimenne arra, amelyik épp
 * „megerősített".
 *
 * A `status` a Mailchimp-paritás állapotgépe: `pending` (double opt-in folyamatban) →
 * `subscribed` → `unsubscribed`, illetve `cleaned` a kézbesíthetetlen (hard bounce) cím. A
 * `subscribed` állapot ÖNMAGÁBAN nem elég a marketinghez: a jogalapot a `consents` tábla adja,
 * és a PreSendGate 3. kapuja azt kérdezi meg — a kettő szándékosan külön él, mert egy
 * importált lista „subscribed" lehet érvényes hozzájárulás nélkül.
 */
final class Contact extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = [
        'email', 'first_name', 'last_name', 'attributes', 'status', 'external_id',
        'validation_verdict', 'validation_risk', 'subscribed_at', 'unsubscribed_at',
        'last_synced_at',
    ];

    /** Az audit-tábla sosem törölhető — érintetti e-mail-cím nem kerülhet bele. */
    public array $auditRedacted = ['email'];

    protected $attributes = ['status' => 'pending', 'attributes' => '{}'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'validation_risk' => 'float',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Normalizálás MUTATORBAN, nem `creating` hookban: különben a `firstOrCreate` a nyers
     * értékkel keresne (nem talál), normalizálva írna, és a második hívás
     * unique-violationnel elszállna — épp az importálás közben.
     */
    public function setEmailAttribute(?string $value): void
    {
        $normalized = DomainNormalizer::listKey(trim((string) $value));

        $this->attributes['email'] = $normalized;
        $this->attributes['email_hash'] = KeyedHash::of('email', $normalized);
    }

    /**
     * Az ÜRES `external_id` NULL, nem üres sztring.
     *
     * A `(workspace_id, external_id)` egyedi: üres sztringgel a MÁSODIK, ventOS-azonosító
     * nélküli kontakt beszúrása elszállna — vagyis egyetlen kézzel felvett cím megakadályozná
     * a következőt. A `NULL` a Postgresben nem ütközik önmagával.
     */
    public function setExternalIdAttribute(?string $value): void
    {
        $trimmed = trim((string) $value);

        $this->attributes['external_id'] = $trimmed === '' ? null : $trimmed;
    }

    /**
     * A PIVOT-SOR IS WORKSPACE-HEZ KÖTÖTT — és a `workspace_id`-t a relációnak kell kitöltenie.
     *
     * A pivot-táblán a `workspace_id` redundáns (a `contact_id`-ból levezethető), de nem
     * dísz: nélküle a listánkénti lekérdezés (`hány feliratkozó van?`) csatlakozásra
     * kényszerülne, és a tenant-szűrés a JOIN-on múlna. A `withPivotValue` azért kell, mert
     * a `belongsToMany` nem tud a saját pivotján lévő extra oszlopról — az `attach()` NULL-t
     * írna, és a beszúrás elhasalna.
     *
     * A KONTEXTUSBÓL vesszük, nem a modell `workspace_id`-jából: ugyanaz a szabály, mint a
     * `BelongsToWorkspace`-nél — a tenant-határ nem a hívó paramétere.
     */
    protected static function booted(): void
    {
        // A KERESŐINDEX frissítése (49. kritérium) — ASZINKRON, `saved` és `deleted` után.
        //
        // Miért a modellben és nem a hívási helyeken: a kontakt HAT úton keletkezik vagy
        // változik (űrlap, import, CRM-szinkron, preference center, megerősítés, API), és
        // ami minden hívási helyen a fejlesztő figyelmén múlik, az valahol kimarad. Az A6
        // négy köre pontosan ezt tanította meg.
        //
        // A job azért kell, mert a keresőindex SOHA nem lehet a mentés előfeltétele: egy
        // Meili-kimaradás nem lehet „a feliratkozás nem sikerült" az űrlapon.
        self::saved(function (self $contact): void {
            SyncContactToIndex::dispatch((string) $contact->uuid, (int) $contact->workspace_id);
        });

        self::deleted(function (self $contact): void {
            SyncContactToIndex::dispatch((string) $contact->uuid, (int) $contact->workspace_id, true);
        });
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class, 'contact_list_memberships')
            ->withPivot(['status', 'subscribed_at', 'unsubscribed_at'])
            ->withPivotValue('workspace_id', $this->workspaceIdForPivot())
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ContactTag::class, 'contact_tag_assignments')
            ->withPivotValue('workspace_id', $this->workspaceIdForPivot())
            ->withTimestamps();
    }

    /**
     * A pivot `workspace_id`-ja.
     *
     * A KONTEXTUS az elsődleges forrás; ha nincs (pl. egy már betöltött modellen olvasunk
     * kontextus nélkül), a saját `workspace_id` a tartalék. A `withPivotValue` a
     * lekérdezésre IS szűkít, tehát ez egyben tenant-szűrő — egy idegen workspace pivot-sora
     * ezen a relációon nem is látszik.
     */
    private function workspaceIdForPivot(): int
    {
        $context = app(WorkspaceContext::class);

        return $context->hasWorkspace() ? $context->workspaceId() : (int) $this->workspace_id;
    }

    /** A marketingre ELÉRHETŐ kontaktok — a szegmens-motor és a kampány-célzás alapja. */
    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereIn('status', ['subscribed', 'pending'])->whereNull('deleted_at');
    }

    public function isSubscribed(): bool
    {
        return $this->status === 'subscribed';
    }
}
