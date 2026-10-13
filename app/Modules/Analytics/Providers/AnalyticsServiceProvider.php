<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Console\PurgeClickMapCommand;
use App\Modules\Analytics\Services\EngagementRecorder;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analytics modul — marketing analitika (72–74. kritérium).
 *
 * UGYANARRA az esemény-forrásra épül, mint a napló: a `message_events`-ből, a kampány és a
 * journey TAGJÉN keresztül. Saját számlálót SZÁNDÉKOSAN nem tart — két számláló
 * elkerülhetetlenül elcsúszik, és akkor a tenant két „delivered" számot lát ugyanarra a
 * kampányra a napló-oldalon és a marketing-riportban.
 *
 * A KLIKK-TÉRKÉP a kivétel, és jó okkal: a kattintott cím szándékosan NEM kerül az
 * eseménybe (a webhook harmadik félhez viszi ki), ezért a térkép saját táblát kap, ami
 * soha nem kerül webhook-payloadba.
 */
final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    /*
     | A `/v1` ROUTE-OK AZ API-HITELESÍTÉS ELŐTT regisztrálódnak.
     |
     | Enélkül a `ModuleServiceProvider::REQUIRED_API` beteszi a böngésző-session `auth` +
     | `workspace` middleware-t az `api.auth` ELÉ, és a végpont API-KULCCSAL IS 401-et ad.
     | Mérve, ugyanabban a kernel-hívásban:
     |
     |     GET /v1/templates                  + templates:read  → 200
     |     GET /v1/analytics/campaigns/{uuid} + analytics:read   → 401 Unauthenticated
     |
     | A `/v1` route-okat exponáló mind a tizenegy másik modul beállítja ezt; ez a kettő
     | kimaradt, és emiatt a B3 `POST /v1/journeys/events` és a B4 kampány-riport
     | egyaránt elérhetetlen volt — miközben az OpenAPI működőként dokumentálta (golden
     | rule 7: a szerződés és a valóság között nincs drift).
     */
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeClickMapCommand::class]);
        }

        /*
         | A TÉNYTÁBLA FELTÖLTÉSE — KÍVÜLRŐL figyelt modell-eseménnyel.
         |
         | Az `Events` modul az A sáv gazdája („az M2 csak OLVAS"), tehát nem tehetünk bele
         | Studio-oldali hookot. A Laravel modell-eseménye viszont innen is figyelhető: az
         | Events kódja érintetlen, a tény mégis egy helyen íródik.
         |
         | A HIBA NEM BUKTATJA EL az esemény rögzítését. Az esemény a napló és a webhook
         | forrása; egy riport-sor nem érhet annyit, hogy egy kézbesítési esemény elvesszen.
         */
        MessageEvent::created(function (MessageEvent $event): void {
            try {
                // SAVEPOINT (v1.1 · 7. modul review RS4): egy hibás upsert különben a hívó
                // tranzakcióját mérgezné (Postgres: „current transaction is aborted"), és a
                // `catch` csak elrejtené, hogy minden további utasítás elbukik.
                DB::transaction(fn () => app(EngagementRecorder::class)->record($event));
            } catch (Throwable $e) {
                Log::warning('A marketing-tény nem került a riportba', [
                    'event_type' => (string) $event->type,
                    'exception' => $e::class,
                ]);
            }
        });
    }
}
