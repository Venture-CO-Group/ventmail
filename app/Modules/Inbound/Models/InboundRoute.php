<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Egy bejövő route-szabály (30. kritérium).
 *
 * A SORREND a szabály lelke: prioritás szerint, azon belül `id` szerint. Az azonos
 * prioritásúak közti `id`-rendezés nem kozmetika — enélkül a kiértékelés a Postgres
 * pillanatnyi kedvétől függne, és ugyanaz a levél ma máshova menne, mint holnap.
 */
final class InboundRoute extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    public const MATCH_TYPES = ['match_recipient', 'match_header', 'catch_all'];

    // A `journey` akció a B3-ból (69. kritérium): egy beérkező levél automatizálást
    // indíthat. A cél a journey uuid-ja — üresen MINDEN `inbound_email` triggerű journey.
    public const ACTION_TYPES = ['forward', 'store', 'stop', 'journey'];

    /**
     * A `signing_secret` SZÁNDÉKOSAN nincs itt: a titkot MI generáljuk, és csak mi. Ha
     * tömegesen kitölthető lenne, az ügyfél a saját gyenge titkát tehetné be — és az
     * aláírásunk annyit érne, amennyit az ő „titka".
     */
    protected $fillable = ['description', 'priority', 'match_type', 'match_field', 'expression', 'actions', 'active'];

    protected $hidden = ['signing_secret'];

    protected $attributes = ['priority' => 100, 'active' => true, 'actions' => '[]'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'active' => 'boolean',
            'priority' => 'integer',
            'last_matched_at' => 'datetime',
        ];
    }

    /**
     * A `forward(url)` ALÁÍRÓ TITKA.
     *
     * Titkosítva tárolva, nem hash-elve: az aláíráshoz a NYERS érték kell (HMAC). Ugyanaz a
     * minta, mint az A3 webhook-végpontjainál — és ugyanazzal az indokkal: egy aláírás, amit
     * a fogadó nem tud ellenőrizni, nem aláírás.
     */
    public function signingSecret(): string
    {
        return Crypt::decryptString((string) ($this->getAttributes()['signing_secret'] ?? ''));
    }

    public function setSigningSecretAttribute(string $value): void
    {
        $this->attributes['signing_secret'] = Crypt::encryptString($value);
    }

    /** Új titok — a hívó kapja meg EGYSZER, utána már csak az aláírásainkban él. */
    public function rotateSecret(): string
    {
        $secret = 'rsec_'.Str::random(40);

        $this->signing_secret = $secret;
        $this->save();

        return $secret;
    }

    public function messages(): mixed
    {
        return $this->hasMany(InboundMessage::class);
    }
}
