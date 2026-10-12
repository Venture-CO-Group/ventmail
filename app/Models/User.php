<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Tenancy\Models\Workspace;
use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;

/**
 * A ventSSO-alany lokális tükre.
 *
 * Szándékosan NEM a Laravel `Foundation\Auth\User` ősből származik: az hozná a
 * jelszó-visszaállító (`CanResetPassword`) és e-mail-megerősítő viselkedést. A
 * ventMail sem jelszót, sem remember-tokent nem tárol — az identitás egyetlen
 * gazdája a ventSSO (golden rule 1), a bejelentkezés az Identity modul
 * OIDC-folyamata (M0-1). A tenant-tagság a saját `workspace_members` táblából
 * dől el, sosem a tokenből.
 */
class User extends Model implements AuthenticatableContract, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use AuthenticatableTrait, HasFactory, Notifiable;

    /** A „maradjak bejelentkezve" nem támogatott: nincs remember_token oszlop. */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // szándékosan üres — a ventMail nem tart remember-tokent
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sso_issuer',
        'sso_subject',
        'name',
        'email',
        'locale',
        'locale_source',
        'last_login_at',
    ];

    /**
     * A ventSSO-alany azonosítója családszinten korrelálható — nem megy ki szerializálva.
     *
     * @var list<string>
     */
    protected $hidden = ['sso_issuer', 'sso_subject'];

    /**
     * A `platform_admin` DEFAULTJA a modellen is ott van, nem csak a DB-ben.
     *
     * Miért: az oszlop DB-oldali defaultja a frissen létrehozott PHP-példányon NEM jelenik meg,
     * és a strict mode (`preventAccessingMissingAttributes`) a hiányzó attribútum olvasásakor
     * kivételt dob. A jogosultság-ellenőrzés így nem „nem admin"-t kapott volna, hanem HTTP 500-at
     * — mérve: a `workspace` middleware minden factoryból származó felhasználónál elhasalt.
     * Az `isPlatformAdmin()` emellett fail-closed olvasót ad, ha a példány mégis csonka.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['platform_admin' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            /*
             | A PLATFORM-ADMIN JELÖLŐ — és SZÁNDÉKOSAN nem `fillable`.
             |
             | A rendszer legerősebb jogosultsága: minden tenant adatát látja. Egy tömeges
             | hozzárendelés (`User::create($request->all())`) SOHA nem adhatja meg — a
             | `PlatformAdminSync` a ventSSO claimjéből, `forceFill`-lel írja, minden
             | bejelentkezésnél újraszámolva.
             */
            'platform_admin' => 'boolean',
            'notification_prefs' => 'array',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * A tenant-tagság EGYETLEN forrása (golden rule 1) — a ventSSO tokenje sosem dönt
     * workspace-hozzáférésről.
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members');
    }

    /**
     * A platform-admin jelölő FAIL-CLOSED olvasója.
     *
     * Sosem dob: ha a példány csonka (pl. `select`-tel szűkített lekérdezés), a válasz `false`.
     * A jogosultság hiánya biztonságos állapot, a kivétel nem az — egy 500-as hiba az
     * `abort(403)` helyett elrejti, hogy a döntés meg sem született.
     */
    public function isPlatformAdmin(): bool
    {
        $raw = $this->attributes['platform_admin'] ?? false;

        // SZIGORÚ összehasonlítás, nem `(bool)` cast: a Postgres-driver `bool`-t ad, de ha
        // az érték bármikor sztringként érkezik (más driver, nyers lekérdezés, cache),
        // akkor `(bool) 'f' === true` — vagyis a fail-closed olvasó fail-openné válna, épp
        // a rendszer legerősebb jogosultságánál.
        return $raw === true || $raw === 1 || $raw === '1' || $raw === 't';
    }

    /**
     * A FELHASZNÁLÓ NYELVE — fail-safe olvasó.
     *
     * A `HasLocalePreference` szerződést azért implementáljuk, mert a Laravel maga is ezt
     * kérdezi (értesítések, mail), és így a felület, az e-mail és a fordító-réteg UGYANAZT
     * a forrást használja.
     *
     * Sosem dob: ha a példány csonka (`select`-tel szűkített lekérdezés, frissen épített
     * modell), az alap nyelv jön. Ugyanaz az érv, mint az `isPlatformAdmin()`-nál — egy
     * hiányzó attribútum nem lehet HTTP 500 egy olyan mezőnél, aminek van értelmes alapja.
     * Mérve: a szigorú mód (`preventAccessingMissingAttributes`) különben az EGÉSZ
     * app-héjat leviszi.
     */
    /**
     * Nyers attribútum-olvasás: a szigorú mód (`preventAccessingMissingAttributes`) csonka
     * példánynál (nem minden oszlop töltődött) kivételt dobna — az opcionális profil-
     * beállításoknál a hiány egyszerűen „nincs beállítva".
     */
    public function raw(string $key): mixed
    {
        return $this->getAttributes()[$key] ?? null;
    }

    public function preferredLocale(): string
    {
        $locale = trim((string) ($this->attributes['locale'] ?? ''));
        $supported = (array) config('app.supported_locales', []);

        return in_array($locale, $supported, true) ? $locale : (string) config('app.locale');
    }

    /** A ventMail nem tárol jelszót — a jelszó-alapú hitelesítés nem értelmezett. */
    public function getAuthPassword(): string
    {
        return '';
    }
}
