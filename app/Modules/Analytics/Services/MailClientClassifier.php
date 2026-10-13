<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

/**
 * LEVELEZŐKLIENS- ÉS ESZKÖZ-BESOROLÁS a nyitás/kattintás User-Agentjéből (v1.1 · 8. modul, D229).
 *
 * Saját, kicsi, NEVESÍTETT szabálytábla — nem külső UA-adatbázis (licenc, méret, és a levelező-
 * kliensekre amúgy sincs megbízható nyílt lista). A kimenet ROGZÍTETT kategória-kulcs; a nyers
 * UA-t a hívó eldobja, soha nem perzisztálja.
 *
 * ## Képproxyk és az Apple MPP
 *
 * A Gmail (`GoogleImageProxy`), a Yahoo (`YahooMailProxy`) és az Apple Mail Privacy Protection a
 * képet a SAJÁT szerveréről tölti le: az eszköz ilyenkor nem ismerhető meg → `unknown`. Az MPP
 * proxyja egy csupasz `Mozilla/5.0` UA-t küld — ezt `apple_mpp`-ként jelöljük, és a felület
 * „gépi megnyitás (becsült)" felirattal mutatja.
 */
final class MailClientClassifier
{
    public const DEVICES = ['desktop', 'mobile', 'tablet', 'unknown'];

    public const CLIENTS = [
        'apple_mail', 'apple_mpp', 'gmail', 'yahoo', 'outlook', 'ios_mail', 'android',
        'thunderbird', 'webmail_other', 'bot', 'other',
    ];

    /**
     * Sorrendben az első találat nyer. `device` = `null` → az eszköz a UA-ból jön.
     *
     * @var list<array{0: string, 1: string, 2: ?string}> [regex, kliens, rögzített eszköz]
     */
    private const RULES = [
        ['/GoogleImageProxy|ggpht\.com/i', 'gmail', 'unknown'],
        ['/YahooMailProxy/i', 'yahoo', 'unknown'],
        ['/^Mozilla\/5\.0$/', 'apple_mpp', 'unknown'],
        ['/bot|crawler|spider|curl|wget|python-requests|Go-http-client|HeadlessChrome|Barracuda|Mimecast|Proofpoint|SafeLinks/i', 'bot', 'unknown'],
        ['/Microsoft Outlook|ms-office|Outlook-iOS|Outlook-Android|MSOffice/i', 'outlook', null],
        ['/Thunderbird/i', 'thunderbird', 'desktop'],
        ['/(iPhone|iPad|iPod).*Mobile\/[0-9A-Z]+$/i', 'ios_mail', null],
        ['/Macintosh.*AppleWebKit(?!.*(Safari|Chrome|Firefox|Edg))/i', 'apple_mail', 'desktop'],
        // Android levelező-app: WebView (`; wv)`) vagy Chrome nélküli Android-UA. Az Android-os
        // Chrome BÖNGÉSZŐ (webmail) nem ide tartozik.
        ['/Android.*; wv\)|Android(?!.*Chrome)/i', 'android', null],
        ['/(mail\.|webmail|roundcube|zimbra|horde|freenet|gmx|web\.de|mail\.ru|yandex|protonmail|aol)/i', 'webmail_other', null],
    ];

    private const TABLET = '/iPad|Tablet|Android(?!.*Mobile)/i';

    private const MOBILE = '/Mobile|iPhone|iPod|Android|Windows Phone/i';

    /** @return array{device: string, client: string} */
    public function classify(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return ['device' => 'unknown', 'client' => 'other'];
        }

        // Korlátos bemenet: a regexek ne fussanak egy több kilobájtos fejlécen.
        $ua = mb_substr($ua, 0, 512);

        foreach (self::RULES as [$pattern, $client, $device]) {
            if (preg_match($pattern, $ua) === 1) {
                return ['device' => $device ?? $this->device($ua), 'client' => $client];
            }
        }

        return ['device' => $this->device($ua), 'client' => 'other'];
    }

    private function device(string $ua): string
    {
        if (preg_match(self::TABLET, $ua) === 1) {
            return 'tablet';
        }

        if (preg_match(self::MOBILE, $ua) === 1) {
            return 'mobile';
        }

        if (preg_match('/Windows|Macintosh|X11|Linux|CrOS/i', $ua) === 1) {
            return 'desktop';
        }

        return 'unknown';
    }
}
