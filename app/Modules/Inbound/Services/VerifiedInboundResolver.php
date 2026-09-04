<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Data\InboundRecipient;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Scopes\WorkspaceScope;

/**
 * „A MIÉNK ez a címzett?" — a VERIFIKÁLT inbound-domainek alapján.
 *
 * A feloldás az MX-oldal nyílt-relay zára, ezért a feltétel SZŰK: a domainnek léteznie kell,
 * és az inbound MX-státuszának `verified`-nek kell lennie. Nem elég, hogy a küldés miatt
 * hitelesített: az MX-rekord külön DNS-bejegyzés, és amíg nem mutat ránk, addig levelet
 * fogadni rá nem a mi dolgunk.
 *
 * A WORKSPACE-SCOPE-OT SZÁNDÉKOSAN MEGKERÜLI, és ez dokumentált kivétel: fogadáskor még
 * NINCS workspace-kontextus — épp ez a lekérdezés állapítja meg, melyik tenanté a levél.
 * Ugyanaz a precedens, mint az API-kulcs és az SMTP-hitelesítő feloldásánál: egyetlen, szűk,
 * pontos egyezésre korlátozott lekérdezés, izolációs teszttel fedve.
 *
 * KIZÁRÓLAG a workspace-scope-ot: a `withoutGlobalScopes()` argumentum nélkül a
 * SoftDeletingScope-ot is leszedné, és akkor a TÖRÖLT domainre továbbra is folyna be a levél
 * — olyan retenció alatt, amihez már nincs jogalapunk.
 *
 * És ha ugyanaz a domain KÉT tenantnál is verifikált lenne, a feloldás önkényesen az egyiket
 * választaná, a másik tenant levele pedig idegen workspace-be kerülne (golden rule 6). Ezt a
 * séma zárja ki (globális unique index az aktív inbound-domain nevére); ha a védelem valaha
 * elromlana, itt HANGOSAN elutasítunk, nem tippelünk.
 */
final class VerifiedInboundResolver implements InboundRecipientResolver
{
    public function resolve(string $recipient): ?InboundRecipient
    {
        $address = DomainNormalizer::email(trim($recipient));

        if ($address === '' || ! str_contains($address, '@')) {
            return null;
        }

        $host = DomainNormalizer::ofEmail($address);

        if ($host === '') {
            return null;
        }

        $domains = Domain::query()
            ->withoutGlobalScopes([WorkspaceScope::class])
            ->where('name', $host)
            ->where('inbound_mx_status', 'verified')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($domains->count() !== 1) {
            // Nulla: nem a miénk. Kettő vagy több: kétértelmű tulajdonlás — inkább nem
            // fogadunk, mint hogy idegen tenanthoz tegyük a levelet.
            return null;
        }

        $domain = $domains->first();

        return new InboundRecipient(
            workspaceId: (int) $domain->workspace_id,
            domainId: (int) $domain->getKey(),
            address: $address,
        );
    }
}
