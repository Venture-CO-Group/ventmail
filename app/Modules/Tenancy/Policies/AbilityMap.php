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
    /*
     | ÚJ KÉPESSÉG (M4): `validation.run`.
     |
     | A címellenőrzés eddig KIZÁRÓLAG API-kulccsal volt elérhető (`/v1/validate`), a
     | felületről nem — pedig a legtöbb elgépelt cím ott keletkezik, ahol egy ember
     | begépeli. A képesség a három ÜZEMELTETŐ szerephez tartozik (owner, admin,
     | developer): a mailbox-szonda RBL-kockázatot hordoz és mérve is van (metering),
     | tehát nem futtathatja bárki — az `analyst` és a tartalmi szerepek szándékosan
     | maradnak ki.
     |
     | ÚJ KÉPESSÉG (M4-6): `storedTemplate.manage`.
     |
     | A TÁROLT sablon (`/v1/templates`) a FEJLESZTŐ szerződése: a tranzakciós küldés
     | `template=` paramétere erre hivatkozik, és a verziói megváltoztathatatlanok. Ezért
     | nem a Studio `template.create` képessége alá tartozik: a block editor sablonja a
     | marketingesé, ez pedig az integrációé — aki egy aktív verziót átkapcsol, egy MÁR
     | MŰKÖDŐ integráció kimenetét változtatja meg. A három üzemeltető szerep kapja.
     |
     | ÚJ KÉPESSÉG (v1.1 · 6. modul): `journey.publish`.
     |
     | A journey publikálása (aktiválás, új verzió) és szüneteltetése küldést indít vagy
     | állít le — ugyanaz a négy-szem minta, mint a `campaign.publish`-nál: a szerző
     | (`author`) megrajzolhatja a vásznat, de élesíteni nem tudja. Ugyanaz a három szerep
     | kapja (owner, admin, editor).
     |
     | ÚJ KÉPESSÉG (v1.1 · 7. modul review S1, D189): `logs.export_pii`.
     |
     | A napló TELJES (nem maszkolt) exportja címzett-címeket és tárgyakat ad ki tömegesen,
     | fájlban, ami a rendszeren kívül él tovább. A maszkolt export a `logs.view`-hoz elég; a
     | teljes alak az üzemeltető szerepeké (owner, admin, developer) — az elemző és a
     | szerkesztő nem kapja.
     */

    /** @var array<string, list<string>> */
    private const ABILITIES = [
        'owner' => [
            'workspace.manage', 'workspace.delete', 'member.manage', 'billing.manage',
            'subAccount.manage', 'domain.manage', 'ipPool.manage',
            'apiKey.manage', 'smtpCredential.manage', 'webhook.manage', 'storedTemplate.manage',
            'suppression.manage', 'validation.run', 'logs.view', 'logs.export_pii', 'analytics.view',
            'campaign.create', 'campaign.publish', 'journey.publish', 'template.create', 'template.publish', 'audience.manage',
        ],
        'admin' => [
            'workspace.manage', 'member.manage',
            'subAccount.manage', 'domain.manage', 'ipPool.manage',
            'apiKey.manage', 'smtpCredential.manage', 'webhook.manage', 'storedTemplate.manage',
            'suppression.manage', 'validation.run', 'logs.view', 'logs.export_pii', 'analytics.view',
            'campaign.create', 'campaign.publish', 'journey.publish', 'template.create', 'template.publish', 'audience.manage',
        ],
        // Fejlesztő: a küldő infrastruktúra igen, a marketing tartalom nem.
        'developer' => [
            'domain.manage', 'ipPool.manage',
            'apiKey.manage', 'smtpCredential.manage', 'webhook.manage', 'storedTemplate.manage',
            'suppression.manage', 'validation.run', 'logs.view', 'logs.export_pii', 'analytics.view',
        ],
        'editor' => [
            'campaign.create', 'campaign.publish', 'journey.publish', 'template.create', 'template.publish', 'audience.manage',
            'logs.view', 'analytics.view',
        ],
        // Szerző: draftot készít, publikálni nem tud — sablonon keresztül sem.
        'author' => [
            'campaign.create', 'template.create',
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

    /**
     * Egy szerep összes képessége.
     *
     * @return list<string>
     */
    public static function abilitiesOf(Role $role): array
    {
        return self::ABILITIES[$role->value] ?? [];
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
