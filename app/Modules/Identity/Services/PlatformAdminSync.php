<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * A PLATFORM-ADMIN JOGOSULTSÁG a ventSSO claimjéből (81. kritérium).
 *
 * ## Miért minden bejelentkezésnél újraszámolva
 *
 * Mert ha a jogosultság csak a MI adatbázisunkban élne, egy ventSSO-oldali visszavonás
 * nálunk NEM érvényesülne: a leszerelt kollégának másnap is lenne platform-hozzáférése —
 * ahhoz a jogosultsághoz, ami MINDEN tenant adatát látja. A `users.platform_admin` oszlop
 * ezért CACHE, nem forrás; a forrás a claim.
 *
 * ## Miért nem az `AbilityMap`-ben van
 *
 * Mert a hat tenant-szerep mind egy WORKSPACE-en belül értelmes, és a jogosultságukat a
 * `workspace_members` adja (golden rule 1). A platform-admin ezzel szemben tenanteken
 * átnyúlva dolgozik: nincs workspace-e, és épp az a munkája, hogy olyat lát, amit egy
 * tenant nem. Egy workspace-scope-olt képességmátrixba ez nem illeszthető be anélkül,
 * hogy a tenant-jogosultságok jelentése ne csúszna el.
 *
 * ## A CLAIM NEVE külön config
 *
 * A tenant-szerepek a `resource_access.ventmail.roles` alatt vannak; a platform-admin
 * SZÁNDÉKOSAN másik claim, hogy egy elgépelt vagy rosszul konfigurált tenant-szerep
 * (`platform_admin` szerepnév egy workspace-ben) ne adhasson platform-hozzáférést.
 */
final class PlatformAdminSync
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public function sync(User $user, array $claims): bool
    {
        $isAdmin = $this->claimGrantsAdmin($claims);

        if ($user->isPlatformAdmin() !== $isAdmin) {
            $user->forceFill(['platform_admin' => $isAdmin])->save();

            /*
             | A VÁLTOZÁS AUDITÁLT — a rendszer legerősebb jogosultságának megadása és
             | elvétele nem lehet nyom nélküli (golden rule 11).
             |
             | A DÖNTÉS gazdája a ventSSO, de a cache-írás itt történik, és egy
             | incidens-vizsgálatnak a ventMail naplójából kell megválaszolnia, hogy „ez az
             | ember mikor lett platform-admin". Mérve: hét `sync()` hívás után NULLA
             | audit-sor volt.
             |
             | Csak a VÁLTOZÁS íródik, nem minden bejelentkezés: különben a napló legnagyobb
             | része ugyanannak a ténynek az ismétlése lenne.
             */
            $this->audit->log(
                $isAdmin ? 'platform.admin_granted' : 'platform.admin_revoked',
                $user,
                context: [
                    'user_id' => (int) $user->getKey(),
                    'claim' => (string) config('oidc.platform_admin_claim'),
                ],
                workspaceId: AuditLogger::NO_WORKSPACE,
                // AZ AKTOR MAGA A FELHASZNÁLÓ, akinél a claim megváltozott: a bejelentkezés
                // pillanatában még nincs `Auth::user()`, tehát a sor különben aktor nélkül
                // maradna — épp a legfontosabbnál.
                actor: $user,
            );
        }

        return $isAdmin;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function claimGrantsAdmin(array $claims): bool
    {
        $claimPath = (string) config('oidc.platform_admin_claim');

        /*
         | A PLATFORM-CLAIM NEM LEHET UGYANAZ, mint a tenant-szerepek claimje.
         |
         | Az egész osztály értelme az elválasztás: ha a két útvonal egybeesik, akkor egy
         | WORKSPACE-en belül kiosztott `platform_admin` nevű szerep platform-hozzáférést
         | adna — vagyis bármelyik tenant-owner adhatna magának mindent. Ez konfigurációs
         | hiba, és fail-closed a válasz: nem adunk jogot, és HANGOSAN naplózzuk.
         */
        if ($claimPath === '' || $claimPath === (string) config('oidc.roles_claim')) {
            Log::error('A platform-admin claim nincs elválasztva a tenant-szerepektől — nem adunk platform-jogot.', [
                'platform_admin_claim' => $claimPath,
            ]);

            return false;
        }

        $roles = (array) Arr::get($claims, $claimPath, []);

        $expected = strtolower(trim((string) config('oidc.platform_admin_role')));

        /*
         | ÜRES SZEREPNÉV: FAIL-CLOSED.
         |
         | Mérve: `platform_admin_role = ''` + a claimben egy üres sztring (`['']`) →
         | `sync()` `true`-t adott, vagyis egy elgépelt env-érték platform-admint
         | osztogatott. Az üres szerepnév nem „bármi megfelel", hanem konfigurációs hiba.
         */
        if ($expected === '') {
            return false;
        }

        foreach ($roles as $role) {
            if (is_string($role) && strtolower(trim($role)) === $expected) {
                return true;
            }
        }

        return false;
    }
}
