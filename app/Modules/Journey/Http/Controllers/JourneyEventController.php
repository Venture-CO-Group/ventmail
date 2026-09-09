<?php

declare(strict_types=1);

namespace App\Modules\Journey\Http\Controllers;

use App\Modules\Journey\Actions\FireJourneyEvent;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /v1/journeys/events` — az API-ESEMÉNY trigger (69. kritérium).
 *
 * ## Miért kellett ez a végpont
 *
 * A `TriggerType::ApiEvent` megvolt, a `FireJourneyEvent::apiEvent()` is — de a
 * `Journey/routes/` ÜRES volt, tehát az `api_event` triggerű journey élesben SOHA nem
 * tüzelt. Egy trigger, amit csak a teszt hív, nem trigger: ugyanaz a hiba, amit a B2-ben
 * a `VariantWinner` elkövetett (megvolt, működött, senki nem hívta).
 *
 * ## A PAYLOAD SZŰRT, és ez nem kényelmi döntés
 *
 * A hívó payloadja a `journey_runs.context`-be kerül, ahol a `condition` lépés szűrhet rá.
 * A `context` viszont PII-t tud tárolni: a security-review mérve
 * `{"phone":"+36301234567","shipping_address":"Budapest, Fő utca 1."}`-t talált egy
 * CRM-payloadból, retenció és redakció nélkül. Ezért:
 *
 *  - a kulcsok száma és a méret korlátos (`MAX_KEYS`, `MAX_VALUE_LENGTH`),
 *  - csak skalár érték megy be (egy beágyazott objektum korlátlan mélységű PII-t hordoz),
 *  - és a `journeys:purge-runs` a lezárt futás kontextusát üríti.
 *
 * Aki több adatot akar a döntéshez, szegmenst használ: az a kontakt-rekordból dolgozik,
 * ami a GDPR-törlés útján AMÚGY IS anonimizálódik.
 */
final class JourneyEventController
{
    /** Hány kulcs mehet a kontextusba. */
    private const MAX_KEYS = 20;

    /** Egy érték felső hossza. */
    private const MAX_VALUE_LENGTH = 500;

    public function __invoke(Request $request, FireJourneyEvent $fire): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:320'],
            'event' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'data' => ['sometimes', 'array', 'max:'.self::MAX_KEYS],
            // CSAK SKALÁR: a beágyazott objektum korlátlan mélységű PII-t hordozna a
            // `context`-be, ahol nincs séma.
            'data.*' => ['nullable', 'string', 'max:'.self::MAX_VALUE_LENGTH],
        ]);

        $email = DomainNormalizer::listKey(trim((string) $validated['email']));

        $runs = $fire->apiEvent($email, (string) $validated['event'], (array) ($validated['data'] ?? []));

        /*
         | A VÁLASZ nem mondja meg, LÉTEZIK-e a cím a közönségben.
         |
         | A `started` a MI oldalunkon indult futásokat számolja, ami a hívó saját
         | eseményéről szól. Ha azt válaszolnánk, hogy „ez a cím nincs a listán", a végpont
         | cím-létezés-orákulummá válna: egy hitelesített, de kíváncsi integráció
         | végigpróbálhatná a címeket. A hívó a saját eseményét küldte be — a többi a mi
         | belső ügyünk.
         */
        return new JsonResponse([
            'accepted' => true,
            'started' => count($runs),
        ], 202);
    }
}
