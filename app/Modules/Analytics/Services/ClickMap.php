<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\LinkClick;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Journey\Models\Journey;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * KLIKK-TÉRKÉP (74. kritérium): melyik linkre kattintottak, és hányan.
 *
 * ## Miért NEM a `message_events`-ből jön
 *
 * Mert a kattintott cím SZÁNDÉKOSAN nincs az eseményben. A `TrackingController` ezt maga
 * mondja ki: „a kattintott cím nem kerül az eseménybe: a webhook harmadik félhez viszi ki,
 * és egy link gyakran tokent vagy azonosítót hordoz". A `MessageEvent::created` hookja a
 * payloadot a kimenő webhookba teszi, tehát bármi, ami oda bekerül, ELHAGYJA a rendszert.
 *
 * Ez a döntés helyes, és nem írjuk vissza. A klikk-térkép ezért SAJÁT táblát kap, ami soha
 * nem kerül webhook-payloadba.
 *
 * ## Miért `recipient_hash` és nem cím
 *
 * A „hány EGYEDI ember kattintott" kérdéshez a címzett-dimenzió kell — az azonosításához
 * nem. A kulcsolt hash pszeudonim: az egyediség számolható vele, a cím nem áll vissza
 * belőle. A térkép aggregált nézet, nem címzett-lista; ha nyers címet tárolna, egy
 * „riport" letöltése a teljes címlistát adná.
 *
 * ## A TENANT-SZŰRŐ a MODELLEN van
 *
 * A `LinkClick` tenant-kötött Eloquent-modell, tehát a global scope adja a `workspace_id`
 * szűrőt — nincs kézi `where`, amit el lehetne felejteni. A `TagStatistics` és a
 * `ListAnalytics` kézi szűrője kényszer (nézetre, illetve pivot táblára kérdeznek); itt van
 * saját tábla, tehát nincs okunk a szabályt a fejlesztő emlékezetére bízni.
 *
 * ## A FELSŐ KORLÁT, és miért látszik
 *
 * Ha a tenant merge-taget tesz a `href`-be, a behelyettesített cím CÍMZETTENKÉNT MÁS. Egy
 * 100 000 címzettes kampány így 100 000 külön „linket" adna: a térkép használhatatlan, a
 * tábla pedig hízik. A korlát fölötti kattintás egyetlen `(egyéb)` sorba gyűlik, és ez a
 * sor LÁTSZIK a riportban — a csonkítás ténye információ, nem szégyen.
 */
final class ClickMap
{
    /**
     * KAMPÁNYONKÉNT ennyi DISTINCT link kerül külön sorba.
     *
     * A korlát korábban ÜZENETENKÉNT állt, és ezért a saját docblockjában leírt fenyegetés
     * ellen nem védett: a kampány CÍMZETTENKÉNT külön `messages` sort küld
     * (`SendCampaignChunk`: `'to' => [$recipient->email]`), tehát minden személyre szabott
     * link a maga üzenetének külön budzséjében volt. Mérve 300 címzettel: 300 soros
     * klikk-térkép, gyűjtő-sor NÉLKÜL — és a `forCampaign()`-en `LIMIT` sincs, tehát a
     * JSON-válasz és a CSV mérete korlátlan volt.
     */
    public const MAX_LINKS_PER_SCOPE = 200;

    /** Egy összevonási körben ennyi (üzenet, link) csoport dolgozódik fel. */
    private const COLLAPSE_CHUNK = 500;

    /** A korlát fölötti linkek gyűjtő-sora. */
    public const OVERFLOW_URL = '(egyéb link)';

    /**
     * SZEMÉLYRE SZABHATÓ query-paraméterek — ezek kiesnek a térkép kulcsából.
     *
     * A lista NEVESÍTETT: a `utm_*`, a `ref` és a `gclid` MEGMARAD, mert azok a
     * kampány-mérés dimenziói. Lásd a `normalize()` indoklását.
     *
     * @var list<string>
     */
    private const PERSONAL_PARAMS = [
        'email', 'e', 'mail', 'address', 'recipient', 'to', 'user', 'u', 'uid', 'user_id',
        'token', 'auth', 'key', 'sig', 'signature', 'hash', 'id', 'contact', 'contact_id',
        'nev', 'name', 'firstname', 'lastname', 'first_name', 'last_name', 'phone', 'tel',
    ];

    /**
     * AZ ÖSSZEVONT sorok címzett-jelölője.
     *
     * A retenció és a GDPR-törlés a címzett-dimenziót vonja össze: a `clicks` és a
     * `unique_clicks` megmarad, a KI viszont eltűnik. A konstans nem hash — szándékosan
     * felismerhető, hogy egy riportot olvasva látszódjon: ez a sor már aggregált.
     */
    public const AGGREGATED_HASH = '(aggregalt)';

    public function __construct(private readonly WorkspaceContext $context) {}

    /**
     * EGY KATTINTÁS rögzítése.
     *
     * IDEMPOTENS: az egyedi index `(message_uuid, url_hash, recipient_hash)` miatt egy
     * újratöltött link nem duplikál sort, csak a számlálót növeli. A `TrackingController`
     * amúgy is percenként egy eseményt engedélyez ugyanarra az igényre — de a térkép nem
     * támaszkodhat egy másik komponens rate-limitjére.
     */
    public function record(
        string $messageUuid,
        ?string $campaignUuid,
        ?int $journeyId,
        string $url,
        string $recipient,
    ): void {
        $workspaceId = $this->workspaceId();
        $url = $this->normalize($url);

        // A KORLÁT a HATÓKÖRÖN (kampány/journey), nem az üzeneten — lásd a
        // `MAX_LINKS_PER_SCOPE` indoklását.
        $scope = $campaignUuid !== null ? 'campaign' : 'journey';
        $scopeValue = $campaignUuid ?? $journeyId;

        if ($scopeValue !== null
            && ! $this->known($scope, $scopeValue, $url)
            && $this->linkCount($scope, $scopeValue) >= self::MAX_LINKS_PER_SCOPE) {
            $url = self::OVERFLOW_URL;
        }

        $urlHash = KeyedHash::of('click-url', $url);
        $recipientHash = KeyedHash::of('email', $recipient);

        // `upsert`: egy körutazás, és a versenyt az egyedi index dönti el — nem egy
        // „megnézem, van-e" ellenőrzés, ami két kérés között befér.
        //
        // A `workspace_id` KÉZZEL kerül a sorba: az `upsert` INSERT, és a global scope a
        // lekérdezésekre hat, nem a beszúrásokra — ugyanaz a rés, amit a B2-ben az
        // `insertOrIgnore` nyitott. A kontextust a `workspaceId()` fail-closed módon adja.
        LinkClick::query()->upsert(
            [[
                'workspace_id' => $workspaceId,
                'message_uuid' => $messageUuid,
                'campaign_uuid' => $campaignUuid,
                'journey_id' => $journeyId,
                'url_hash' => $urlHash,
                'url' => $url,
                'recipient_hash' => $recipientHash,
                'clicks' => 1,
                'unique_clicks' => 1,
                'first_clicked_at' => now(),
                'last_clicked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            // AZ ÜTKÖZÉSI CÉL tartalmazza a `workspace_id`-t: az `upsert` INSERT, tehát a
            // global scope nem hat rá, és nélküle két tenant ugyanarra a sorra ütközne.
            ['workspace_id', 'message_uuid', 'url_hash', 'recipient_hash'],
            // ÜTKÖZÉSNÉL a számláló nő, az ELSŐ kattintás ideje MARAD: az „első kattintás"
            // egy valódi mérőszám (mennyi idő alatt reagált a címzett).
            [
                'clicks' => DB::raw('link_clicks.clicks + 1'),
                'last_clicked_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * A TÉRKÉP egy kampányra — RANGSOR, nem lista.
     *
     * A legtöbbet kattintott link az első: a klikk-térkép kérdése az, hogy MI működött, és
     * arra a sorrend maga a válasz.
     *
     * @return list<array{url: string, clicks: int, unique_clicks: int, first_clicked_at: ?string, last_clicked_at: ?string}>
     */
    public function forCampaign(Campaign $campaign): array
    {
        return $this->aggregate(fn ($query) => $query->where('campaign_uuid', (string) $campaign->uuid));
    }

    /**
     * @return list<array{url: string, clicks: int, unique_clicks: int, first_clicked_at: ?string, last_clicked_at: ?string}>
     */
    public function forJourney(Journey $journey): array
    {
        return $this->aggregate(fn ($query) => $query->where('journey_id', $journey->getKey()));
    }

    /**
     * @param  callable(Builder): mixed  $scope
     * @return list<array{url: string, clicks: int, unique_clicks: int, first_clicked_at: ?string, last_clicked_at: ?string}>
     */
    private function aggregate(callable $scope): array
    {
        // A `workspace_id` szűrőt a MODELL global scope-ja adja; a `workspaceId()` hívása
        // itt a fail-closed ellenőrzés, hogy kontextus nélkül ne is induljon lekérdezés.
        $this->workspaceId();

        $query = LinkClick::query();

        $scope($query);

        return $query
            // A `sum(unique_clicks)`, nem `count(*)`: élő sornál az oszlop 1, tehát a kettő
            // megegyezik — ÖSSZEVONT sornál viszont a `count(*)` egyetlen kattintót
            // mutatna egy ezres kampányra. Lásd a migráció indoklását.
            ->selectRaw('url, sum(clicks) as clicks, sum(unique_clicks) as unique_clicks')
            ->selectRaw('min(first_clicked_at) as first_clicked_at, max(last_clicked_at) as last_clicked_at')
            ->groupBy('url')
            // A RANGSOR: teljes kattintás szerint, egyenlőségnél a link címe (determinizmus).
            ->orderByDesc('clicks')
            ->orderBy('url')
            ->get()
            ->map(static fn (object $row): array => [
                'url' => (string) $row->url,
                'clicks' => (int) $row->clicks,
                // AZ EGYEDI KATTINTÓ a SOROK száma: soronként egy (link, címzett) páros áll.
                'unique_clicks' => (int) $row->unique_clicks,
                'first_clicked_at' => $row->first_clicked_at === null ? null : (string) $row->first_clicked_at,
                'last_clicked_at' => $row->last_clicked_at === null ? null : (string) $row->last_clicked_at,
            ])
            ->all();
    }

    /**
     * A CÍMZETT-DIMENZIÓ ÖSSZEVONÁSA — a retenció és a GDPR-törlés útja.
     *
     * A `clicks` és az egyedi kattintók száma MEGMARAD (a riport nem változik
     * visszamenőleg), a KI viszont eltűnik. Ez a golden rule 10 pontos alakja: a nyers
     * grain törlődik, az aggregátum marad.
     *
     * A `$scope` dönti el, MIT vonunk össze: egy címzett sorait (érintetti törlés) vagy egy
     * lejárt időszakot (retenció).
     *
     * @param  callable(\Illuminate\Database\Eloquent\Builder<LinkClick>): mixed  $scope
     * @return int az összevont sorok száma
     */
    public function collapse(callable $scope): int
    {
        $this->workspaceId();

        $collapsedTotal = 0;

        // KÖTEGENKÉNT, amíg van mit: a `collapse()` a saját szűrőjét minden körben újra
        // futtatja, és mivel a feldolgozott sorok ELTŰNNEK (aggregált sorra cserélődnek,
        // amit a hívó scope-ja kizár), a ciklus véges.
        do {
            $collapsed = $this->collapseChunk($scope);
            $collapsedTotal += $collapsed;
        } while ($collapsed > 0);

        return $collapsedTotal;
    }

    /**
     * EGY KÖTEG összevonása.
     *
     * @param  callable(\Illuminate\Database\Eloquent\Builder<LinkClick>): mixed  $scope
     */
    private function collapseChunk(callable $scope): int
    {
        $query = LinkClick::query();
        $scope($query);

        /*
         | A CSOPORTOK: üzenet + link. Ezen a grainen áll a klikk-térkép.
         |
         | A `min(campaign_uuid::text)` a NULL-t kihagyja — és pontosan ezért kell a
         | csoportba a `campaign_uuid` is: nélküle egy `campaign_uuid IS NULL` sor
         | (journey-klikk vagy hozzárendelés nélküli kattintás) BEHÚZÓDOTT a kampány
         | csoportjába, és mérve a riport klikkszáma 3-ról 5-re nőtt az összevonás után.
         | A csoportosítás így a hozzárendelést is megőrzi.
         */
        $groups = (clone $query)
            ->selectRaw('message_uuid, url_hash, min(url) as url, campaign_uuid, journey_id')
            ->selectRaw('sum(clicks) as clicks, sum(unique_clicks) as unique_clicks')
            ->selectRaw('min(first_clicked_at) as first_clicked_at, max(last_clicked_at) as last_clicked_at')
            ->groupBy('message_uuid', 'url_hash', 'campaign_uuid', 'journey_id')
            // A LAPOZÁS kötelező: a `get()` korlát nélkül materializálta a csoportokat, és
            // egy százezres, személyre szabott linkes kampány százezer csoportot ad —
            // mérve 1000 csoport 0,63 s és 2001 lekérdezés, ami lineárisan
            // ~12,5 s / 40 000 lekérdezés egyetlen tenantra, egy éjszakai futásban.
            ->limit(self::COLLAPSE_CHUNK)
            ->get();

        $collapsed = 0;

        foreach ($groups as $group) {
            DB::transaction(function () use ($query, $group, &$collapsed): void {
                // A CSOPORT SORAI eltűnnek…
                $deleted = (clone $query)
                    ->where('message_uuid', $group->message_uuid)
                    ->where('url_hash', $group->url_hash)
                    ->delete();

                if ($deleted === 0) {
                    return;
                }

                // …és egyetlen aggregált sor lép a helyükre. Az `upsert` azért kell, mert
                // egy korábbi összevonás sora már ott lehet: akkor a számok ÖSSZEADÓDNAK.
                LinkClick::query()->upsert(
                    [[
                        'workspace_id' => $this->workspaceId(),
                        'message_uuid' => (string) $group->message_uuid,
                        'campaign_uuid' => $group->campaign_uuid,
                        'journey_id' => $group->journey_id === null ? null : (int) $group->journey_id,
                        'url_hash' => (string) $group->url_hash,
                        'url' => (string) $group->url,
                        'recipient_hash' => self::AGGREGATED_HASH,
                        'clicks' => (int) $group->clicks,
                        'unique_clicks' => (int) $group->unique_clicks,
                        'first_clicked_at' => $group->first_clicked_at,
                        'last_clicked_at' => $group->last_clicked_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    // AZ ÜTKÖZÉSI CÉL tartalmazza a `workspace_id`-t: az `upsert` INSERT, tehát a
                    // global scope nem hat rá, és nélküle két tenant ugyanarra a sorra ütközne.
                    ['workspace_id', 'message_uuid', 'url_hash', 'recipient_hash'],
                    [
                        'clicks' => DB::raw('link_clicks.clicks + excluded.clicks'),
                        'unique_clicks' => DB::raw('link_clicks.unique_clicks + excluded.unique_clicks'),
                        // AZ ELSŐ KATTINTÁS a KORÁBBI marad: ismételt összevonásnál a
                        // bent lévő, későbbi érték felülírta volna, és az „első
                        // kattintásig eltelt idő" mérőszám visszamenőleg romlott volna.
                        'first_clicked_at' => DB::raw('least(link_clicks.first_clicked_at, excluded.first_clicked_at)'),
                        'last_clicked_at' => DB::raw('greatest(link_clicks.last_clicked_at, excluded.last_clicked_at)'),
                        'updated_at' => now(),
                    ]
                );

                $collapsed += $deleted;
            });
        }

        return $collapsed;
    }

    /**
     * A LINK NORMALIZÁLÁSA — a térkép kulcsa ÉS a személyes adat kiszűrése.
     *
     * ## A SZEMÉLYRE SZABOTT QUERY-PARAMÉTER KIESIK
     *
     * A review ezt mérte meg, és ez volt a packet legsúlyosabb találata: a tenant
     * merge-taget tehet a `href`-be, a behelyettesített cím pedig
     * `https://pelda.hu/r?email=titkos@pelda.hu&nev=Anna+Kiss` — vagyis NYERS SZEMÉLYES
     * ADAT, ami nyersen a táblába került, amit sem az Art. 17 törlés, sem a retenció nem
     * vitt el (mindkettő csak a `recipient_hash`-t vonja össze), és amit a CSV-export meg
     * az API-válasz kiadott. Az OpenAPI ugyanerről a végpontról azt állítja: „a válasz
     * aggregált: egyetlen címzett-címet sem tartalmaz."
     *
     * Ez EGYBEN a másik mért hibát is javítja: a személyre szabott linkek címzettenként más
     * `url_hash`-t adtak, tehát egy 300 címzettes kampány 300 soros klikk-térképet
     * eredményezett — a linkenkénti korlát pedig nem fogott, mert az `message_uuid` grainen
     * áll, és a kampány címzettenként külön üzenetet küld.
     *
     * A KIVÁGOTT paraméterek listája SZŰK és nevesített: a `utm_*` és a többi kampány-mérő
     * paraméter MEGMARAD, mert épp azok a klikk-térkép értelmes dimenziói.
     *
     * ## A HOSZT KISBETŰS, a záró `/` csak az ÚTVONALON válik le
     *
     * A `rtrim($url, '/')` korábban a TELJES URL-re futott. Mérve: a
     * `?next=https://a.hu/` és a `?next=https://a.hu` EGY sorba került (és a tárolt cím
     * olyan lett, ami sosem volt a levélben), a `https://PELDA.hu/akcio` és a
     * `https://pelda.hu/akcio` viszont KETTŐBE — vagyis a rangsor hasadt, pontosan az,
     * amit a docblock „a térkép hazudik a rangsorról"-ként ír le.
     */
    private function normalize(string $url): string
    {
        $url = trim($url);

        if ($url === self::OVERFLOW_URL) {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            // NEM ÉRTELMEZHETŐ cím: a `TrackingController::safeDestination()` már
            // kiszűrte a nem-http(s) alakot, tehát ide csak elgépelés jut. A gyűjtő-sor a
            // biztonságos válasz — a térképbe nem tesszük be feldolgozhatatlan kulccsal.
            return self::OVERFLOW_URL;
        }

        // A HOSZT kisbetűs (a DNS is az), az útvonalon a záró `/` leválik.
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $normalized = strtolower($parts['scheme'] ?? 'https').'://'.$host;

        if (isset($parts['port'])) {
            $normalized .= ':'.$parts['port'];
        }

        $normalized .= $path;

        $query = $this->safeQuery($parts['query'] ?? '');

        if ($query !== '') {
            $normalized .= '?'.$query;
        }

        // A FRAGMENT eldobva: a szerverre amúgy sem érkezik meg, tehát két „különböző"
        // link egyazon oldalra ugyanaz a kattintás.
        return mb_substr($normalized, 0, 2000);
    }

    /**
     * A QUERY SZŰRÉSE — a személyre szabható paraméterek kiesnek.
     *
     * A tiltólista NEVESÍTETT, nem heurisztikus: egy „minden hosszú értéket kivágunk"
     * szabály a legitim kampány-azonosítókat is elvinné, és a térkép használhatatlan lenne.
     * Ami kiesik, az a személyes adat és a címzettenkénti azonosító — ami marad, a
     * kampány-mérés (`utm_*`, `ref`, `gclid`).
     */
    private function safeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);

        $kept = [];

        foreach ($params as $key => $value) {
            $lower = strtolower((string) $key);

            if (in_array($lower, self::PERSONAL_PARAMS, true)) {
                continue;
            }

            // A `@` jel az értékben: gyakorlatilag mindig e-mail-cím. Egy nevesített
            // listával nem lehet minden tenant paraméter-elnevezését lefedni (`e`, `u`,
            // `kontakt`), ezért a TARTALOM is szűr.
            if (is_string($value) && str_contains($value, '@')) {
                continue;
            }

            // A NEM SKALÁR érték (tömb-alakú paraméter) kiesik: a kulcsnak stabilnak és
            // rövidnek kell lennie, és a beágyazott alak korlátlan adatot hordoz.
            if (! is_scalar($value)) {
                continue;
            }

            $kept[$lower] = (string) $value;
        }

        // A RENDEZÉS a determinizmus miatt: ugyanaz a link ugyanazt a kulcsot adja,
        // akkor is, ha a paraméterek sorrendje eltér.
        ksort($kept);

        return http_build_query($kept);
    }

    private function known(string $scope, string|int $value, string $url): bool
    {
        return LinkClick::query()
            ->where($scope === 'campaign' ? 'campaign_uuid' : 'journey_id', $value)
            ->where('url_hash', KeyedHash::of('click-url', $url))
            ->exists();
    }

    private function linkCount(string $scope, string|int $value): int
    {
        return (int) LinkClick::query()
            ->where($scope === 'campaign' ? 'campaign_uuid' : 'journey_id', $value)
            ->distinct()
            ->count('url_hash');
    }

    /**
     * A KONTEXTUS FAIL-CLOSED ellenőrzése.
     *
     * A szűrést a `LinkClick` global scope-ja végzi; ez a metódus azt biztosítja, hogy
     * kontextus NÉLKÜL egyáltalán ne induljon lekérdezés, és hogy a beszúrásba (ahol a
     * scope nem hat) a helyes `workspace_id` kerüljön.
     */
    private function workspaceId(): int
    {
        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException('A klikk-térkép workspace-kontextus nélkül nem kérdezhető le.');
        }

        return $this->context->workspaceId();
    }
}
