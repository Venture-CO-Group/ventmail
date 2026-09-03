<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Contracts\HostResolver;

/** A rendszer DNS-e. */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $records = array_merge(
            @dns_get_record($host, DNS_A) ?: [],
            @dns_get_record($host, DNS_AAAA) ?: []
        );

        $addresses = [];

        foreach ($records as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($addresses));
    }
}
