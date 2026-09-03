<?php

declare(strict_types=1);

namespace App\Modules\Logs\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy SMTP-párbeszéd a fogadó MTA-val.
 *
 * A hitelesítési sorok SOHA nem kerülnek ide: azokat a session már redaktálva adja át
 * (`AUTH PLAIN <redacted>`) — a base64-elt sor maga a jelszó.
 */
final class SmtpTransaction extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'message_uuid', 'recipient', 'ip_address_id', 'remote_host',
        'response_code', 'enhanced_code', 'response_text', 'lines',
        'started_at', 'finished_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $transaction): void {
            // PII-tároló: a párbeszéd címzett-címet és a fogadó szövegét tartalmazza.
            $transaction->expires_at ??= now()->addDays((int) config('sending.delivery_attempt_retention_days'));
        });
    }
}
