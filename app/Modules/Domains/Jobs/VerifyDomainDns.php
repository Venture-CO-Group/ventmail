<?php

declare(strict_types=1);

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Models\Domain;

/**
 * A DNS-hitelesítés állapotgépe (M0-4 elfogadási kritérium #24).
 *
 * A `verified → failed` visszaesés ugyanúgy kezelt, mint az előrelépés: ha az ügyfél
 * törli a DKIM-rekordot, a domain elveszti a hitelesítését, és a PreSendGate 2. kapuja
 * onnantól elutasít. Egy „egyszer verified, mindig verified" logika csendben engedné a
 * hitelesítetlen küldést (golden rule 8).
 */
final class VerifyDomainDns
{
    public function __construct(private readonly DnsResolver $resolver) {}

    public function handle(Domain $domain): Domain
    {
        $records = $domain->dnsRecords();

        $statuses = [
            'spf_status' => $this->check($records['spf']),
            'dkim_status' => $this->check($records['dkim']),
            'dmarc_status' => $this->check($records['dmarc']),
            'tracking_status' => $this->check($records['tracking']),
        ];

        // A `verified` CSAK teljes egyezésre jár: SPF + DKIM + DMARC mind kell.
        // A tracking-domain opcionális, a küldés hitelességéhez nem tartozik hozzá.
        $authenticated = $statuses['spf_status'] === 'verified'
            && $statuses['dkim_status'] === 'verified'
            && $statuses['dmarc_status'] === 'verified';

        $domain->fill([
            ...$statuses,
            'status' => $authenticated ? 'verified' : 'failed',
            'verified_at' => $authenticated ? ($domain->verified_at ?? now()) : null,
            'last_checked_at' => now(),
        ])->save();

        return $domain;
    }

    /** @param array{type: string, name: string, value: string, purpose: string} $record */
    private function check(array $record): string
    {
        $found = $this->resolver->records($record['name'], $record['type']);

        foreach ($found as $value) {
            if ($this->matches($record, (string) $value)) {
                return 'verified';
            }
        }

        return 'failed';
    }

    /**
     * A TXT-rekordokat a fogadó oldalak szóközre és idézőjelre érzéketlenül olvassák;
     * a DNS-szolgáltatók pedig gyakran darabolnak. Ezért normalizálva hasonlítunk.
     *
     * @param  array{type: string, name: string, value: string, purpose: string}  $record
     */
    private function matches(array $record, string $found): bool
    {
        $normalize = static fn (string $value): string => strtolower(
            preg_replace('/\s+/', '', str_replace(['"', "'"], '', $value)) ?? ''
        );

        return $normalize($found) === $normalize($record['value']);
    }
}
