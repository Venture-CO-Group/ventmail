<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Support\Facades\Broadcast;

/*
 | BROADCAST-CSATORNÁK (65. kritérium).
 |
 | A kampány-haladás PRIVÁT csatornán megy, workspace-enként. Egy közös csatorna azt
 | jelentené, hogy minden tenant látja minden más tenant kampányának a haladását — a kampány
 | nevével és a címzettszámmal együtt. Ez üzleti információ: hány ügyfele van a
 | versenytársnak, mikor és mennyit küld.
 |
 | A JOGOSULTSÁG a ventMail SAJÁT `workspace_members` táblájából dől el, nem a ventSSO
 | tokenből: a tenant-tagság gazdája a ventMail (`CLAUDE.md`), és egy OIDC-token
 | önmagában nem mondja meg, melyik workspace-hez tartozik a felhasználó.
 |
 | FAIL-CLOSED: aki nem tagja a workspace-nek, nem kap engedélyt. A `false` visszatérés a
 | Laravel számára elutasítás — nem „nincs szabály, tehát engedjük".
 */

/*
 | A TÍPUS `App\Models\User`, és ez nem stílus kérdése.
 |
 | Itt korábban egy nem létező `App\Modules\Identity\Models\User` állt. A closure
 | típushibával hasalt el (`must be of type …\Identity\Models\User, App\Models\User
 | given`), tehát a `/broadcasting/auth` MINDEN kérésre 500-at adott: senki nem tudott
 | feliratkozni a kampány-haladásra. A `channels.php`-ra nem volt teszt, ezért csúszott át —
 | az alábbi három ág (tag · más workspace · nem-numerikus) most tesztelt.
 |
 | A gazdát a `config('auth.providers.users.model')` mondja meg; a `WorkspaceMember` maga is
 | ezt az osztályt importálja.
 */
Broadcast::channel('workspace.{workspaceId}.campaigns', function (User $user, string $workspaceId): bool {
    // A CSATORNANÉV a kérésből jön: számként kezeljük, hogy egy `1 or 1=1` alak ne
    // kerülhessen a lekérdezésbe.
    if (! ctype_digit($workspaceId)) {
        return false;
    }

    return WorkspaceMember::query()
        ->where('workspace_id', (int) $workspaceId)
        ->where('user_id', $user->getKey())
        ->exists();
});
