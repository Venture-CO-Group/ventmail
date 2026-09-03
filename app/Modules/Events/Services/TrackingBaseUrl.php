<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Modules\Domains\Models\Domain;

/**
 * MELYIK hoszton fut a nyomkövetés ehhez az üzenethez?
 *
 * A válasz nem az alkalmazás domainje, és ez két okból fontos:
 *
 * 1. **Reputáció.** A domain-wizard (M0-4) az ügyféltől EGY `email.<domain> CNAME
 *    track.ventmail.app` rekordot kér, és verifikálja is (`domains.tracking_status`). Ha a
 *    küldés ezt nem használná, az ügyfél beállítana egy DNS-rekordot, amit a termék figyelmen
 *    kívül hagy — és minden ügyfél link-reputációja EGYETLEN közös hosztra folyna össze
 *    (golden rule 8).
 *
 * 2. **Nyílt átirányító.** A `/t/c/{token}` az alkalmazás domainjén azon az originen ülne,
 *    ami a bejelentkezett session cookie-ját szolgálja ki. Bármelyik tenant kiválthatna
 *    magának tetszőleges célra mutató linket (küld magának egy levelet, és kiolvassa a kimenő
 *    törzsből) — ez működő nyílt átirányító lenne a fő domainen, adathalászathoz és
 *    host-allowlistes return-URL ellenőrzések megkerüléséhez egyaránt.
 *
 * Ha nincs verifikált nyomkövetési domain ÉS nincs explicit platform-hoszt konfigurálva,
 * akkor NINCS nyomkövetés. Ez fail-closed: inkább hiányzik a nyitási statisztika, mint hogy
 * nyílt átirányítót adjunk a saját domainünkön.
 */
final class TrackingBaseUrl
{
    public function for(?Domain $domain): string
    {
        if ($domain !== null
            && $domain->tracking_enabled
            && $domain->tracking_status === 'verified'
            && ($domain->tracking_domain ?? '') !== '') {
            return 'https://'.$domain->tracking_domain;
        }

        // Platform-szintű tartalék (pl. megosztott nyomkövetési hoszt vagy fejlesztői
        // környezet). SZÁNDÉKOSAN nem esik vissza az `app.url`-re.
        $configured = rtrim((string) config('sending.tracking_base_url'), '/');

        // A tokent MINDEN link viszi, és az egyszerre a címzett azonosítójának burka és
        // bemutatóra szóló hitelesítő az esemény-rögzítéshez. Titkosítatlan csatornán nem
        // mehet ki — kivéve a helyi fejlesztést, ahol nincs mit lehallgatni.
        if ($configured !== '' && ! str_starts_with($configured, 'https://') && ! $this->isLocal($configured)) {
            return '';
        }

        return $configured;
    }

    private function isLocal(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
