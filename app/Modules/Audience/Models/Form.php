<?php

declare(strict_types=1);

namespace App\Modules\Audience\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Beiratkozó űrlap (54. kritérium).
 *
 * A HONEYPOT MEZŐ NEVE tenantonként (sőt űrlaponként) más, és ez szándékos: egy általános
 * bot a `email_confirm` vagy `website` nevű csapdát felismeri, a `kontakt_7f3a9c`-t nem. A
 * time-trap pedig azt méri, amit a honeypot nem: az emberi kitöltés ideje másodpercekben
 * mérhető, a boté milliszekundumokban.
 *
 * Egyik sem CAPTCHA, és ez tudatos: a CAPTCHA a beiratkozók egy részét elveszíti, a
 * honeypot + time-trap páros viszont a valódi látogató számára LÁTHATATLAN.
 */
final class Form extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = [
        'contact_list_id', 'name', 'slug', 'fields', 'honeypot_field', 'min_seconds',
        'policy_version', 'consent_text', 'redirect_url', 'active',
    ];

    protected $attributes = ['fields' => '[]', 'min_seconds' => 3, 'active' => true];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'min_seconds' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $form): void {
            // A SLUG globálisan egyedi (lásd a migrációt), ezért a generált alak MINDIG
            // kap véletlen utótagot — és ha a tenant adott sajátot, az is egyedi kell
            // legyen: az ütközés HANGOS hiba, nem csendes felülírás.
            $form->slug = $form->slug ?: Str::slug((string) $form->name).'-'.Str::lower(Str::random(10));

            // A CSAPDA NEVE generált, ha a hívó nem adott: egy állandó, kitalálható név
            // (`website`) néhány hét alatt bekerül a bot-keretrendszerek kihagyási listájába.
            $form->honeypot_field = $form->honeypot_field ?: 'kontakt_'.Str::lower(Str::random(6));
        });
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'contact_list_id');
    }
}
