<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\GateResult;
use App\Modules\Sending\Models\Message;

/**
 * ONE-CLICK UNSUBSCRIBE (RFC 8058) — marketing levélben KÖTELEZŐ (47. kritérium, golden rule 8).
 *
 * Ez nem formaság. A Gmail és a Yahoo 2024 februárja óta MEGKÖVETELI a tömeges küldőktől, és
 * a hiánya nem „rosszabb kézbesítés", hanem elutasítás. A logika mögötte egyszerű: ha a
 * leiratkozás nehéz, az olvasó a spam-gombot nyomja meg helyette — és a panasz a küldő
 * reputációját viszi, nem a levélét.
 *
 * KÉT fejléc kell EGYÜTT, és mindkettőnek konkrét szerepe van:
 *  - `List-Unsubscribe`: HOVA menjen a leiratkozás (URL és/vagy mailto);
 *  - `List-Unsubscribe-Post: List-Unsubscribe=One-Click`: hogy a levélkliens EGY KATTINTÁSSAL
 *    elvégezhesse, megerősítő oldal nélkül. Ez utóbbi nélkül a fejléc csak egy link.
 *
 * A GATE a `marketing` csatornára szól. A tranzakciós levélre nem: egy jelszó-visszaállítóról
 * nem lehet leiratkozni, és a fejléc ott félrevezető lenne.
 *
 * A kapu NEM az ügyfél fejlécét követeli meg — a küldési út MAGA teszi be (a `MessageDispatcher`
 * a leiratkozó-linkből). A kapu azt zárja ki, hogy bármely ÚT (REST, SMTP relay, kampány)
 * megkerülje ezt.
 */
final class UnsubscribeHeaderGate implements Gate
{
    public function name(): string
    {
        return 'unsubscribe_header';
    }

    public function check(Message $message): GateResult
    {
        if ($message->channel !== 'marketing') {
            return GateResult::pass('nem marketing');
        }

        $headers = array_change_key_case((array) $message->headers, CASE_LOWER);

        $unsubscribe = trim((string) ($headers['list-unsubscribe'] ?? ''));
        $post = strtolower(trim((string) ($headers['list-unsubscribe-post'] ?? '')));

        if ($unsubscribe === '') {
            return GateResult::reject(
                GateReason::InvalidRequest,
                'Marketing levélhez `List-Unsubscribe` fejléc kell (RFC 8058).',
            );
        }

        // A HTTPS URL kötelező: a Gmail és a Yahoo one-click követelménye kifejezetten
        // HTTP POST-ot vár, tehát a puszta `mailto:` nem elég.
        //
        // KIVÉTEL a helyi cím. A `localhost`-ot egyetlen fogadó sem tudja meghívni, tehát a
        // követelménynek ott nincs értelme — a fejlesztői környezet viszont enélkül egyetlen
        // marketing levelet sem tudna kiküldeni, és a fejlesztő a saját gépén ütközne egy
        // ÉLES szabályba. A `http://` élesben így is elbukik.
        if (! str_contains($unsubscribe, 'https://') && ! $this->isLocal($unsubscribe)) {
            return GateResult::reject(
                GateReason::InvalidRequest,
                'A `List-Unsubscribe` fejlécnek https URL-t is tartalmaznia kell (one-click).',
            );
        }

        if ($post !== 'list-unsubscribe=one-click') {
            return GateResult::reject(
                GateReason::InvalidRequest,
                'Hiányzik a `List-Unsubscribe-Post: List-Unsubscribe=One-Click` fejléc.',
            );
        }

        return GateResult::pass();
    }

    /** Helyi cím-e? A `localhost`-ot egyetlen fogadó sem tudja meghívni. */
    private function isLocal(string $header): bool
    {
        return preg_match('#https?://(localhost|127\.0\.0\.1|\[::1\])[:/]#i', $header) === 1;
    }
}
