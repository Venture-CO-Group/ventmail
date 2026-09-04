<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Data\InboundRecipient;
use App\Modules\Sending\Services\DomainNormalizer;

/**
 * „A MIÉNK ez a címzett?" — a VERIFIKÁLT inbound-domainek alapján.
 *
 * A feloldás az MX-oldal nyílt-relay zára, ezért a feltétel SZŰK: a domainnek léteznie kell,
 * és az inbound MX-státuszának `verified`-nek kell lennie. Nem elég, hogy a küldés miatt
 * hitelesített: az MX-rekord külön DNS-bejegyzés, és amíg nem mutat ránk, addig levelet
 * fogadni rá nem a mi dolgunk.
 *
 * A GLOBAL SCOPE-OT SZÁNDÉKOSAN MEGKERÜLI, és ez dokumentált kivétel: fogadáskor még NINCS
 * workspace-kontextus — épp ez a lekérdezés állapítja meg, melyik tenanté a levél. Ugyanaz a
 * precedens, mint az API-kulcs és az SMTP-hitelesítő feloldásánál: egyetlen, szűk, pontos
 * egyezésre korlátozott lekérdezés, izolációs teszttel fedve.
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

        $domain = Domain::query()
            ->withoutGlobalScopes()
            ->where('name', $host)
            ->where('inbound_mx_status', 'verified')
            ->first();

        if ($domain === null) {
            return null;
        }

        return new InboundRecipient(
            workspaceId: (int) $domain->workspace_id,
            domainId: (int) $domain->getKey(),
            address: $address,
        );
    }
}
