<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Contracts;

/**
 * Hosztnév → IP-címek. Interfész mögött, mert ez KÜLSŐ gazda (DNS).
 *
 * A tesztben determinisztikus fake áll a helyén: valódi DNS-től függő SSRF-teszt vagy
 * hálózatot igényelne, vagy — ami rosszabb — csendben átengedné a vizsgált esetet.
 */
interface HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
