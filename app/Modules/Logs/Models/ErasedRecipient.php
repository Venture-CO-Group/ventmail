<?php

declare(strict_types=1);

namespace App\Modules\Logs\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy teljesített érintetti törlési kérés NYOMA (GDPR Art. 17).
 *
 * Miért kell a nyom: a törlés különben visszafordítható. A már kiküldött levélben ott a
 * nyomkövetési pixel, ami titkosítva hordozza a címzettet — egy betöltés új eseményt írna a
 * törölt címmel, és a webhookon harmadik félhez is kiküldené. Az Apple MPP és a Gmail
 * képproxyja ezt felhasználói szándék nélkül, magától megteszi.
 *
 * A tábla a cím HASH-ét tárolja, nem a címet. Egy törlési kérés nyilvántartása nem lehet maga
 * is egy címlista — és az egyezés-vizsgálathoz a hash elég.
 */
final class ErasedRecipient extends Model
{
    // AUDITÁLT: egy törlési kérés teljesítése bizonyítandó tény — az adatvédelmi
    // hatóságnak épp azt kell tudnunk megmutatni, hogy MIKOR és KI teljesítette.
    use Auditable, BelongsToWorkspace;

    protected $fillable = ['recipient_hash', 'erased_at'];

    protected function casts(): array
    {
        return ['erased_at' => 'datetime'];
    }

    public static function hash(string $email): string
    {
        return hash('sha256', $email);
    }

    public static function erased(string $email): bool
    {
        return self::query()->where('recipient_hash', self::hash($email))->exists();
    }
}
