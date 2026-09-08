<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Services;

use App\Modules\Deliverability\Data\DmarcReport as ParsedReport;
use App\Modules\Deliverability\Models\DmarcReport;
use App\Modules\Deliverability\Models\DmarcReportRow;
use App\Modules\Domains\Models\Domain;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A DMARC-jelentés BETÖLTÉSE (44. kritérium).
 *
 * A parser megmondja, mi van a jelentésben; az ingestor azt dönti el, KIÉ, és megőrzi. A
 * kettő szándékosan külön: a parse idegen bemenetet dolgoz fel (gzip-bomba, XXE), az ingest
 * pedig tenant-adatot ír — a két kockázat nem keverhető egy osztályba.
 *
 * HÁROM zár:
 *
 *  1. **A jelentés domainje dönti el, kié — és ez a probléma ELTOLÁSA, nem a lezárása.** A jelentést BÁRKI elküldheti nekünk (az RUA-cím
 *     nyilvános a DNS-ben). Ha a feladó szavára írnánk be a workspace-t, egy hamisított
 *     jelentéssel bárki idegen tenant grafikonjára rajzolhatna. Ezért a `policy_published >
 *     domain` mezőt a SAJÁT, verifikált domainjeinkhez kötjük, és ami nem illik rá, azt
 *     eldobjuk.
 *
 *     AMIT EZ NEM ZÁR: ez a mező is a feladó szava. Egy hamis jelentés ma nem tud TÖRÖLNI
 *     (lásd a 2. pontot), de a forrásonkénti listát KORLÁTLANUL tudja szennyezni —
 *     hozzáadással. Ma latens: az `ingest()` egyetlen hívója az operátori CLI. A helyes
 *     hozzárendelés a FOGADÓ postafiókból/route-ból jön, a szállító levél DKIM-igazodásával
 *     a rekordban — és ez ELŐFELTÉTELE az A4 inbound route-ra kötött automatikus betöltésnek
 *     (PHASE.md · A6 backlog). Amíg az nincs meg, ez a bekötés nem történhet meg.
 *  2. **Idempotens — BÁJTRA azonos jelentésre.** Ugyanaz a jelentés kétszer is megérkezhet (a
 *     fogadók újraküldenek); az egyedi kulcs `(workspace_id, org_name, report_id, body_hash)`,
 *     és az újratöltés FRISSÍT, nem duplikál. A `body_hash` nélkül az `(org_name, report_id)`
 *     páros — ami a FELADÓ SZAVA — elég volt ahhoz, hogy egy hamisítvány a VALÓDI jelentés
 *     sorait letörölje. Az „idempotens" így csak a bájtra azonos újraküldésre igaz: ugyanaz a
 *     jelentés másképp gzippelve új sor lesz. Ez tudatos csere — a duplikált sor rossz, a
 *     letörölt valódi adat rosszabb.
 *  3. **`known_source`.** Minden sor forrás-IP-jét a saját küldő IP-inkhez mérjük. Ez az a
 *     mező, amiért az egész tábla létezik: az ismeretlen forrás vagy egy elfelejtett saját
 *     rendszer, vagy egy hamisító.
 */
final class DmarcReportIngestor
{
    public function __construct(
        private readonly DmarcReportParser $parser,
        private readonly WorkspaceContext $context,
    ) {}

    /** @throws InvalidArgumentException ha a jelentés nem a mi domainünkről szól */
    public function ingest(string $raw): DmarcReport
    {
        $parsed = $this->parser->parse($raw);

        // A TÖRZS lenyomata a kulcs része: az `(org_name, report_id)` páros a feladó szava,
        // tehát hamisítható. Enélkül egy hamis jelentés FELÜLÍRTA volna a valódit.
        $bodyHash = hash('sha256', $raw);

        $domain = $this->domainOf($parsed);

        if ($domain === null) {
            // NEM a mi domainünk — a jelentés vagy téves címre jött, vagy szándékos.
            // Egyik esetben sem írunk tenant-adatot idegen szó alapján.
            throw new InvalidArgumentException(sprintf(
                'A DMARC-jelentés ismeretlen domainről szól: %s',
                $parsed->domain,
            ));
        }

        $known = $this->knownSources();

        // A betöltés ÜTEMEZETTEN fut, ahol nincs bejelentkezett tenant: a kontextust a
        // jelentés domainje adja, és a művelet után VISSZAÁLLÍTJUK. A `BelongsToWorkspace`
        // enélkül (helyesen) elszállna — és ez a jó irány: a tenant-határt nem a hívó
        // figyelmessége, hanem a modell őrzi.
        $previous = $this->context->hasWorkspace() ? $this->context->workspace() : null;

        $this->context->set($domain->workspace);

        try {
            return $this->persist($parsed, $domain, $known, $bodyHash);
        } finally {
            $previous === null ? $this->context->forget() : $this->context->set($previous);
        }
    }

    /** @param list<string> $known */
    private function persist(ParsedReport $parsed, Domain $domain, array $known, string $bodyHash): DmarcReport
    {
        return DB::transaction(function () use ($parsed, $domain, $known, $bodyHash): DmarcReport {
            // A `workspace_id` SZÁNDÉKOSAN nincs a keresőkulcsban és az attribútumokban: a
            // `BelongsToWorkspace` a KONTEXTUSBÓL írja (és eltérő értékkel hangosan elszáll),
            // az olvasást pedig a global scope szűkíti. Kézzel átadva a tömeges kitöltés
            // őrébe futnánk — helyesen: a tenant-határ nem a hívó paramétere.
            $report = DmarcReport::query()->updateOrCreate(
                [
                    'org_name' => $parsed->orgName,
                    'report_id' => $parsed->reportId,
                    'body_hash' => $bodyHash,
                ],
                [
                    'domain_id' => $domain->getKey(),
                    'policy' => $parsed->policy,
                    'range_start' => $parsed->rangeStart > 0 ? now()->setTimestamp($parsed->rangeStart) : null,
                    'range_end' => $parsed->rangeEnd > 0 ? now()->setTimestamp($parsed->rangeEnd) : null,
                    'total' => $parsed->total(),
                    'passed' => $parsed->passed(),
                    'failed' => $parsed->failed(),
                    'expires_at' => now()->addDays((int) config('deliverability.dmarc.retention_days')),
                ],
            );

            // ÚJRATÖLTÉSNÉL a sorok cserélődnek, nem halmozódnak: a jelentés egy zárt
            // időszakról szól, tehát a második változat a teljes igazság, nem kiegészítés.
            // A törlés CSAK a bájtra azonos jelentés sorait érinti (a `body_hash` a kulcs
            // része), tehát egy hamisítvány nem tudja letörölni a valódi adatot.
            $report->rows()->delete();

            foreach ($parsed->rows as $row) {
                DmarcReportRow::query()->create([
                    'dmarc_report_id' => $report->getKey(),
                    'source_ip' => $row->sourceIp,
                    'count' => $row->count,
                    'disposition' => $row->disposition,
                    'spf_result' => $row->spf,
                    'dkim_result' => $row->dkim,
                    'header_from' => $row->headerFrom,
                    'known_source' => in_array($row->sourceIp, $known, true),
                ]);
            }

            return $report->fresh();
        });
    }

    /**
     * FORRÁSONKÉNTI aggregáció, az ISMERETLEN forrásokkal az élen.
     *
     * A rendezés nem kozmetika: egy jelentésben több száz forrás lehet, és a lényeg a lista
     * elején van. Az ismeretlen, sok levelet küldő forrás az, amit meg kell nézni — a saját,
     * igazodó IP-nk unalmas.
     *
     * @return list<array{source_ip: string, count: int, known: bool, passed: int}>
     */
    public function sources(Domain $domain, int $days = 30): array
    {
        return DmarcReportRow::query()
            ->join('dmarc_reports', 'dmarc_reports.id', '=', 'dmarc_report_rows.dmarc_report_id')
            ->where('dmarc_reports.domain_id', $domain->getKey())
            ->where('dmarc_reports.range_start', '>=', now()->subDays($days))
            ->groupBy('dmarc_report_rows.source_ip', 'dmarc_report_rows.known_source')
            ->orderBy('dmarc_report_rows.known_source')
            ->orderByDesc(DB::raw('sum(dmarc_report_rows.count)'))
            ->get([
                'dmarc_report_rows.source_ip',
                'dmarc_report_rows.known_source',
                DB::raw('sum(dmarc_report_rows.count) as total'),
                DB::raw("sum(case when lower(dmarc_report_rows.spf_result) = 'pass' or lower(dmarc_report_rows.dkim_result) = 'pass' then dmarc_report_rows.count else 0 end) as passed"),
            ])
            ->map(fn ($row) => [
                'source_ip' => (string) $row->source_ip,
                'count' => (int) $row->total,
                'known' => (bool) $row->known_source,
                'passed' => (int) $row->passed,
            ])
            ->values()
            ->all();
    }

    /**
     * Melyik SAJÁT, verifikált domainünkről szól a jelentés?
     *
     * A `withoutGlobalScopes` itt SZÁNDÉKOS és dokumentált: a betöltés ütemezetten fut, ahol
     * nincs bejelentkezett workspace-kontextus — a jelentés MAGA mondja meg, melyik tenanté,
     * és épp ezt kell megállapítanunk. A találat egyértelműségét a `limit(2)` őrzi: ha
     * ugyanaz a domain két workspace-ben is szerepelne, inkább NEM töltjük be, mint hogy a
     * rossz tenanthoz írjuk (ugyanaz a minta, mint a `VerifiedInboundResolver`-ben).
     */
    private function domainOf(ParsedReport $parsed): ?Domain
    {
        $name = DomainNormalizer::canonical(trim($parsed->domain));

        if ($name === '' || $name === null) {
            return null;
        }

        $matches = Domain::query()
            ->withoutGlobalScopes()
            ->where('name', $name)
            ->where('status', 'verified')
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return list<string> */
    private function knownSources(): array
    {
        return IpAddress::query()->pluck('address')->map(fn ($ip) => (string) $ip)->values()->all();
    }
}
