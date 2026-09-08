<?php

declare(strict_types=1);

namespace App\Modules\Sending\Gate\Gates;

use App\Modules\Sending\Enums\Channel;
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
 * A GATE MINDEN consent-köteles csatornára szól — `marketing`, `journey` ÉS `campaign` —,
 * mert a Studio tömegforgalma épp az utóbbi kettőn megy. A tranzakciós levélre nem: egy
 * jelszó-visszaállítóról nem lehet leiratkozni, és a fejléc ott félrevezető lenne.
 *
 * A csatornát ENUMMAL kérdezzük, MEGFORDÍTOTT logikával (ugyanaz a minta, mint a
 * `ConsentGate`-ben): nem azt kérdezzük, „marketing-e", hanem hogy consent-mentes-e. Egy
 * nyers `!== 'marketing'` összehasonlítás a `campaign` és a `journey` sorokat átengedte
 * volna — pont azt a forgalmat, amit a Gmail és a Yahoo 2024 óta elutasít.
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
        if (! Channel::from((string) $message->channel)->requiresConsent()) {
            return GateResult::pass('tranzakciós csatorna');
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
        if (! $this->hasOneClickUrl($unsubscribe)) {
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

    /**
     * Van-e a fejlécben VALÓDI one-click URL?
     *
     * A fejléc `<…>`-be zárt címek vesszővel elválasztott listája (RFC 2369), és a
     * részsztring-vizsgálat itt megtévedhet: a `<mailto:le@pelda.hu?subject=https://>` sor
     * tartalmazza a `https://`-t, de nincs benne egyetlen hívható URL sem — a fogadó
     * one-click POST-ja sehova nem menne, a levél pedig a kapun átcsúszott volna.
     *
     * Ezért a listát FELBONTJUK, és legalább egy elemtől megköveteljük, hogy `https://`-sel
     * KEZDŐDJÖN.
     */
    private function hasOneClickUrl(string $header): bool
    {
        preg_match_all('/<([^>]*)>/', $header, $matches);

        foreach ($matches[1] as $entry) {
            $entry = trim((string) $entry);

            if (str_starts_with(strtolower($entry), 'https://') || $this->isLocal($entry)) {
                return true;
            }
        }

        return false;
    }

    /** Helyi cím-e? A `localhost`-ot egyetlen fogadó sem tudja meghívni. */
    private function isLocal(string $url): bool
    {
        return preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])([:/]|$)#i', $url) === 1;
    }
}
