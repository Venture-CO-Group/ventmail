<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Console\Command;

/**
 * EGYSZERI BACKFILL: a parent tulajdonosai bekerülnek a MÁR MEGLÉVŐ al-fiókokba.
 *
 * A tükrözés csak a JÖVŐBELI szerep-változásokra fut. A korábban létrehozott
 * al-fiókokban ezért csak a létrehozó a tulajdonos — és ha ő időközben kikerült a
 * parentből, a child gazdátlan: ott van az al-fiók-listán, a parent számlájára fogy,
 * beleszámít a darabszám-plafonba, de a JELENLEGI tulajdonos be sem tud lépni. Ezt
 * platform-admin nélkül nem lehetne javítani.
 *
 * A parancs KIZÁRÓLAG HOZZÁAD, sosem vesz el: egy hiányzó tulajdonos pótolható,
 * egy tévedésből elvett hozzáférés nem.
 */
final class SyncSubAccountOwnersCommand extends Command
{
    protected $signature = 'tenancy:sync-sub-account-owners {--dry-run : csak felsorol, nem ír}';

    protected $description = 'A parent tulajdonosainak pótlása a meglévő al-fiókokban (csak hozzáad).';

    public function handle(AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $added = 0;

        /*
         | GLOBAL SCOPE NÉLKÜL, SZÁNDÉKOSAN: a backfill MINDEN tenantra fut, konzolból, ahol
         | nincs (és nem is lehet) workspace-kontextus. A `Workspace` amúgy sem scope-olt —
         | ő maga a scope alanya.
         */
        Workspace::query()->whereHas('children')->with('children')->get()
            ->each(function (Workspace $parent) use (&$added, $dryRun, $audit): void {
                $owners = WorkspaceMember::query()
                    ->where('workspace_id', $parent->getKey())
                    ->get()
                    ->filter(fn (WorkspaceMember $member) => in_array(Role::Owner->value, $member->roles(), true));

                foreach ($parent->children as $child) {
                    foreach ($owners as $owner) {
                        $exists = WorkspaceMember::query()
                            ->where('workspace_id', $child->getKey())
                            ->where('user_id', $owner->user_id)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        $this->line(sprintf(
                            '%s → %s: user #%d owner-tagság pótolva',
                            $parent->name,
                            $child->name,
                            $owner->user_id
                        ));

                        $added++;

                        if ($dryRun) {
                            continue;
                        }

                        $member = WorkspaceMember::create([
                            'workspace_id' => $child->getKey(),
                            'user_id' => $owner->user_id,
                            'roles' => [Role::Owner->value],
                            'source' => WorkspaceMember::SOURCE_PARENT_OWNER,
                        ]);

                        $audit->log(
                            'member.roles.assigned',
                            $member,
                            after: ['roles' => [Role::Owner->value]],
                            context: [
                                'target_user_id' => $owner->user_id,
                                'reason' => 'parent_owner_backfill',
                                'parent_workspace_id' => $parent->getKey(),
                            ],
                            workspaceId: (int) $child->getKey(),
                        );
                    }
                }
            });

        $this->info($dryRun ? "Pótolható tagság: {$added}." : "Pótolt tagság: {$added}.");

        return self::SUCCESS;
    }
}
