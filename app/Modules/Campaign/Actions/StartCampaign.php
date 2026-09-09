<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Actions;

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
        $this->audience->each($campaign, self::MATERIALIZE_CHUNK, function (array $chunk, int $offset) use ($campaign, $workspaceId, $plan, &$inserted): void {
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
        });

        $campaign->forceFill([
            'recipient_count' => $campaign->recipients()->count(),
            'estimated_recipients' => $total,
            'estimated_at' => now(),
        ])->save();

        return $this->dispatchBatch($campaign->fresh());
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
