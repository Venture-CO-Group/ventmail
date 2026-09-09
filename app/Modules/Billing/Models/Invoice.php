<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ELSZÁMOLÁSI KIVONAT — nem számla.
 *
 * A ventMail a FOGYÁST rögzíti, nem a pénzt: nincs itt ár, adó, fizetési mód, és
 * kártyaadat SOHA. A ventPay ebből a kivonatból számláz (golden rule: egy képesség, egy
 * gazda). Ha a ventMail árat tartana, két rendszer mondaná meg, mennyibe kerül egy
 * küldés — és a kettő elcsúszna.
 *
 * A `dunning_state` szintén TÜKÖR: a ventPay dönti el, hogy valaki fizetett-e; a ventMail
 * csak azt tudja, hogy emiatt küldhet-e. Ezért van `Auditable`: a lezárás és az
 * állapotváltás üzemeltetői/pénzügyi döntés, aminek nyoma kell.
 */
final class Invoice extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'parent_workspace_id', 'period', 'usage',
        'dunning_state', 'due_at', 'paid_at', 'suspended_at', 'closed_at',
    ];

    protected $attributes = ['usage' => '{}', 'dunning_state' => 'open'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function parentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'parent_workspace_id');
    }
}
