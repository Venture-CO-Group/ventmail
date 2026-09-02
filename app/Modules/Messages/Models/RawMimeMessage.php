<?php

declare(strict_types=1);

namespace App\Modules\Messages\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A beadott nyers MIME-levél.
 *
 * A levél MAGA — ha nem őrizzük meg, nincs mit kiküldeni és nincs mit trace-elni.
 * Megváltoztathatatlan: ami egyszer beérkezett, azt utólag nem írjuk át.
 */
final class RawMimeMessage extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['message_uuid', 'raw', 'size', 'sha256'];

    /**
     * A Postgres `bytea` oszlopot a PDO STREAM-ként adja vissza — a hívónak sztring kell.
     */
    public function getRawAttribute(mixed $value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        return (string) $value;
    }

    protected static function booted(): void
    {
        self::creating(function (self $mime): void {
            $raw = (string) $mime->getAttributes()['raw'];

            $mime->size = strlen($raw);
            $mime->sha256 = hash('sha256', $raw);
        });

        self::updating(function (): never {
            throw new \RuntimeException('A beadott MIME-levél nem írható át.');
        });
    }
}
