<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy dimenzió fogyása egy periódusban.
 *
 * Nincs `Auditable`: a fogyás-sor GÉPI adat, ami minden küldésnél nő — nem admin-művelet.
 * Az admin-műveletet (a periódus lezárását, egy plan-override-ot) a `Invoice`, illetve a
 * C2 super-admin auditálja. Soronkénti audit itt a törölhetetlen audit-táblát tömné meg
 * forgalommal.
 */
final class UsageRecord extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['period', 'dimension', 'value', 'closed_at'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'closed_at' => 'datetime'];
    }

    public function dimension(): UsageDimension
    {
        return UsageDimension::from((string) $this->dimension);
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
