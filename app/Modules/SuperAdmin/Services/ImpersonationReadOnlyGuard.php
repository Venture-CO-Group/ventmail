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
 * kimaradnának, és a tilalom pontosan ott lyukadna ki, ahol nem számítunk rá.
 *
 * ## AMI EZEN AZ ÚTON KIMARAD — kimondva
 *
 * A modell-esemény az ELOQUENT-mentést fogja, a QUERY BUILDERT nem: aktív nézet alatt a
 * `Model::query()->update()`, a `->delete()` és a nyers query-builderes beszúrás átmegy
 * (mérve). (A nyers builder nevét szándékosan nem írjuk le: az arch-őr — helyesen —
 * szöveg szerint keresi a scope-megkerülő hívásokat, és a próza nem kivétel.)
 * Ugyanígy kimarad a queue-job, mert ott nincs bejelentkezett felhasználó, tehát a guard
 * nem talál munkamenetet.
 *
 * Ez tehát BEST-EFFORT réteg, nem záró határ — a záró határnak HTTP-szinten is meg kell
 * lennie (a nem-idempotens metódusok tiltása aktív munkamenet alatt), és az a
 * platform-konzol packetjének nevesített előfeltétele (`PHASE.md` · „C2 állapota"). Amíg a
 * konzolnak nincs route-ja, a builder-út csak kódból érhető el; a modell-út viszont az,
 * amin a szolgáltatások (és a jövőbeli kontrollerek) valóban írnak.
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

    /**
     * TENANT-ADAT-E, amit írni akarunk?
     *
     * A trait önmagában NEM elég válasz, és ez volt a javítás első körének a hibája: a
     * `Suppression` a `BelongsToWorkspaceOrPlatform`-ot használja, tehát a guard a GLOBÁLIS
     * tiltás felvételét is megtiltotta — mérve: aktív nézet alatt a
     * `GlobalSuppressions::add()` kezeletlen `RuntimeException`-t dobott, vagyis a 84.
     * kritérium fő munkafolyamata (a támogatás meglátja a spam-csapdát a tenant nézetében,
     * és letiltja mindenkinél) HTTP 500-cal állt meg.
     *
     * A helyes határ nem a modell traitje, hanem hogy a sor KIÉ:
     *  - `workspace_id IS NULL` → PLATFORM-adat: a támogatás a saját nevében ír, szabad;
     *  - aktív `onPlatform()` blokk → ugyanaz, csak a `creating` ELŐTT, amikor a
     *    `workspace_id` még nincs beállítva (a `saving` event itt fut);
     *  - minden más → a tenant adata, tiltva.
     */
    private function isTenantData(Model $model): bool
    {
        $traits = class_uses_recursive($model::class);

        if (in_array(BelongsToWorkspaceOrPlatform::class, $traits, true)) {
            /** @var class-string $class */
            $class = $model::class;

            // (a) Fut egy `onPlatform()` blokk → a támogatás a SAJÁT nevében ír.
            if ($class::isPlatformWrite()) {
                return false;
            }

            /*
             | (b) LÉTEZŐ, platform-szintű sor módosítása/törlése.
             |
             | Az `exists` feltétel nem formalitás: a `saving` event a `creating` ELŐTT fut,
             | tehát egy ÚJ sornál a `workspace_id` MINDIG `null` — a puszta attribútum-
             | vizsgálat így minden tenant-létrehozást átengedett (mérve: a teszt
             | `Suppression::create()`-je nem bukott el). Létező sornál viszont az
             | attribútum az igazság, és a platform-sor mutálását amúgy is az
             | `assertMayMutatePlatformRow()` őrzi.
             */
            if ($model->exists && $model->getAttribute('workspace_id') === null) {
                return false;
            }

            return true;
        }

        return in_array(BelongsToWorkspace::class, $traits, true);
    }
}
