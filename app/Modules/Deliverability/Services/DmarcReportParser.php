<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Services;

use App\Modules\Deliverability\Data\DmarcReport;
use App\Modules\Deliverability\Data\DmarcRow;
use RuntimeException;

/**
 * DMARC RUA jelentés értelmezése (44. kritérium).
 *
 * A jelentés a FOGADÓKTÓL jön, és ez az EGYETLEN forrás, amiből megtudjuk, hogy a mi
 * domainünk nevében ki küld. Az ismeretlen forrás vagy egy elfelejtett saját rendszer (a
 * marketinges regisztrált egy külső eszközt), vagy egy hamisító — mindkettő azonnali
 * figyelmet érdemel, és a DMARC-jelentés nélkül egyikről sem tudnánk.
 *
 * A bemenet IDEGEN, és tömörítve érkezik: a fogadók gzip-elve (`.xml.gz`) küldik. A
 * gzip-bomba ugyanaz a veszély, mint a bejövő csatolmánynál — a kicsomagolt méretet
 * KORLÁTOZZUK, és darabokban bontjuk ki. A ZIP-be csomagolt változat (néhány fogadó így
 * küld) NEM támogatott: az A4 `ArchiveInspector`-a a gazdája, és a kettőt nem duplikáljuk.
 *
 * Az XML-parse `LIBXML_NONET`-tel megy: az XXE (külső entitás) klasszikus vektora épp az
 * ilyen, harmadik féltől érkező XML. Ezzel a parser nem tud fájlt olvasni és nem tud hálózatra
 * menni, akármit tartalmaz a dokumentum.
 */
final class DmarcReportParser
{
    public function parse(string $raw): DmarcReport
    {
        $xml = $this->decompress($raw);

        // XXE ELLEN: hálózat tiltva, DTD nem dolgozódik fel. Egy harmadik féltől érkező XML
        // különben a `/etc/passwd`-t vagy egy belső URL-t olvastathatna fel velünk.
        $previous = libxml_use_internal_errors(true);

        // CSAK `LIBXML_NONET`. A `LIBXML_NOENT` (entitás-behelyettesítés) SZÁNDÉKOSAN nincs
        // itt: az a kapcsoló ÉLESÍTENÉ az XXE-t, nem védene ellene — a neve félrevezető.
        // Korábban `LIBXML_NONET | LIBXML_NOENT & 0` állt itt: az operátor-precedencia miatt
        // pontosan ugyanez az érték (2048), de aki elírásnak nézi és leszedi a `& 0`-t, az
        // egy karakterrel kinyitja a külső entitásokat.
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RuntimeException('A DMARC-jelentés XML-je nem értelmezhető.');
        }

        $metadata = $document->report_metadata ?? null;
        $policy = $document->policy_published ?? null;

        $rows = [];

        foreach ($document->record ?? [] as $record) {
            $rows[] = new DmarcRow(
                sourceIp: (string) ($record->row->source_ip ?? ''),
                count: (int) ($record->row->count ?? 0),
                disposition: (string) ($record->row->policy_evaluated->disposition ?? ''),
                spf: (string) ($record->row->policy_evaluated->spf ?? ''),
                dkim: (string) ($record->row->policy_evaluated->dkim ?? ''),
                headerFrom: (string) ($record->identifiers->header_from ?? ''),
            );
        }

        return new DmarcReport(
            orgName: (string) ($metadata->org_name ?? ''),
            reportId: (string) ($metadata->report_id ?? ''),
            domain: (string) ($policy->domain ?? ''),
            policy: (string) ($policy->p ?? ''),
            rangeStart: (int) ($metadata->date_range->begin ?? 0),
            rangeEnd: (int) ($metadata->date_range->end ?? 0),
            rows: $rows,
        );
    }

    /**
     * A tömörített jelentés kibontása — KORLÁTOZOTT mérettel.
     *
     * A fogadók gzip-elve küldik; a gzip-bomba ugyanaz a veszély, mint a bejövő csatolmánynál.
     * A `gzdecode` egyben bontana ki mindent a memóriába, ezért darabokban dolgozunk, és a
     * plafon fölött MEGÁLLUNK.
     */
    private function decompress(string $raw): string
    {
        if (! str_starts_with($raw, "\x1f\x8b")) {
            return $raw;
        }

        $max = (int) config('deliverability.dmarc.max_report_bytes');

        $handle = @gzopen('data://application/octet-stream;base64,'.base64_encode($raw), 'rb');

        if ($handle === false) {
            throw new RuntimeException('A gzip-elt DMARC-jelentés nem bontható ki.');
        }

        $out = '';

        try {
            while (! gzeof($handle)) {
                $out .= (string) gzread($handle, 65_536);

                if (strlen($out) > $max) {
                    throw new RuntimeException('A kicsomagolt DMARC-jelentés meghaladja a korlátot.');
                }
            }
        } finally {
            gzclose($handle);
        }

        return $out;
    }
}
