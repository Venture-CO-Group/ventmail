<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Enums\Role;

/**
 * A hat szerep képességmátrixa (spec 3.3).
 *
 * Kiemelt szabályok:
 * - `developer`: API-kulcs, SMTP-hitelesítő, webhook, napló — KAMPÁNYHOZ NEM nyúl.
 * - `author`: tartalmat készít, de NEM publikál.
 * - `analyst`: kizárólag olvas.
 * - `billing.manage` csak `owner` — az admin sem költhet.
 *
 * Fehérlistás: ami nincs felsorolva, az tiltott.
 */
final class AbilityMap
{
    /** @var array<string, list<string>> */
    private const ABILITIES = [
        'owner' => [
            'workspace.manage', 'workspace.delete', 'member.manage', 'billing.manage',
            'subAccount.manage', 'domain.manage', 'ipPool.manage',
            'apiKey.manage', 'smtpCredential.manage', 'webhook.manage',
            'suppression.manage', 'logs.view', 'analytics.view',
            'campaign.create', 'campaign.publish', 'template.manage', 'audience.manage',
        ],
        'admin' => [
            'workspace.manage', 'member.manage',
            'subAccount.manage', 'domain.manage', 'ipPool.manage',
            'apiKey.manage', 'smtpCredential.manage', 'webhook.manage',
            'suppression.manage', 'logs.view', 'analytics.view',
            'campaign.create', 'campaign.publish', 'template.manage', 'audience.manage',
        ],
        // Fejlesztő: a küldő infrastruktúra igen, a marketing tartalom nem.
        'developer' => [
            'domain.manage', 'ipPool.manage',
            'apiKey.manage', 'smtpCredential.manage', 'webhook.manage',
            'suppression.manage', 'logs.view', 'analytics.view',
        ],
        'editor' => [
            'campaign.create', 'campaign.publish', 'template.manage', 'audience.manage',
            'logs.view', 'analytics.view',
        ],
        // Szerző: draftot készít, publikálni nem tud.
        'author' => [
            'campaign.create', 'template.manage',
            'analytics.view',
        ],
        // Elemző: csak olvas.
        'analyst' => [
            'logs.view', 'analytics.view',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return collect(self::ABILITIES)->flatten()->unique()->values()->all();
    }

    /** @param list<string> $roles */
    public static function allows(array $roles, string $ability): bool
    {
        foreach ($roles as $role) {
            $mapped = Role::tryFromClaim((string) $role);

            if ($mapped === null) {
                continue; // ismeretlen szerep nem ad jogot
            }

            if (in_array($ability, self::ABILITIES[$mapped->value] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
