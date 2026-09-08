<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Egy seed/inbox-placement mérés (45. kritérium). */
final class SeedTest extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = ['name', 'status', 'inbox', 'spam', 'promotions', 'missing', 'providers', 'started_at', 'finished_at', 'expires_at'];

    protected $attributes = ['status' => 'pending', 'providers' => '{}'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'providers' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $test): void {
            $test->expires_at ??= now()->addDays((int) config('deliverability.seed.retention_days'));
        });
    }

    /**
     * A BEÉRKEZŐ ARÁNY — a mérés lényege.
     *
     * A nevezőbe a HIÁNYZÓ is beleszámít. Aki csak a megérkezett leveleket osztja, az a
     * legrosszabb esetet (sehova nem jutott el) egyszerűen kihagyja a statisztikából — és
     * pont az a szám lesz szép, aminek a romlását észre kellene venni.
     */
    public function inboxRate(): float
    {
        $total = (int) $this->inbox + (int) $this->spam + (int) $this->promotions + (int) $this->missing;

        return $total === 0 ? 0.0 : round((int) $this->inbox / $total * 100, 2);
    }

    public function spamRate(): float
    {
        $total = (int) $this->inbox + (int) $this->spam + (int) $this->promotions + (int) $this->missing;

        return $total === 0 ? 0.0 : round((int) $this->spam / $total * 100, 2);
    }
}
