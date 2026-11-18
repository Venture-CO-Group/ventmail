<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
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

    /**
     * A `secret` SZÁNDÉKOSAN nincs itt.
     *
     * A titkot mi generáljuk (`issue()`), és csak mi. Ha tömegesen kitölthető lenne, az
     * első `create($request->validated())` engedné az ügyfélnek a saját, gyenge titkát —
     * és az aláírásunk annyit érne, amennyit az ő „titka".
     */
    protected $fillable = ['name', 'url', 'events', 'active', 'redact_recipient'];

    protected $hidden = ['secret', 'previous_secret'];

    /** A végpont ALAPÉRTELMEZÉSBEN aktív — a DB-alapérték a modellben nem látszana. */
    protected $attributes = ['active' => true, 'events' => '[]', 'redact_recipient' => false];

    protected static function booted(): void
    {
        // A VÉGPONT mentésekor is ellenőrzünk: a hibát ott kell jelezni, ahol az ügyfél
        // beírja, nem a hatodik sikertelen kézbesítésnél.
        self::saving(function (self $endpoint): void {
            if ($endpoint->isDirty('url')) {
                app(WebhookUrlGuard::class)->assertAllowed((string) $endpoint->url);
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'active' => 'boolean',
            'redact_recipient' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'previous_secret_expires_at' => 'datetime',
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
     * A RÉGI titok a rotációs türelmi ablakban (D171) — különben `null`.
     *
     * Az ablak alatt a kézbesítés a régi titokkal is aláír (`X-VentMail-Signature-Previous`),
     * így a fogadó a saját tempójában cserélheti a titkot. Lejárat után a régi titok
     * SEHOL nem használt (a tárolt alakot a következő rotáció felülírja).
     */
    public function previousSigningSecret(): ?string
    {
        $raw = $this->getAttributes()['previous_secret'] ?? null;

        if ($raw === null || $this->previous_secret_expires_at === null || $this->previous_secret_expires_at->isPast()) {
            return null;
        }

        return Crypt::decryptString((string) $raw);
    }

    /**
     * Titok-csere — az új titok EGYSZER látszik (a visszatérési érték).
     *
     * `$graceHours = 0`: a régi titok AZONNAL érvénytelen (szivárgásnál ez kell; a `/v1`
     * rotáció ezt használja, változatlan szerződéssel). `> 0`: a régi titok ennyi óráig a
     * második aláírásban még szerepel.
     */
    public function rotateSecret(int $graceHours = 0): string
    {
        $secret = 'whsec_'.Str::random(40);
        $current = $this->getAttributes()['secret'] ?? null;

        // A régi titok TITKOSÍTOTT alakja költözik át — nyers alakban sosem kerül memóriába.
        $this->forceFill([
            'previous_secret' => $graceHours > 0 ? $current : null,
            'previous_secret_expires_at' => $graceHours > 0 ? now()->addHours($graceHours) : null,
        ]);
        $this->secret = $secret;
        $this->save();

        return $secret;
    }

    /** A titok utolsó négy karaktere — a felület ebből ismeri fel, melyik titok él. */
    public function secretHint(): string
    {
        try {
            return substr($this->signingSecret(), -4);
        } catch (\Throwable) {
            return '????';
        }
    }

    /**
     * Létrehozás — a titok EGYSZER látszik.
     *
     * @return array{endpoint: self, secret: string}
     */
    public static function issue(string $name, string $url, array $events, bool $redactRecipient = false): array
    {
        $secret = 'whsec_'.Str::random(40);

        return [
            'endpoint' => tap(self::make([
                'name' => $name,
                'url' => $url,
                'events' => $events,
                'active' => true,
                'redact_recipient' => $redactRecipient,
            ]), function (self $endpoint) use ($secret): void {
                // A titok NEM tömegesen kitölthető: itt, explicit adjuk meg.
                $endpoint->secret = $secret;
                $endpoint->save();
            }),
            'secret' => $secret,
        ];
    }

    public function listensTo(string $eventType): bool
    {
        // Üres lista = MINDEN esemény. Az explicit lista szűkít.
        return $this->active && ($this->events === [] || in_array($eventType, (array) $this->events, true));
    }
}
