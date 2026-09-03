<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Webhook-végpont: hova küldjük az eseményeket.
 *
 * A TITKOT titkosítva tároljuk, nem hash-elve: az aláíráshoz a NYERS érték kell (HMAC).
 * A `Crypt` az `APP_KEY`-hez köti, tehát egy adatbázis-szivárgás önmagában nem elég ahhoz,
 * hogy valaki érvényes aláírást gyártson a nevünkben — ahhoz a kulcs is kell.
 */
final class WebhookEndpoint extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'url', 'secret', 'events', 'active'];

    protected $hidden = ['secret'];

    /** A végpont ALAPÉRTELMEZÉSBEN aktív — a DB-alapérték a modellben nem látszana. */
    protected $attributes = ['active' => true, 'events' => '[]'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** A nyers titok — csak az aláíráshoz, sosem a válaszban. */
    public function signingSecret(): string
    {
        return Crypt::decryptString((string) $this->getAttributes()['secret']);
    }

    public function setSecretAttribute(string $value): void
    {
        $this->attributes['secret'] = Crypt::encryptString($value);
    }

    /**
     * Létrehozás — a titok EGYSZER látszik.
     *
     * @return array{endpoint: self, secret: string}
     */
    public static function issue(string $name, string $url, array $events): array
    {
        $secret = 'whsec_'.Str::random(40);

        return [
            'endpoint' => self::create([
                'name' => $name,
                'url' => $url,
                'events' => $events,
                'secret' => $secret,
                'active' => true,
            ]),
            'secret' => $secret,
        ];
    }

    public function listensTo(string $eventType): bool
    {
        // Üres lista = MINDEN esemény. Az explicit lista szűkít.
        return $this->active && ($this->events === [] || in_array($eventType, (array) $this->events, true));
    }
}
