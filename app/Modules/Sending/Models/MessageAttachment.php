<?php

declare(strict_types=1);

namespace App\Modules\Sending\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy kimenő csatolmány bájtjai — csak a kiküldésig / az újrapróbálás végéig (H123).
 *
 * A tartalom PII (egy fan fotója, egy számla), ezért:
 * - TITKOSÍTVA nyugszik (`encrypted` cast, APP_KEY) — egy DB-dump vagy -mentés nem viszi ki
 *   olvashatóan;
 * - a `content` soha nem szerializálódik (`$hidden`), tehát API-válaszba vagy naplóba sem;
 * - a lezárt üzenet sorai azonnal törlődnek (`Message` hook), a lejártakat a
 *   `messages:purge-attachments` takarítja;
 * - a lenyomat KULCSOLT (a nyers SHA-256 egy ismert fotó „elküldték-e?" kérdésére
 *   felelne).
 */
final class MessageAttachment extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['message_uuid', 'position', 'filename', 'content_type', 'size', 'sha256', 'content', 'expires_at'];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
            'position' => 'integer',
            'size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
