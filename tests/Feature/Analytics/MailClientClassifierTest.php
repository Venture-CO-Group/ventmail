<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\MailClientClassifier;

/*
 | v1.1 · 8. modul (D229) — levelezőkliens- és eszköz-besorolás a User-Agentből. Rögzített
 | kategória-kulcsok; a képproxyk és az Apple MPP mögött az eszköz NEM ismerhető meg.
 */

dataset('user agents', [
    'Gmail képproxy' => ['Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', 'gmail', 'unknown'],
    'Yahoo proxy' => ['YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy-SLN28749.html', 'yahoo', 'unknown'],
    'Apple MPP' => ['Mozilla/5.0', 'apple_mpp', 'unknown'],
    'Apple Mail (macOS)' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)', 'apple_mail', 'desktop'],
    'iOS Mail' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 'ios_mail', 'mobile'],
    'iPad Mail' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 'ios_mail', 'tablet'],
    'Outlook desktop' => ['Mozilla/4.0 (compatible; ms-office; MSOffice 16)', 'outlook', 'unknown'],
    'Outlook Windows' => ['Microsoft Outlook 16.0.17126; Windows NT 10.0', 'outlook', 'desktop'],
    'Thunderbird' => ['Mozilla/5.0 (X11; Linux x86_64; rv:115.0) Gecko/20100101 Thunderbird/115.3.1', 'thunderbird', 'desktop'],
    'Android levelező (WebView)' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/UQ1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.230 Mobile Safari/537.36', 'android', 'mobile'],
    'Android Chrome böngésző' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36', 'other', 'mobile'],
    'bot' => ['curl/8.4.0', 'bot', 'unknown'],
    'üres' => ['', 'other', 'unknown'],
    'asztali böngésző (webmail)' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', 'other', 'desktop'],
]);

it('rögzített kategóriát ad', function (string $ua, string $client, string $device) {
    expect(app(MailClientClassifier::class)->classify($ua))->toBe(['device' => $device, 'client' => $client]);
})->with('user agents');

it('csak a nevesített kulcsokat adja (a nyers UA sosem jön vissza)', function () {
    $out = app(MailClientClassifier::class)->classify(str_repeat('Valami/1.0 ', 500).'<script>');

    expect(MailClientClassifier::CLIENTS)->toContain($out['client'])
        ->and(MailClientClassifier::DEVICES)->toContain($out['device']);
});
