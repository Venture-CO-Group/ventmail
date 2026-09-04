<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Contracts;

use App\Modules\Inbound\Data\InboundRecipient;

/**
 * „A MIÉNK ez a címzett?"
 *
 * Ez az MX-oldal NYÍLT RELAY ZÁRA. A submission-oldalon a hitelesítés dönti el, kinek a
 * nevében küldhetünk; itt nincs hitelesítés — a kérdés az, hogy a levél NEKÜNK szól-e.
 *
 * Ha bármelyik címzettre `null` a válasz, a levél NEM FOGADHATÓ EL: az idegen domainre
 * címzett, elfogadott levél pontosan az, amit a világ nyílt relaynek nevez.
 *
 * Interfész mögött, mert a válasz a domain-modulé (verifikált MX-státusz), és a
 * protokoll-rétegnek nem dolga tudni, hogyan dől el.
 */
interface InboundRecipientResolver
{
    public function resolve(string $recipient): ?InboundRecipient;
}
