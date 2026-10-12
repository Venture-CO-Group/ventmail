<?php

declare(strict_types=1);

namespace App\Modules\Sending\Models;

use Illuminate\Database\Eloquent\Model;

/** Egy feldolgozott Postal-webhook uuid-ja — az újrapróbált kézbesítés egyszer hat. */
final class PostalWebhookReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['pool', 'uuid', 'event', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
