<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Services;

use App\Modules\Domains\Models\Domain;

/**
 * MELYIK hoszton fut a leiratkozó link?
 *
 * A választás sorrendje: (1) az ügyfél VERIFIKÁLT nyomkövetési domainje, (2) a
 * platform-szintű, konfigurált hoszt, (3) az alkalmazás címe. Mindhárom esetben KÖTELEZŐ a
 * `https` — a token a leiratkozó személyét hordozza, és a levélkliensen, proxyn és a one-click
 * esetén a GMAIL SZERVERÉN megy át; titkosítatlan csatornán nem mehet ki.
 *
 * MIÉRT NEM a `TrackingBaseUrl` (A3), és miért nem másolat belőle:
 *
 *  - A `TrackingBaseUrl` megköveteli a `tracking_enabled`-t, és fail-closed: ha nincs
 *    verifikált nyomkövetési domain, NINCS nyomkövetés. A leiratkozásnál ugyanez azt
 *    jelentené, hogy aki KIKAPCSOLTA a kattintás-követést, az egyetlen marketing levelet sem
 *    tud kiküldeni (a kapu elutasítaná) — a nyomkövetés kikapcsolása és a leiratkozás
 *    lehetősége viszont két külön döntés.
 *  - A `TrackingBaseUrl` másik indoka a NYÍLT ÁTIRÁNYÍTÓ: a `/t/c/{token}` a megadott URL-re
 *    IRÁNYÍT. A `/u/{token}` nem irányít sehova — ír egy suppression-sort és megjelenít egy
 *    oldalt —, tehát az `app.url` originen sem lesz belőle átirányító.
 *
 * Ami viszont KÖZÖS és nem alku: a `http://` cím. Ott a védelem azonos, mert a kockázat azonos.
 */
final class UnsubscribeBaseUrl
{
    public function for(?Domain $domain): string
    {
        // A VERIFIKÁLT nyomkövetési domain az elsődleges: az ügyfél saját hosztján lévő link
        // az ő reputációját építi, nem egy közös hosztra folyatja össze mindenkiét.
        if ($domain !== null
            && $domain->tracking_status === 'verified'
            && ($domain->tracking_domain ?? '') !== '') {
            return 'https://'.$domain->tracking_domain;
        }

        foreach ([config('sending.tracking_base_url'), config('app.url')] as $candidate) {
            $url = rtrim((string) $candidate, '/');

            if ($url !== '' && (str_starts_with($url, 'https://') || $this->isLocal($url))) {
                return $url;
            }
        }

        // Nincs használható hoszt: a fejléc NEM születik meg, és az `UnsubscribeHeaderGate`
        // elutasítja a marketing levelet. Ez fail-closed, és szándékos: egy `http://`-s
        // leiratkozó link rosszabb, mint a kimaradt küldés.
        return '';
    }

    private function isLocal(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
