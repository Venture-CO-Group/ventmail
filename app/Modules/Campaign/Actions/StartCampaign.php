<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Campaign\Services\AudienceResolver;
use App\Modules\Campaign\Services\CampaignBatchDispatcher;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\VariantAssigner;
use App\Modules\Tenancy\Services\WorkspaceContext;
use RuntimeException;

/**
 * A KAMPÁNY INDÍTÁSA (63–66. kritérium).
 *
 * ## A HALMAZ MATERIALIZÁLÓDIK, MIELŐTT BÁRMI KIMEGY
 *
 * Előbb feloldjuk és TÁBLÁBA írjuk a címzetteket, csak utána indul a küldés. A fordított
 * sorrend (menet közbeni lekérdezés) három hibát hozna: a közben módosult közönség miatt egy
 * újrapróbálás más halmazt látna (valaki kétszer kapja, valaki egyszer sem), a felületen
 * mutatott becsült szám eltérne a valóságtól, és az A/B variáns-hozzárendelés nem lenne
 * megismételhető.
 *
 * ## A KÖTEGELÉS a Horizon batch-e (65. kritérium)
 *
 * Egy százezres kampány nem egy job: kötegekre bomlik, és a Horizon batch adja a
 * progressz-számot, amit a Reverb továbbít a felületre. A batch másik haszna a
 * MEGSZAKÍTHATÓSÁG: a szüneteltetés a batch törlése, nem száz job kézi leállítása.
 *
 * ## A SOR: `campaign` — a LEGALACSONYABB prioritás (66. kritérium)
 *
 * Golden rule 4: a kampány SOHA nem előzheti meg a tranzakciós levelet. Egy százezres
 * kampány mellett a jelszó-visszaállító különben órákat várna — ez a leggyakoribb
 * ESP-panasz, nálunk pedig szabály, amit a sor NEVE kényszerít ki.
 */
final class StartCampaign
{
    /** Egy MATERIALIZÁLÓ lépés mérete — ennyi sor van egyszerre a memóriában. */
    private const MATERIALIZE_CHUNK = 1000;

    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly CampaignLifecycle $lifecycle,
        private readonly VariantAssigner $variants,
        private readonly WorkspaceContext $context,
        private readonly CampaignBatchDispatcher $batches,
    ) {}

    public function __invoke(Campaign $campaign): Campaign
    {
        // AZ ÁLLAPOTGÉP ELŐBB: a `start()` ellenőrzi a sablont, a tárgyat, a feladót, a
        // célzást és a variáns-súlyokat, és a `sending` állapotot FELTÉTELES UPDATE-tel
        // IGÉNYLI. Ha bármi hiányzik — vagy ha valaki más már elindította —, semmi nem
        // materializálódik.
        $campaign = $this->lifecycle->start($campaign);

        return $this->materialize($campaign);
    }

    /**
     * A CÍMZETTEK MATERIALIZÁLÁSA és a kötegek indítása — a már LEFOGLALT (`sending`) kampányon.
     *
     * v1.1 (review RB1/S4): a felület és az ütemező a kérésben csak lefoglal, ez a lépés a
     * `StartCampaignJob`-ban fut a `campaign` soron — egy 300k-s célzás nem egy webkérés
     * idejében materializálódik. Ismételhető: a beszúrás `insertOrIgnore`, a kötegek a
     * `pending` sorokra indulnak.
     */
    public function materialize(Campaign $campaign): Campaign
    {
        // A TENANT-HATÁR: a kötegelt beszúrás megkerüli az Eloquent `creating` hookját,
        // tehát a `BelongsToWorkspace::assertOwnWorkspace` sem fut le rajta. Ezért a
        // szabályt ITT mondjuk ki újra. Nem elméleti: az `insertOrIgnore` bármilyen
        // `workspace_id`-t beírna, amit ide beadnak.
        $workspaceId = (int) $campaign->workspace_id;

        if ($workspaceId !== $this->context->workspaceId()) {
            throw new RuntimeException(sprintf(
                'Idegen workspace_id (%d) az aktuális kontextusban (%d): tenant-határ sértése.',
                $workspaceId,
                $this->context->workspaceId()
            ));
        }

        // A TELJES CÍMZETTSZÁM a variáns-felosztáshoz kell, MIELŐTT az első köteg beíródik:
        // a pozíció-alapú hozzárendelés a halmaz méretéből számol.
        $total = $this->audience->estimate($campaign);
        $plan = $this->variants->plan($campaign, $total);

        $inserted = 0;

        // KÖTEGENKÉNT, NEM EGYSZERRE. A teljes közönség memóriába gyűjtése 100 000
        // kontaktnál +193 MB-ot vitt, és a konténer 128M-jével `Allowed memory size …
        // exhausted`-del elhalt — a memória-fatal pedig nem `Throwable`, tehát az ütemező
        // `catch`-e sem fogta meg (lásd az `AudienceResolver` docblockját).
        //
        // MINDEN köteg előtt FRISS állapot-ellenőrzés (review BN1): egy percekig tartó felírás
        // alatt a felhasználó szüneteltethet vagy visszavonhat. Ilyenkor a felírás megáll — a
        // visszavont kampányhoz nem jön új `pending` sor, és nem indul köteg.
        $this->audience->each($campaign, self::MATERIALIZE_CHUNK, function (array $chunk, int $offset) use ($campaign, $workspaceId, $plan, &$inserted): bool {
            if (! $this->stillSending($campaign)) {
                return false;
            }

            $rows = [];

            foreach ($chunk as $index => $recipient) {
                $row = new CampaignRecipient([
                    'campaign_id' => $campaign->getKey(),
                    'contact_id' => $recipient['contact_id'],
                    'campaign_variant_id' => $plan[$offset + $index] ?? null,
                    'email' => $recipient['email'],
                ]);

                // A `firstOrCreate` helyett kötegelt beszúrás: százezer soron a
                // soronkénti lekérdezés perceket vinne el. A mutátor viszont KELL (a
                // cím normalizálása és a hash), ezért modellként építjük, és az
                // attribútumokat adjuk át.
                $rows[] = array_merge($row->getAttributes(), [
                    'workspace_id' => $workspaceId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // A DEDUPLIKÁCIÓ az egyedi kulcsra épül (`campaign_id` + `email`): az
            // `insertOrIgnore` egy újrapróbált indítást is elnyel, duplikálás nélkül.
            $inserted += CampaignRecipient::query()->insertOrIgnore($rows);

            return true;
        });

        // A TELJES felírás rögzítése — FELTÉTELES UPDATE, csak `sending` állapotban (BN1/SN2). Ha
        // közben szüneteltették, a `materialized_at` NULL marad: a folytatás újra materializál
        // (nem a csonka közönségre küld); ha visszavonták, a köztes `pending` sorok `skipped`-ek.
        $claimed = Campaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', CampaignStatus::Sending->value)
            ->update([
                'recipient_count' => $campaign->recipients()->count(),
                'estimated_recipients' => $total,
                'estimated_at' => now(),
                'materialized_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return $this->abandon($campaign);
        }

        // AUDIT (review S3): a feltételes UPDATE megkerüli az Auditable hookot. A háttérben futó
        // felírás aktora a `system`; a szám a TÉNYLEGESEN felírt címzetteké.
        $fresh = $campaign->fresh();
        app(AuditLogger::class)->log('campaign.materialized', $fresh, context: [
            'actor' => 'system',
            'recipients' => (int) $fresh->recipient_count,
        ]);

        return $this->dispatchBatch($fresh);
    }

    /** A kampány MOST (az adatbázisban) is `sending`? — a modell-példány állapota elavult lehet. */
    private function stillSending(Campaign $campaign): bool
    {
        return Campaign::query()->whereKey($campaign->getKey())->value('status') === CampaignStatus::Sending->value;
    }

    /**
     * A FÉLBEHAGYOTT felírás lezárása: köteg nem indul. A visszavont kampány közben beírt
     * `pending` sorai `skipped` állapotba kerülnek (a `cancel()` a saját pillanatában ugyanezt tette).
     */
    private function abandon(Campaign $campaign): Campaign
    {
        $fresh = $campaign->fresh();

        if ($fresh->statusEnum() === CampaignStatus::Canceled) {
            $fresh->recipients()->where('status', 'pending')->update([
                'status' => 'skipped',
                'skip_reason' => 'campaign_canceled',
                'updated_at' => now(),
            ]);
        }

        return $fresh;
    }

    /**
     * A KÖTEGEK ELINDÍTÁSA — a közös indítón, mert a `resume()` is ezt használja.
     */
    private function dispatchBatch(Campaign $campaign): Campaign
    {
        if ($this->batches->dispatch($campaign) === null) {
            // NINCS kinek küldeni: a kampány BEFEJEZETT, nem „küldés folyamatban". A
            // felületen egy örökre `sending` kampány rosszabb, mint egy üres riport.
            return $this->lifecycle->complete($campaign);
        }

        return $campaign->fresh();
    }
}
