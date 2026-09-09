<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Jobs\ForwardInboundToEmail;
use App\Modules\Inbound\Jobs\ForwardInboundToUrl;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Services\JourneyTrigger;
use Illuminate\Support\Facades\Log;

/**
 * A ROUTE-MOTOR (30. kritérium).
 *
 * Prioritás szerint rendezett szabálylista; az ELSŐ `stop()` lezárja a láncot. A rendezés
 * másodlagos kulcsa az `id` — enélkül az azonos prioritású szabályok sorrendje a Postgres
 * pillanatnyi kedvétől függne, és ugyanaz a levél ma máshova menne, mint holnap.
 *
 * A motor NEM végzi el a munkát: sorba tesz. Egy lassú ügyfél-végpont vagy egy továbbított
 * levél nem foghatja meg a parse-utat — és a `forward` mindkét fajtája újrapróbálható kell
 * legyen, ami csak külön jobbal értelmes.
 *
 * Ha EGYETLEN szabály sem illeszkedik, a levél `routed` marad akció nélkül: nem hiba, hanem
 * a `catch_all` hiánya. A retenció úgyis elviszi.
 */
final class RouteEngine
{
    public function __construct(private readonly SafeRegex $regex) {}

    public function run(InboundMessage $message): void
    {
        // LIMITTEL: a bejövő forgalom forró útja. Korlát nélkül minden aktív szabály
        // betöltődne LEVELENKÉNT — ötszáz szabálynál ötszáz sor, minden egyes levélre.
        $routes = InboundRoute::query()
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->limit((int) config('inbound.max_routes'))
            ->get();

        $executed = 0;

        foreach ($routes as $route) {
            if (! $this->matches($route, $message)) {
                continue;
            }

            // A találat IDEJE óránként frissül, nem levelenként: a pontos másodperc senkinek
            // nem kell, egy forgalmas route-nál viszont levelenkénti UPDATE lenne belőle.
            if ($route->last_matched_at === null || $route->last_matched_at->lt(now()->subHour())) {
                $route->forceFill(['last_matched_at' => now()])->saveQuietly();
            }

            foreach ((array) $route->actions as $action) {
                $type = (string) ($action['type'] ?? '');

                if ($type === 'stop') {
                    // A LÁNC VÉGE. A `stop()` UTÁNI akciók ugyanabban a szabályban már nem
                    // futnak: aki a `stop()` mögé ír, az szándékosan zárta le a sort.
                    $message->forceFill(['status' => 'routed'])->save();

                    return;
                }

                // A számláló CSAK akkor nő, ha valóban történt valami: enélkül a `reason`
                // `null` lenne egy ismeretlen típusú vagy cél nélküli akciónál is, és az
                // ügyfél a naplóból azt látná, hogy a szabály lefutott — pedig nem.
                $executed += $this->execute($type, (string) ($action['target'] ?? ''), $message, $route) ? 1 : 0;
            }
        }

        $message->forceFill(['status' => 'routed', 'reason' => $executed === 0 ? 'NO_ROUTE_MATCHED' : null])->save();
    }

    private function matches(InboundRoute $route, InboundMessage $message): bool
    {
        return match ($route->match_type) {
            // A `catch_all` MINDIG illeszkedik — ez a lista végére szánt gyűjtőszabály.
            'catch_all' => true,
            'match_recipient' => $this->test($route, (string) $message->recipient),
            'match_header' => $this->test($route, (string) (($message->headers ?? [])[strtolower((string) $route->match_field)] ?? '')),
            default => false,
        };
    }

    private function test(InboundRoute $route, string $subject): bool
    {
        $result = $this->regex->matches((string) $route->expression, $subject);

        if ($result['error'] !== null) {
            // A HIBÁS szabály NEM illeszkedik — és látszik. A csendes „nem illeszkedik"
            // ugyanaz lenne, mint a hiba elrejtése: az ügyfél sosem tudná meg, miért nem
            // működik a szabálya.
            Log::warning('A route-szabály kifejezése nem futtatható.', [
                'route' => $route->uuid,
                'error' => $result['error'],
            ]);

            return false;
        }

        return $result['matched'];
    }

    private function execute(string $type, string $target, InboundMessage $message, InboundRoute $route): bool
    {
        if ($type === 'forward' && $target !== '') {
            // A CÉL alakja dönt: URL vagy e-mail-cím. A kettő teljesen más úton megy — az
            // egyik aláírt HTTP POST, a másik a PreSendGate-en át kiküldött levél.
            str_contains($target, '@') && ! str_starts_with($target, 'http')
                ? ForwardInboundToEmail::dispatch((int) $message->workspace_id, (string) $message->uuid, $target)
                : ForwardInboundToUrl::dispatch((int) $message->workspace_id, (string) $message->uuid, $target, (string) $route->uuid);

            return true;
        }

        if ($type === 'store' && $target !== '') {
            // A `store()` maga NEM csinál semmit: a levél már el van tárolva. Ha van
            // értesítési URL, azt hívjuk.
            ForwardInboundToUrl::dispatch((int) $message->workspace_id, (string) $message->uuid, $target, (string) $route->uuid);

            return true;
        }

        /*
         | A `journey()` AKCIÓ (B3 · 69. kritérium): a beérkező levél automatizálást indít.
         |
         | A FELADÓ a levél `from` címe — és ez HAMISÍTHATÓ: bárki írhat levelet a világon
         | `anna@pelda.hu` feladóval. Ezért a `JourneyTrigger` a 71. kritérium szerint
         | MEGERŐSÍTETT consentet követel az `inbound_email` triggerű journey-knél; enélkül
         | egy idegen bármikor levelezést indíthatna bárki nevében, a mi domainünkről.
         |
         | A beléptetés ITT csak a triggert TÜZELI — a döntést (entitlement, suppression,
         | consent, újrabelépés) a `JourneyTrigger` hozza meg, egy helyen.
         */
        if ($type === 'journey') {
            /*
             | A DMARC-VERDIKT NEM DOBHATÓ EL.
             |
             | A `ParseInboundMessage` kitölti az `spf_result`/`dkim_result`/`dmarc_result`
             | oszlopokat, és ez az akció korábban egyszerűen nem nézett rájuk. A 71.
             | kritérium megerősített-consent kapuja áll — de egy BIZONYÍTOTTAN hamisított
             | (`dmarc_result = 'fail'`) levél így is elindított marketing ágat egy valódi
             | feliratkozó nevében, akinek a levéllel semmi köze nem volt. Ha a fogadó
             | oldal már megmondta, hogy a feladó hamis, nincs okunk megbízni benne.
             */
            if (strtolower((string) $message->dmarc_result) === 'fail') {
                Log::info('Journey-trigger elutasítva: a levél DMARC-ellenőrzése elhasalt.', [
                    'route' => (string) $route->uuid,
                    'inbound_message' => (string) $message->uuid,
                ]);

                return true;
            }

            app(JourneyTrigger::class)->fire(
                TriggerType::InboundEmail,
                (string) $message->sender,
                [
                    'route' => (string) $route->uuid,
                    'journey' => $target === '' ? null : $target,
                    'inbound_message' => (string) $message->uuid,
                    /*
                     | A TÁRGY SZÁNDÉKOSAN NEM KERÜL A KONTEXTUSBA.
                     |
                     | A `journey_runs.context` retenció nélkül él, a bejövő levél maga
                     | viszont 3 napos (`inbound:purge`). Mérve egy
                     | „A HITELKARTYAM SZAMA 4111 1111 1111 1111" tárgy szó szerint
                     | landolt a `context`-ben, és ott maradt volna örökre — egy törlési
                     | kérés után is. Aki a tárgyra akar szűrni, az `inbound_message`
                     | uuid-ján éri el, amíg a retenció engedi.
                     */
                ],
            );

            return true;
        }

        // A puszta `store()` LEGITIM akció (a levél tárolva marad), tehát „lefutottnak"
        // számít — csak nem indít semmit.
        return $type === 'store';
    }
}
