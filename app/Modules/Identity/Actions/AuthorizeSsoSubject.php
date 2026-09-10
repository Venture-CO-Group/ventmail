<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Van-e az alanynak keresnivalója a ventMailben?
 *
 * A ventSSO a teljes Venture-családé: az `aud` claim csak azt igazolja, hogy a tokent
 * NEKÜNK állították ki, nem azt, hogy a felhasználónak van itt hozzáférése. Enélkül a
 * realm minden felhasználója belépne, és PII-sort (név, e-mail) hoznánk létre olyanokról,
 * akiknek semmi közük az apphoz — GDPR-szempontból jogalap nélküli adatkezelés.
 *
 * Belépési jogcím: (a) leképzett `ventmail` szerep a tokenben, VAGY (b) meglévő tagság,
 * VAGY (c) NYITOTT MEGHÍVÓ a token VERIFIKÁLT e-mail-címére.
 *
 * A (c) nélkül a meghívó használhatatlan lenne: az újonnan meghívott kollégának még nincs
 * tagsága (épp azt kapná), és ha a ventSSO-ban nincs `ventmail` szerepe, itt elutasítanánk —
 * a meghívó pedig lejárna anélkül, hogy bárki beválthatta volna.
 *
 * A VERIFIKÁLTSÁG ITT IS FELTÉTEL, nem a beváltásra hagyva: egy nem verifikált címmel
 * regisztráló idegen különben LÉTREHOZATNÁ magáról a PII-sort (név, e-mail) pusztán azzal,
 * hogy egy meghívott címet ír be — a tagságot nem kapná meg, de az adatkezelés jogalap
 * nélkül megtörtént volna.
 */
final class AuthorizeSsoSubject
{
    /** @param list<string> $roles */
    public function __invoke(array $claims, array $roles): void
    {
        if ($roles !== []) {
            return;
        }

        $existing = User::query()
            ->where('sso_issuer', (string) ($claims['iss'] ?? ''))
            ->where('sso_subject', (string) ($claims['sub'] ?? ''))
            ->first();

        $hasMembership = $existing !== null
            && WorkspaceMember::query()->where('user_id', $existing->getKey())->exists();

        if (! $hasMembership && ! $this->hasOpenInvitation($claims)) {
            throw new AuthorizationException(
                'Ehhez a ventSSO-alanyhoz nincs ventMail-hozzáférés. Kérj meghívót a workspace tulajdonosától.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * A lekérdezés SZÁNDÉKOSAN global scope nélkül fut: a belépés pillanatában nincs
     * workspace-kontextus — épp a meghívó mondja meg, melyik workspace-ről van szó. A
     * feltétel ezért szűk: pontos cím-egyezés, nyitott, le nem járt meghívó.
     */
    private function hasOpenInvitation(array $claims): bool
    {
        if (($claims['email_verified'] ?? false) !== true) {
            return false;
        }

        // HOZZÁFÉRÉSI kulcs (fail-closed): a nem tisztán leképezhető cím NEM ad
        // belépési jogcímet.
        $email = DomainNormalizer::authKey((string) ($claims['email'] ?? ''));

        if ($email === '') {
            return false;
        }

        return WorkspaceInvitation::query()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
