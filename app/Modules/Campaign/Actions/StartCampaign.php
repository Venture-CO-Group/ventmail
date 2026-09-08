<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Actions;

use App\Modules\Campaign\Jobs\SendCampaignChunk;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Campaign\Services\AudienceResolver;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Campaign\Services\VariantAssigner;
use App\Modules\Sending\Data\SendQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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
    /** Egy köteg mérete. Elég nagy, hogy a job-overhead ne domináljon, elég kicsi, hogy a progressz mozogjon. */
    private const CHUNK = 250;

    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly CampaignLifecycle $lifecycle,
        private readonly VariantAssigner $variants,
    ) {}

    public function __invoke(Campaign $campaign): Campaign
    {
        // AZ ÁLLAPOTGÉP ELŐBB: a `start()` ellenőrzi a sablont, a tárgyat, a feladót, a
        // célzást és a variáns-súlyokat. Ha bármi hiányzik, semmi nem materializálódik.
        $campaign = $this->lifecycle->start($campaign);

        $recipients = $this->audience->resolve($campaign);

        DB::transaction(function () use ($campaign, $recipients): void {
            $variants = $this->variants->plan($campaign, count($recipients));

            foreach (array_chunk($recipients, 500) as $offset => $chunk) {
                $rows = [];

                foreach ($chunk as $index => $recipient) {
                    $position = $offset * 500 + $index;

                    $row = new CampaignRecipient([
                        'campaign_id' => $campaign->getKey(),
                        'contact_id' => $recipient['contact_id'],
                        'campaign_variant_id' => $variants[$position] ?? null,
                        'email' => $recipient['email'],
                    ]);

                    // A `firstOrCreate` helyett kötegelt beszúrás: százezer soron a
                    // soronkénti lekérdezés perceket vinne el. A mutátor viszont KELL (a
                    // cím normalizálása és a hash), ezért modellként építjük, és az
                    // attribútumokat adjuk át.
                    $rows[] = array_merge($row->getAttributes(), [
                        'workspace_id' => $campaign->workspace_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // A DEDUPLIKÁCIÓ az egyedi kulcsra épül (`campaign_id` + `email`): az
                // `insertOrIgnore` egy újrapróbált indítást is elnyel, duplikálás nélkül.
                CampaignRecipient::query()->insertOrIgnore($rows);
            }

            $campaign->forceFill([
                'recipient_count' => $campaign->recipients()->count(),
                'estimated_recipients' => count($recipients),
                'estimated_at' => now(),
            ])->save();
        });

        return $this->dispatchBatch($campaign->fresh());
    }

    /**
     * A KÖTEGEK ELINDÍTÁSA — Horizon batch-ben, a `campaign` soron.
     */
    private function dispatchBatch(Campaign $campaign): Campaign
    {
        $jobs = [];

        $campaign->recipients()
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$jobs, $campaign): void {
                $jobs[] = new SendCampaignChunk(
                    (string) $campaign->uuid,
                    (int) $campaign->workspace_id,
                    $chunk->pluck('id')->all(),
                );
            });

        if ($jobs === []) {
            // NINCS kinek küldeni: a kampány BEFEJEZETT, nem „küldés folyamatban". A
            // felületen egy örökre `sending` kampány rosszabb, mint egy üres riport.
            return $this->lifecycle->complete($campaign);
        }

        $batch = Bus::batch($jobs)
            ->name('campaign:'.$campaign->uuid)
            // A KAMPÁNY-SOR: legalacsonyabb prioritás (golden rule 4).
            ->onQueue(SendQueue::Campaign->value)
            // EGY KÖTEG HIBÁJA NEM ÁLLÍTJA LE A TÖBBIT: egy fogadó időleges elutasítása
            // nem ok arra, hogy a maradék kilencvenkilenc köteg ne menjen ki.
            ->allowFailures()
            ->dispatch();

        $campaign->forceFill(['batch_id' => $batch->id])->save();

        return $campaign->fresh();
    }
}
