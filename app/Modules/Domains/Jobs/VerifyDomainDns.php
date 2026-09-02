<?php

declare(strict_types=1);

namespace App\Modules\Domains\Jobs;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DnsRecordMatcher;

/**
 * A DNS-hitelesítés állapotgépe (M0-4 elfogadási kritérium #24).
 *
 * A `verified → failed` visszaesés ugyanúgy kezelt, mint az előrelépés: ha az ügyfél
 * törli a DKIM-rekordot, a domain elveszti a hitelesítését, és a PreSendGate 1. kapuja
 * onnantól elutasít. Egy „egyszer verified, mindig verified" logika csendben engedné a
 * hitelesítetlen küldést (golden rule 8).
 *
 * ROTÁCIÓ ALATT az előző selector is hitelesnek számít (`rotating` státusz): enélkül a
 * rotáció pillanatától a domain `failed` lenne, és MINDEN küldése elakadna.
 */
final class VerifyDomainDns
{
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly DnsRecordMatcher $matcher,
    ) {}

    public function handle(Domain $domain): Domain
    {
        $records = $domain->dnsRecords();

        $spf = $this->matcher->spfMatches($this->lookup($records['spf']));
        $dmarc = $this->matcher->dmarcMatches($this->lookup($records['dmarc']));
        $tracking = $this->matcher->cnameMatches($this->lookup($records['tracking']), $records['tracking']['value']);

        [$dkimStatus, $dkimAuthenticated] = $this->checkDkim($domain, $records);

        // A `verified` CSAK teljes egyezésre jár: SPF + DKIM + DMARC mind kell (golden rule 8).
        // A tracking-domain opcionális — a küldés hitelességéhez nem tartozik hozzá.
        $authenticated = $spf && $dmarc && $dkimAuthenticated;

        $domain->fill([
            'spf_status' => $spf ? 'verified' : 'failed',
            'dkim_status' => $dkimStatus,
            'dmarc_status' => $dmarc ? 'verified' : 'failed',
            'tracking_status' => $tracking ? 'verified' : 'pending',
            'status' => $authenticated ? 'verified' : 'failed',
            // A `verified_at` BIZONYÍTÉK: mikortól küldhetett a tenant erről a domainről.
            // A visszaesés ezt nem törli — külön mező rögzíti, mikor veszett el.
            'verified_at' => $authenticated ? ($domain->verified_at ?? now()) : $domain->verified_at,
            'unverified_at' => $authenticated ? null : now(),
            'last_checked_at' => now(),
        ])->save();

        return $domain;
    }

    /**
     * @param  array<string, array<string, string>>  $records
     * @return array{0: string, 1: bool} a DKIM-státusz és hogy hitelesnek számít-e
     */
    private function checkDkim(Domain $domain, array $records): array
    {
        $found = $this->lookup($records['dkim']);

        if ($this->matcher->dkimMatches($found, (string) $domain->dkim_public_key)) {
            // Az ÚJ rekord látszik: mostantól az új kulccsal írunk alá.
            if ($domain->dkim_active_selector !== null && $domain->dkim_active_selector !== $domain->dkim_selector) {
                $domain->dkim_active_selector = $domain->dkim_selector;
            }

            return ['verified', true];
        }

        // Az ÚJ selector még nincs kint, de az előző az átfedési ablakon belül van és
        // feloldható: a domain hiteles marad, a wizard viszont mutatja, hogy rotáció van.
        if ($domain->previousDkimIsValid()) {
            $previous = $this->lookup([
                'name' => sprintf('%s._domainkey.%s', $domain->dkim_previous_selector, $domain->name),
                'type' => 'TXT',
            ]);

            if ($this->matcher->dkimMatches($previous, (string) $domain->dkim_previous_public_key)) {
                return ['rotating', true];
            }
        }

        return ['failed', false];
    }

    /**
     * @param  array<string, string>  $record
     * @return list<string>
     */
    private function lookup(array $record): array
    {
        return $this->resolver->records($record['name'], $record['type']);
    }
}
