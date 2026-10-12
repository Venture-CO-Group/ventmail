<?php

declare(strict_types=1);

namespace App\Modules\Audience\Providers;

use App\Modules\Audience\Console\PurgeImportsCommand;
use App\Modules\Audience\Console\ReindexContactsCommand;
use App\Modules\Audience\Contracts\ContactSearchIndex;
use App\Modules\Audience\Contracts\VentureOsSync;
use App\Modules\Audience\Services\MeilisearchContactIndex;
use App\Modules\Audience\Testing\FakeContactIndex;
use App\Modules\Audience\Testing\FakeVentureOsSync;
use App\Modules\Support\ModuleServiceProvider;
use Meilisearch\Client as MeiliClient;
use RuntimeException;

/**
 * Audience modul — közönség, listák, címkék, szegmensek, űrlapok (B1).
 */
final class AudienceServiceProvider extends ModuleServiceProvider
{
    /**
     * A modul EGYETLEN web-route-ja a publikus űrlap-beküldés, amit az ügyfél weboldala
     * küld be NÉVTELEN látogatóktól — hitelesítés itt nem értelmezett.
     */
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReindexContactsCommand::class, PurgeImportsCommand::class]);
        }
    }

    public function register(): void
    {
        // A ventOS KÜLSŐ GAZDA, és még nem él. A fake a teszttel fedett kontraktus —
        // élesben viszont HANGOSAN dob, ugyanaz a minta, mint a `ConsentChecker`-nél és a
        // `VentPayClient`-nél: egy fake szinkron élesben azt jelentené, hogy a CRM-integráció
        // „működik", miközben semmi nem mozdul.
        $this->app->bind(VentureOsSync::class, function () {
            if ($this->app->isProduction() && ! config('sending.allow_fake_dependencies')) {
                throw new RuntimeException(
                    'Éles környezetben nincs valódi ventOS-szinkron bekötve (56. kritérium). '
                    .'A fake csendben azt állítaná, hogy a CRM-integráció működik.'
                );
            }

            return new FakeVentureOsSync;
        });

        /*
         | A KONTAKT-KERESŐINDEX (49. kritérium).
         |
         | A Meilisearch itt NEM „még nem élő külső gazda": a dev stack része, és a
         | kontraktus valódi implementációja megvan. A fake a TESZTEKÉ — determinisztikus,
         | és a tenant-szűrőt ő is elvégzi, különben pont azt a hibát takarná el, ami a
         | legsúlyosabb lenne (idegen tenant kontaktja a találatok között).
         |
         | Ha a Meili nincs konfigurálva, a fake áll a helyén ÉLESBEN IS — és ez itt
         | megengedhető, szemben a `ConsentChecker`-rel: egy hiányzó keresőindex
         | kellemetlen (a felület nem talál), de nem nyit ki kaput és nem küld levelet.
         | A hiányt a `audience:reindex` és a felület jelzi, nem egy csendes kapu-átjárás.
         */
        $this->app->bind(ContactSearchIndex::class, function () {
            $host = (string) config('scout.meilisearch.host', env('MEILISEARCH_HOST', ''));

            if ($host === '' || ! class_exists(MeiliClient::class)) {
                return new FakeContactIndex;
            }

            return new MeilisearchContactIndex(
                new MeiliClient($host, (string) config('scout.meilisearch.key', env('MEILISEARCH_KEY', '')))
            );
        });
    }
}
