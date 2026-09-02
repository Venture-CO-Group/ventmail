<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Tenancy\Models\Workspace;
use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
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
class User extends Model implements AuthenticatableContract
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
        'last_login_at',
    ];

    /**
     * A ventSSO-alany azonosítója családszinten korrelálható — nem megy ki szerializálva.
     *
     * @var list<string>
     */
    protected $hidden = ['sso_issuer', 'sso_subject'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

    /** A ventMail nem tárol jelszót — a jelszó-alapú hitelesítés nem értelmezett. */
    public function getAuthPassword(): string
    {
        return '';
    }
}
