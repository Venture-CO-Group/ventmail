<?php

declare(strict_types=1);

namespace App\Modules\Audience\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * EGYÉNI MEZŐ definíciója (v1.1 import).
 *
 * A kontakt értéke továbbra is az `attributes` JSON-ban él, a KULCS alatt; ez a sor azt
 * mondja meg, hogy a kulcs létezik, hogyan hívják az embernek, és milyen típusú (az import
 * ez alapján ellenőrzi az értéket). A `key` zárt alakú (`[a-z][a-z0-9_]{0,39}`): JSON-útvonalként
 * kerül a szegmens-lekérdezésbe, ezért nem lehet benne idézőjel.
 */
final class ContactField extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    public const TYPES = ['text', 'number', 'date', 'boolean'];

    /** A beépített attribútum-kulcsok: egyéni mező nem foglalhatja el őket. */
    public const RESERVED = ['email', 'first_name', 'last_name', 'company', 'phone', 'status', 'name', 'id', 'uuid'];

    protected $fillable = ['key', 'label', 'type'];

    /**
     * v1.1 (review B1) · a mező a preference centerben látszik és a címzett írhatja — ALAPBÓL
     * NEM. Szándékosan nem `fillable`: csak a mezőkezelő kapcsolója írja.
     */
    protected $attributes = ['preference_visible' => false];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['preference_visible' => 'boolean'];
    }
}
