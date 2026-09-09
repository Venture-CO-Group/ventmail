<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Enums\SuppressionScope;
use App\Modules\Suppression\Models\Suppression;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * GLOBÁLIS SUPPRESSION (84. kritérium): platform-szintű tiltás MINDEN tenantra.
 *
 * ## Mikor indokolt, és miért nem a tenant dolga
 *
 * Három eset: (1) egy spam-csapda cím, ami bármely tenant listáján reputáció-vesztést
 * okoz; (2) egy jogi felszólítás („ez a cím soha többé"); (3) egy visszaélő cím, ami
 * minden tenantnál panaszt generál. Egyik sem EGY tenant ügye — és ha tenantonként kellene
 * felvenni, az első kimaradó tenant vinné a közös IP reputációját.
 *
 * ## A SÉMA már tudja, a KEZELÉS az, ami eddig hiányzott
 *
 * A `workspace_id = NULL` platform-szintű tiltást jelent, és a `BelongsToWorkspaceOrPlatform`
 * `onPlatform()` hívása az EGYETLEN út, amivel ilyen sor létrejöhet — épp azért, hogy egy
 * sima tenant-kérés véletlenül se írhasson mindenkire érvényes tiltást. Ez a szolgáltatás
 * a platform-admin oldali belépési pont hozzá, jogosultság-ellenőrzéssel és audittal.
 *
 * ## A TÖRLÉS is auditált — és INDOKKAL
 *
 * Egy globális tiltás feloldása a súlyosabb művelet: attól kezdve MINDEN tenant küldhet
 * arra a címre. Ha ez indoklás nélkül megtehető lenne, egy fél évvel későbbi
 * reputáció-vizsgálatnál nem lehetne megmondani, miért került vissza egy spam-csapda a
 * küldhető címek közé.
 */
final class GlobalSuppressions
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function add(User $admin, string $email, SuppressionReason $reason, string $detail = ''): Suppression
    {
        $this->authorize($admin);

        $email = DomainNormalizer::listKey(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A globális tiltáshoz érvényes cím kell.');
        }

        if (trim($detail) === '') {
            throw new InvalidArgumentException(
                'A globális tiltáshoz INDOKLÁS kötelező: minden tenantra hat, és egy későbbi '
                .'reputáció-vizsgálatnál tudni kell, miért került fel.'
            );
        }

        /*
         | `onPlatform()`: az EGYETLEN út a `workspace_id = NULL` sorhoz.
         |
         | A LÉTEZŐ SOR KERESÉSE EXPLICIT `whereNull('workspace_id')`-DEL MEGY, és ez a
         | packet legdrágább hibája volt.
         |
         | Az `onPlatform()` az ÍRÁST viszi platform-szintre (a `creating` hook nullázza a
         | `workspace_id`-t, a DB-trigger pedig a session-változót nézi) — az OLVASÁS
         | viszont továbbra is átmegy a `WorkspaceOrPlatformScope`-on, ami kontextusban
         | `(workspace_id = ctx OR workspace_id IS NULL)`-ra illeszt. A korábbi
         | `firstOrCreate(['email' => …, 'applies_to' => 'all'])` tehát TALÁLATNAK vette a
         | tenant SAJÁT sorát, visszaadta sikerként, és globális sor NEM jött létre —
         | mérve: `workspace_id IS NULL` sorok száma 0, közben a
         | `platform.global_suppression_added` audit-sor megíródott. Egy jogi felszólításra
         | felvett cím így minden MÁS tenantnál küldhető maradt, a napló szerint elintézve.
         |
         | A `whereNull` a scope-ot NEM kapcsolja ki, csak SZŰKÍTI: a metszet pontosan a
         | globális sor. Ezért nincs itt scope-kikapcsolás — az nagyobb jog, mint ami ehhez
         | kell, és az arch-őr (`ScopeBypassGuardTest`) joggal tiltja.
         |
         | Az idempotencia megmarad: egy már felvett globális tiltás ismételt felvétele nem
         | hiba — a támogatás nem tudja fejből, mi van a listán.
         */
        $scope = SuppressionScope::All->value;

        $suppression = Suppression::onPlatform(function () use ($email, $reason, $detail, $scope): Suppression {
            $existing = Suppression::query()
                ->whereNull('workspace_id')
                ->where('email', $email)
                ->where('applies_to', $scope)
                ->first();

            if ($existing instanceof Suppression) {
                return $existing;
            }

            // A `workspace_id`-t NEM adjuk át: nem `fillable` (tenant-azonosító sosem jön
            // tömeges hozzárendelésből), és az `onPlatform()` `creating` hookja nullázza.
            return Suppression::query()->create([
                'email' => $email,
                'applies_to' => $scope,
                'reason' => $reason->value,
                'source' => 'platform_admin',
                'detail' => mb_substr(trim($detail), 0, 500),
            ]);
        });

        $this->audit->log(
            'platform.global_suppression_added',
            $suppression,
            context: [
                // A NYERS CÍM NEM kerül az auditba: az audit-sor SOHA nem törölhető
                // (GDPR Art. 17). A hash és az ok elég a diagnózishoz.
                'email_hash' => (string) $suppression->email_hash,
                'reason' => $reason->value,
                'admin_id' => (int) $admin->getKey(),
            ],
            actor: $admin,
        );

        return $suppression;
    }

    /**
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function remove(User $admin, Suppression $suppression, string $detail): Suppression
    {
        $this->authorize($admin);

        if (! $suppression->isPlatformWide()) {
            throw new InvalidArgumentException(
                'Ez nem globális tiltás: egy tenant saját tiltását a tenant oldja fel.'
            );
        }

        if (trim($detail) === '') {
            throw new InvalidArgumentException(
                'A feloldáshoz INDOKLÁS kötelező: attól kezdve MINDEN tenant küldhet erre a címre.'
            );
        }

        $hash = (string) $suppression->email_hash;

        // A SOR TÖRLÉSE, nem lejáratása: a globális tiltás feloldása azt jelenti, hogy a
        // cím visszakerül a küldhetők közé. A TÉNY az auditban marad.
        Suppression::onPlatform(fn () => $suppression->delete());

        $this->audit->log(
            'platform.global_suppression_removed',
            null,
            context: [
                'email_hash' => $hash,
                'detail' => mb_substr(trim($detail), 0, 500),
                'admin_id' => (int) $admin->getKey(),
            ],
            actor: $admin,
        );

        return $suppression;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $admin): void
    {
        if (! Gate::forUser($admin)->allows('platform.admin')) {
            /*
             | AZ ELUTASÍTOTT PRÓBÁLKOZÁS IS AUDITÁLT.
             |
             | A 81. kritérium szerint MINDEN platform-művelet auditált — és egy visszavont
             | admin próbálkozása épp a legérdekesebb sor. Enélkül a napló csak a sikeres
             | műveleteket ismeri, tehát pont a támadás-kísérlet nem látszik.
             */
            $this->audit->log(
                'platform.denied',
                null,
                context: ['operation' => 'global_suppression', 'admin_id' => (int) $admin->getKey()],
                actor: $admin,
            );

            throw new AuthorizationException('A globális suppression platform-admin jogosultságot igényel.');
        }
    }
}
