<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'name',
        'email',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /** A ventMail nem tárol jelszót — a jelszó-alapú hitelesítés nem értelmezett. */
    public function getAuthPassword(): string
    {
        return '';
    }
}
