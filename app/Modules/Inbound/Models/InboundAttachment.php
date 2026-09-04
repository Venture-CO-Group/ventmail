<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Egy csatolmány metaadata (35. kritérium).
 *
 * A TARTALOM a fájltárban van, és CSAK akkor tölthető le, ha a vírus-szkennelés tisztának
 * találta. A `scan_result` alapértéke `pending`: amíg nem szkenneltünk, nem adjuk ki —
 * a fail-open itt azt jelentené, hogy a szkenner kiesése idején mi terjesztjük a kártevőt.
 */
final class InboundAttachment extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'inbound_message_id', 'filename', 'content_type', 'size', 'sha256',
        'storage_key', 'scan_result', 'scan_detail',
    ];

    protected $attributes = ['scan_result' => 'pending'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'inbound_message_id');
    }

    /** Letölthető-e? CSAK a tisztának bizonyult csatolmány. */
    public function downloadable(): bool
    {
        return $this->scan_result === 'clean' && ($this->storage_key ?? '') !== '';
    }
}
