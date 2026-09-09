<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A TÉNYTÁBLA FELTÖLTÉSE — esemény-figyelőből, nem a küldési útból.
 *
 * ## Miért figyelő, és miért nem a `MessageEvent`-be írt kód
 *
 * Mert az `Events` modul az A SÁV GAZDÁJA, és a `PHASE.md` kimondja: „ahol közös
 * (`Events`), ott az M1 a gazda, az M2 csak OLVAS". Egy `message_events`-be tett oszlop
 * vagy hook a Studio kedvéért megsértené ezt. A Laravel modell-eseménye viszont
 * KÍVÜLRŐL figyelhető: a `MessageEvent::created` a `AnalyticsServiceProvider`-ben
 * regisztrálódik, és az Events modul kódja érintetlen marad.
 *
 * ## Miért EGY hely
 *
 * Nyolc esemény-típus, négy emitter (`SendMessage`, `MessageDispatcher`,
 * `TrackingController`, webhook-ingest). Ha a tényírás a hívási helyeken lenne, valahol
 * elmaradna — és a riport CSENDBEN kevesebbet mutatna. Ez a projekt ezt a hibaosztályt
 * már többször megtalálta; ezért van egyetlen choke point.
 */
final class EngagementRecorder
{
    /** Melyik esemény-típus melyik oszlopot növeli. */
    private const COLUMNS = [
        // A KAPU ELUTASÍTÁSA is tény: enélkül a riportból nem derül ki, hogy a lista egy
        // része ki sem ment (lásd a migráció `rejected` indoklását).
        'rejected' => 'rejected',
        'accepted' => 'accepted',
        'delivered' => 'delivered',
        'deferred' => 'deferred',
        'bounced' => 'bounced',
        'complained' => 'complained',
        'unsubscribed' => 'unsubscribed',
        'opened' => 'opens',
        'clicked' => 'clicks',
    ];

    public function record(MessageEvent $event): void
    {
        $column = self::COLUMNS[(string) $event->type] ?? null;
        $recipient = (string) $event->recipient;

        // A REDAKTÁLT esemény (`recipient = null`) nem hordoz új tényt: a hozzá tartozó
        // sorokat a törlés már összevonta. Egy üres címre írt sor viszont HAMIS egyedi
        // címzettet adna a riportba.
        if ($column === null || $recipient === '') {
            return;
        }

        $scopes = $this->scopes((array) $event->tags);

        if ($scopes === []) {
            return;
        }

        $variant = $this->variant((array) $event->tags);

        // A CÍM NORMALIZÁLÁSA a hash ELŐTT — és ez nem apróság.
        //
        // A review megmérte, hogy a klikk-térkép a NYERS címet hashelte, míg a
        // GDPR-parancs a `listKey()` alakot keresi: `Anna@Pelda.hu` esetén a törlés NULLA
        // sort talált, és a parancs mégis sikert jelentett. A kódbázisban minden más
        // e-mail-hash normalizál; ez a réteg sem lehet kivétel.
        $hash = KeyedHash::of('email', DomainNormalizer::listKey(trim($recipient)));

        $at = $event->occurred_at ?? now();

        foreach ($scopes as $scope) {
            CampaignEngagement::query()->upsert(
                [[
                    'workspace_id' => (int) $event->workspace_id,
                    'scope' => $scope,
                    'variant_label' => $variant,
                    'recipient_hash' => $hash,
                    $column => 1,
                    'first_opened_at' => $column === 'opens' ? $at : null,
                    'first_clicked_at' => $column === 'clicks' ? $at : null,
                    'last_event_at' => $at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['workspace_id', 'scope', 'variant_label', 'recipient_hash'],
                [
                    // A SZÁMLÁLÓ nő, az ELSŐ reakció ideje a KORÁBBI marad: a „mennyi idő
                    // alatt nyitotta meg" valódi mérőszám, és egy második nyitás nem
                    // írhatja felül.
                    $column => DB::raw('campaign_engagement.'.$column.' + 1'),
                    'first_opened_at' => DB::raw('least(campaign_engagement.first_opened_at, excluded.first_opened_at)'),
                    'first_clicked_at' => DB::raw('least(campaign_engagement.first_clicked_at, excluded.first_clicked_at)'),
                    'last_event_at' => DB::raw('greatest(campaign_engagement.last_event_at, excluded.last_event_at)'),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * A HATÓKÖRÖK a tagekből — validálva.
     *
     * A tag a HÍVÓ szava (`o:tag` a Messages API-n), tehát bármi lehet benne. A review
     * megmérte, hogy egy nem-uuid `campaign:` tag a klikk-térképnél `SQLSTATE[22P02]`-t
     * adott, amit egy `catch` lenyelt — a kattintás csendben kimaradt. Itt a `scope`
     * sztring oszlop, tehát nem hasal el; a VALIDÁCIÓ mégis kell, különben egy elgépelt
     * tag saját, örökre üres „kampány-riportot" hoz létre a táblában.
     *
     * @param  list<mixed>  $tags
     * @return list<string>
     */
    private function scopes(array $tags): array
    {
        $scopes = [];

        foreach ($tags as $tag) {
            $tag = (string) $tag;

            if (str_starts_with($tag, 'campaign:')) {
                $uuid = substr($tag, strlen('campaign:'));

                if (Str::isUuid($uuid)) {
                    $scopes[] = 'campaign:'.$uuid;
                }
            }

            if (str_starts_with($tag, 'journey:')) {
                $id = substr($tag, strlen('journey:'));

                // `ctype_digit`, nem `(int)`: a `(int) 'abc'` NULLA, ami létező
                // journey-hivatkozásnak látszó, de nem létező kötést adna.
                if ($id !== '' && ctype_digit($id)) {
                    $scopes[] = 'journey:'.$id;
                }
            }
        }

        return array_values(array_unique($scopes));
    }

    /** @param list<mixed> $tags */
    private function variant(array $tags): string
    {
        foreach ($tags as $tag) {
            $tag = (string) $tag;

            if (str_starts_with($tag, 'variant:')) {
                return mb_substr(substr($tag, strlen('variant:')), 0, 100);
            }
        }

        // ÜRES sztring, nem `NULL`: a `NULL`-ok az egyedi indexben KÜLÖNBÖZŐEK, tehát a
        // `NULL` variánssal az `upsert` idempotenciája megszűnne.
        return '';
    }
}
