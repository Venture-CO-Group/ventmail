<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Domains\Models\Domain;

/**
 * A lejárt előző DKIM kulcsanyag törlése.
 *
 * Kulcsrotációnál kötelező kérdés: van-e TÖRLÉSI ÚT? Enélkül az előző privát kulcs
 * határozatlan ideig ott marad az adatbázisban és MINDEN adatbázis-mentésben — a
 * kompromittálódás ablaka pedig nem záródik be soha.
 *
 * A törlés TÉNYE auditált, az ÉRTÉK természetesen nem.
 */
final class PurgeExpiredDkimKeys
{
    public static function run(): int
    {
        $purged = 0;

        Domain::query()
            ->whereNotNull('dkim_previous_private_key')
            ->whereNotNull('dkim_rotated_at')
            ->where('dkim_rotated_at', '<=', now()->subDays((int) config('domains.dkim_rotation_overlap_days')))
            ->each(function (Domain $domain) use (&$purged): void {
                $domain->forceFill([
                    'dkim_previous_selector' => null,
                    'dkim_previous_public_key' => null,
                    'dkim_previous_private_key' => null,
                ])->save();

                app(AuditLogger::class)->log('domain.dkim.previous_key_purged', $domain, after: [
                    'rotated_at' => (string) $domain->dkim_rotated_at,
                ]);

                $purged++;
            });

        return $purged;
    }
}
