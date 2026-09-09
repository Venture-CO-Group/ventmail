<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Logs\Services\TagStatistics;
use App\Modules\Tenancy\Services\WorkspaceContext;
use RuntimeException;

/**
 * A SZÁMLÁLÓK — a TÉNYTÁBLÁBÓL, nem a nyers eseményekből (72., 73. kritérium).
 *
 * ## Miért NEM a `message_events`-ből, pedig az „az egy forrás"
 *
 * Mert azon 90 napos RETENCIÓ van, és a review ezt megmérte egy 100 napos kampányon,
 * `drop_chunks` után:
 *
 *     Studio-riport:   delivered 0,  opened 0,  open_rate 0%
 *     Platform-napló:  delivered 50, opened 20, open_rate 40%
 *
 * Vagyis a „egy forrás" elv a nyers táblával ELLENTÉTES eredményt adott: a napló a
 * folytonos aggregátumból még látta a számokat, a Studio már nem. A 73. kritérium tehát
 * nem attól teljesül, hogy ugyanazt a TÁBLÁT olvassuk, hanem attól, hogy ugyanazokból az
 * ESEMÉNYEKBŐL származunk — és mindkét származék túléli a retenciót.
 *
 * A ténytáblát ugyanaz az esemény-folyam táplálja (`EngagementRecorder`, a
 * `MessageEvent::created`-en), tehát a forrás továbbra is EGY. A napi idősor a MEGLÉVŐ
 * Timescale folytonos aggregátumból jön (`message_event_daily`) — abból, amit a napló is
 * olvas.
 *
 * ## Miért CÍMZETTET számol, nem eseményt
 *
 * A `delivered`, `accepted`, `bounced` egy címzettre elvileg egyszer jön — a valóságban
 * nem. A review megmérte, hogy az éles driver-úton KÉTSZER keletkezik `accepted`
 * (`SendMessage` a kapun, `MessageDispatcher` a driver válaszán, mert a `PostalDriver`
 * `accepted`-et ad, nem `delivered`-et), és emiatt a `bounce_rate` nevezője megduplázódott:
 * 1 küldés / 1 bounce **50%-ot** mutatott 100% helyett. A bounce-arány a kézbesíthetőségi
 * megfelelés száma (spam-ráta < 0,3%) — nem lehet felére kozmetikázva.
 *
 * Ezért az állapot-számok CÍMZETTET számolnak (`count(*) where oszlop > 0`). A nyitás és a
 * klikk viszont KÉT számot ad: a `total` az esemény-szám (mennyi megnyitás), az `unique` a
 * címzett-szám (hányan) — a kettő külön kérdés.
 */
final class EventCounters
{
    /** @var list<string> */
    public const TYPES = [
        'rejected', 'accepted', 'delivered', 'deferred', 'bounced',
        'complained', 'unsubscribed', 'opened', 'clicked',
    ];

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly TagStatistics $tags,
    ) {}

    /**
     * A SZÁMOK egy hatókörre (`campaign:<uuid>` / `journey:<id>`), opcionális variánssal.
     *
     * @return array<string, int>
     */
    public function forScope(string $scope, ?string $variant = null): array
    {
        $this->assertContext();

        $row = CampaignEngagement::query()
            ->where('scope', $scope)
            ->when($variant !== null, fn ($query) => $query->where('variant_label', $variant))
            // A CÍMZETT-SZÁMOK: `count(*) filter (…)`, nem `sum(…)`. Lásd az osztály
            // docblockját a kétszer írt `accepted`-ről.
            ->selectRaw('count(*) filter (where rejected > 0) as rejected')
            ->selectRaw('count(*) filter (where accepted > 0) as accepted')
            ->selectRaw('count(*) filter (where delivered > 0) as delivered')
            ->selectRaw('count(*) filter (where deferred > 0) as deferred')
            ->selectRaw('count(*) filter (where bounced > 0) as bounced')
            ->selectRaw('count(*) filter (where complained > 0) as complained')
            ->selectRaw('count(*) filter (where unsubscribed > 0) as unsubscribed')
            // A NYITÁS ÉS A KLIKK KÉT számot ad: mennyi történt, és hányan tették.
            ->selectRaw('coalesce(sum(opens), 0) as opened')
            ->selectRaw('count(*) filter (where opens > 0) as unique_opened')
            ->selectRaw('coalesce(sum(clicks), 0) as clicked')
            ->selectRaw('count(*) filter (where clicks > 0) as unique_clicked')
            /*
             | AZ ELÉRT KÖZÖNSÉG: aki nyitott VAGY kattintott.
             |
             | Ez a CTOR helyes nevezője. A `unique_clicked / unique_opened` mérve 266%-ot
             | adott egy hétköznapi bemeneten (10 kézbesítve / 3 nyitva / 8 kattintva):
             | kép-blokkoló kliensben (Outlook desktop, céges gateway, plain-text) a pixel
             | NEM tölt be, a link viszont kattintható — tehát több kattintó van, mint
             | nyitó. A kattintás BIZONYÍTJA a megnyitást, ezért a nevező a két halmaz
             | UNIÓJA, és a ráta nem tud 100% fölé menni.
             */
            ->selectRaw('count(*) filter (where opens > 0 or clicks > 0) as engaged')
            ->first();

        $counts = [];

        foreach (self::TYPES as $type) {
            $counts[$type] = (int) ($row->{$type} ?? 0);
        }

        $counts['unique_opened'] = (int) ($row->unique_opened ?? 0);
        $counts['unique_clicked'] = (int) ($row->unique_clicked ?? 0);
        $counts['engaged'] = (int) ($row->engaged ?? 0);

        return $counts;
    }

    /**
     * A RÁTÁK a számokból — a nevezők a `Rates` docblockjában vannak kimondva.
     *
     * @param  array<string, int>  $counts
     * @return array<string, float>
     */
    public function rates(array $counts): array
    {
        $delivered = $counts['delivered'] ?? 0;
        $accepted = $counts['accepted'] ?? 0;

        return [
            'open_rate' => Rates::percent($counts['unique_opened'] ?? 0, $delivered),
            'click_rate' => Rates::percent($counts['unique_clicked'] ?? 0, $delivered),
            // A NEVEZŐ az ELÉRT közönség (nyitott VAGY kattintott) — lásd a `forScope()`
            // `engaged` oszlopának indoklását.
            'click_to_open_rate' => Rates::percent($counts['unique_clicked'] ?? 0, $counts['engaged'] ?? 0),
            'bounce_rate' => Rates::percent($counts['bounced'] ?? 0, $accepted),
            'complaint_rate' => Rates::percent($counts['complained'] ?? 0, $delivered),
            'unsubscribe_rate' => Rates::percent($counts['unsubscribed'] ?? 0, $delivered),
            /*
             | AZ ELUTASÍTÁSI ARÁNY nevezője a MEGKÍSÉRELT küldés: elutasított + elfogadott.
             |
             | Ez a listahigiénia mérőszáma („a célzott közönség hány százaléka nem is
             | kapható meg"), és a nevezője NEM a `recipients` — az a célzás mérete, ami a
             | materializálás után is változhat (a kampány folytatható, szüneteltethető).
             */
            'rejection_rate' => Rates::percent($counts['rejected'] ?? 0, ($counts['rejected'] ?? 0) + $accepted),
        ];
    }

    /**
     * A NAPI IDŐSOR — a NAPLÓ szolgáltatásán keresztül (72., 73. kritérium).
     *
     * ## Miért a `TagStatistics`-en, és nem közvetlenül a nézeten
     *
     * Mert a `message_event_daily` folytonos aggregátum gazdája a `Logs\TagStatistics`
     * („egy képesség, egy gazda"). A nézeten nincs Eloquent-modell, tehát global scope sem,
     * és a workspace-szűrő EGYETLEN helyre van bezárva. Amikor ez a szolgáltatás maga
     * kérdezte a nézetet, az arch-őr (`ScopeBypassGuardTest`) helyesen tüzelt: az egy
     * MÁSODIK hely lett volna, ahol egy elfelejtett `where` cross-tenant számot adna.
     *
     * Ez EGYBEN a 73. kritérium szerkezeti garanciája: a Studio idősora szó szerint
     * ugyanazt a lekérdezést futtatja, amit a platform-napló — nem tud elcsúszni tőle.
     *
     * @return list<array{day: string, delivered: int, opened: int, clicked: int}>
     */
    public function dailyForTag(string $tag): array
    {
        $this->assertContext();

        return $this->tags->dailyForTag($tag);
    }

    /**
     * FAIL-CLOSED: kontextus nélkül nem indul lekérdezés.
     *
     * A ténytáblát a `CampaignEngagement` global scope-ja szűri; ez az őr azt biztosítja,
     * hogy a nézetre menő kézi szűrő se maradhasson el, és hogy a „nincs kontextus" ne
     * „minden tenant"-ot jelentsen.
     */
    private function assertContext(): void
    {
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException('A marketing-riport workspace-kontextus nélkül nem kérdezhető le.');
        }
    }
}
