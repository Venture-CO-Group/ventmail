<?php

declare(strict_types=1);

namespace App\Modules\Sending\Models;

use App\Modules\IpPools\Models\IpAddress;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy kézbesítési kísérlet és a fogadó VÁLASZA.
 *
 * Ez a „miért nem érkezett meg?" nyersanyaga (A3 trace): melyik IP-ről, hányadik
 * próbálkozásra, milyen kóddal és szöveggel. A válasz-szöveg a fogadóé — PII-t is
 * tartalmazhat, ezért lejárati ideje van (golden rule 10).
 */
final class DeliveryAttempt extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'message_uuid', 'recipient', 'attempt', 'code', 'enhanced_code',
        'response', 'classification', 'ip_address_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $attempt): void {
            $attempt->expires_at ??= now()->addDays((int) config('sending.delivery_attempt_retention_days'));
        });
    }

    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }
}
