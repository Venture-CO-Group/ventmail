<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Testing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A böngésző-suite helyi webhook-fogadója (D191) — kimenő kérés harmadik félhez nélkül.
 *
 * Nem kódút a kézbesítésben: a `DeliverWebhook` változatlanul fut (SSRF-őr, pinning,
 * aláírás); csak a HTTP-kliens válaszolja meg helyben az illeszkedő URL-t. Élesben SOHA nem
 * kapcsol be, akkor sem, ha valaki a változót beállítja.
 */
final class E2eWebhookSink
{
    public static function enabled(): bool
    {
        $prefix = (string) config('webhooks.e2e_sink', '');

        if ($prefix === '') {
            return false;
        }

        if (app()->isProduction() || ! app()->environment('local', 'testing')) {
            Log::warning('A WEBHOOKS_E2E_SINK csak local/testing környezetben hat — figyelmen kívül hagyva.');

            return false;
        }

        return str_starts_with($prefix, 'https://');
    }

    public static function install(): void
    {
        if (! self::enabled()) {
            return;
        }

        Http::fake([
            rtrim((string) config('webhooks.e2e_sink'), '*').'*' => Http::response('{"e2e_sink":true}', 200),
        ]);
    }
}
