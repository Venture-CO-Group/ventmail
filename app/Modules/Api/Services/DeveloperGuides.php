<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Modules\Api\Support\ApiRateLimits;
use App\Modules\Messages\Data\MailgunPayload;
use App\Modules\Webhooks\Jobs\DeliverWebhook;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * A FEJLESZTŐI DOKSI PRÓZAI ÚTMUTATÓI (UI-polírozás · 9. pont, D305).
 *
 * EGYETLEN forrásfájl: `docs/developer/guides.yaml`. A szöveg ott él (a felület mind a 7 nyelvén,
 * D309), a SZÁMOK viszont nem: a `{{kulcs}}` helyőrzőket itt, a futó kódból és
 * konfigurációból töltjük ki (rate-limit, SMTP, webhook-újrapróbálás, aláírás-tűrés) — a doksi
 * így nem mondhat mást, mint ami fut. Ismeretlen helyőrző = hiba (a teszt elkapja).
 *
 * A törzs Markdown → HTML a CommonMark (GFM) konverterrel, NYERS HTML NÉLKÜL (`html_input:
 * strip`) és nem biztonságos link nélkül: a kimenet a felületen `v-html`-ként jelenik meg.
 */
final class DeveloperGuides
{
    public const SOURCE = 'docs/developer/guides.yaml';

    /** A forrásban kötelező nyelvek (a felület 7 nyelve, D309). */
    public const LOCALES = ['hu', 'en', 'de', 'fr', 'it', 'es', 'ru'];

    /** @return list<array{key: string, soon: bool, title: string, html: string, text: string}> */
    public function build(string $locale, string $baseUrl): array
    {
        $values = $this->values($baseUrl);
        // A forrás módosítási ideje + nyelv + a kitöltött értékek: bármelyik változik, új kulcs.
        $key = 'developer-guides:'.(string) @filemtime(base_path(self::SOURCE)).':'.$locale.':'.md5((string) json_encode($values));

        return Cache::remember($key, 3600, fn (): array => $this->render($locale, $values));
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{key: string, soon: bool, title: string, html: string, text: string}>
     */
    private function render(string $locale, array $values): array
    {
        $source = Yaml::parseFile(base_path(self::SOURCE));
        // D309: mind a 7 felületi nyelv a forrásban; ismeretlen nyelv → angol.
        $language = in_array($locale, self::LOCALES, true) ? $locale : 'en';
        $out = [];

        foreach ((array) ($source['guides'] ?? []) as $guide) {
            $body = $this->fill((string) ($guide['body'][$language] ?? $guide['body']['en'] ?? ''), $values, (string) $guide['key']);

            $out[] = [
                'key' => (string) $guide['key'],
                'soon' => (bool) ($guide['soon'] ?? false),
                'title' => (string) ($guide['title'][$language] ?? $guide['title']['en'] ?? $guide['key']),
                'html' => Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
                // A KERESŐNEK: a törzs jelölés nélkül.
                'text' => trim((string) preg_replace('/[`*_#|>]+/', ' ', $body)),
            ];
        }

        return $out;
    }

    /** @return array<string, string> */
    public function values(string $baseUrl): array
    {
        $backoff = [];

        for ($attempt = 1; $attempt < DeliverWebhook::MAX_ATTEMPTS; $attempt++) {
            $backoff[] = (string) intdiv(DeliverWebhook::backoffSeconds($attempt), 60);
        }

        return [
            'base_url' => $baseUrl,
            'test_key_prefix' => 'vm_test_',
            'batch_max' => (string) MailgunPayload::MAX_RECIPIENTS,
            'smtp_host' => (string) config('smtp.hostname'),
            'smtp_max_mb' => (string) intdiv((int) config('smtp.limits.max_message_bytes'), 1024 * 1024),
            'smtp_rate' => (string) (int) config('smtp.rate_limit.per_credential_per_minute'),
            'tolerance' => (string) WebhookSigner::TOLERANCE_SECONDS,
            'future_tolerance' => (string) WebhookSigner::FUTURE_TOLERANCE_SECONDS,
            'webhook_attempts' => (string) DeliverWebhook::MAX_ATTEMPTS,
            'webhook_backoff' => implode(' → ', $backoff).' min',
            'api_per_key' => (string) ApiRateLimits::PER_KEY,
            'api_per_peer' => (string) ApiRateLimits::PER_PEER,
            'api_anonymous' => (string) ApiRateLimits::ANONYMOUS_PER_PEER,
        ];
    }

    /** @param array<string, string> $values */
    private function fill(string $body, array $values, string $guide): string
    {
        return (string) preg_replace_callback('/\{\{(\w+)\}\}/', static function (array $match) use ($values, $guide): string {
            if (! array_key_exists($match[1], $values)) {
                throw new RuntimeException("Ismeretlen helyőrző a(z) {$guide} útmutatóban: {$match[1]}");
            }

            return $values[$match[1]];
        }, $body);
    }
}
