<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Concerns\BelongsToWorkspaceOrPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * AZ ÍRÁS-TILALOM KIKÉNYSZERÍTÉSE aktív impersonation alatt (82. kritérium).
 *
 * ## Miért kellett ez, és mit mértünk nélküle
 *
 * A `Impersonation::isReadOnly()` metódus megvolt — és NULLA produkciós hívója. A
 * security-review ezt megmérte: aktív impersonation alatt a platform-admin
 * LÉTREHOZOTT egy domaint a tenant nevében, felvett és törölt egy tenant-suppressiont.
 * Semmi nem konzultálta az `isReadOnly()`-t. A 82. kritérium magja („az ÍRÁS TILOS")
 * állítás volt, nem védelem.
 *
 * ## Miért MODELL-szinten, és nem middleware-ben
 *
 * Mert a tilalom nem a HTTP-kérésről szól, hanem az ADATRÓL. Egy middleware csak a
 * route-okat védi — a jobok, az artisan-parancsok és a jövőbeli belépési pontok
 * kimaradnának, és a tilalom pontosan ott lyukadna ki, ahol nem számítunk rá. A
 * modell-esemény MINDEN íráson átmegy.
 *
 * ## Mit tilt, és mit NEM
 *
 * KIZÁRÓLAG a TENANT adatát: azokat a modelleket, amelyek a `BelongsToWorkspace` vagy a
 * `BelongsToWorkspaceOrPlatform` traitet használják. Ez épp az a halmaz, ami „a tenant
 * fiókja".
 *
 * Amit NEM tilt: a platform saját sorait (`ImpersonationSession`, `PlanOverride`) és az
 * AUDIT-NAPLÓT. Ez nem kényelmi kivétel — enélkül a munkamenet LEZÁRÁSA is elbukna
 * (`ended_at` írás), és a támogatás nem tudná befejezni, amit elkezdett; az audit-sor
 * pedig épp azt rögzíti, hogy a tilalom életbe lépett.
 */
final class ImpersonationReadOnlyGuard
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @throws RuntimeException ha aktív impersonation alatt tenant-adatot írnának
     */
    public function assertWritable(Model $model): void
    {
        if (! $this->isTenantData($model)) {
            return;
        }

        $user = Auth::user();

        if ($user === null || ! $this->impersonation->isReadOnly($user)) {
            return;
        }

        /*
         | A KIVÉTEL HANGOS, és ez szándékos.
         |
         | A csendes eldobás (a művelet „sikerül", de nem történik meg) itt a legrosszabb
         | válasz: a támogatás azt hinné, megjavította a tenant beállítását, a tenant pedig
         | azt látná, hogy semmi nem változott. A kivétel megmondja, hogy a nézetet le kell
         | zárni ahhoz, hogy a SAJÁT nevében dolgozhasson.
         */
        throw new RuntimeException(sprintf(
            'Aktív támogatói nézet (impersonation) alatt az ÍRÁS TILOS: %s. A támogatás LÁT, '
            .'nem módosít — zárd le a nézetet, ha a saját nevedben kell írnod.',
            $model::class
        ));
    }

    private function isTenantData(Model $model): bool
    {
        $traits = class_uses_recursive($model::class);

        return in_array(BelongsToWorkspace::class, $traits, true)
            || in_array(BelongsToWorkspaceOrPlatform::class, $traits, true);
    }
}
