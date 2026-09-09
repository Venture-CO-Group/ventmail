<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Jobs\SendCampaignChunk;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Sending\Data\SendQueue;
use Illuminate\Support\Facades\Bus;

/**
 * A KÖTEGEK ELINDÍTÁSA — a `pending` sorokra, a `campaign` soron.
 *
 * ## Miért külön osztály, és nem a `StartCampaign` privát metódusa
 *
 * Mert KÉT hívója van: az indítás és a FOLYTATÁS. A `resume()` korábban semmit nem indított
 * — átírta az állapotot `sending`-re, a `batch_id` változatlan maradt, és egyetlen job sem
 * került a sorba. Mérve: pause → 3 `pending`; resume → `sending`, 3 `pending`, NULLA job. A
 * szüneteltetés alatt lefutott kötegek addig a `SendCampaignChunk` korai `return`-jével
 * elfogytak, tehát azok a címzettek soha nem kaptak volna levelet: a kampány örökre „küldés
 * folyamatban" maradt, nulla küldéssel.
 *
 * A `CampaignLifecycle` nem hívhatná közvetlenül a `StartCampaign`-t (az action EZT a
 * szolgáltatást használja — körkörös konténer-függés, ami futásidőben, a hívási ponton
 * hasal el). Egy közös, függésmentes indító ezt megszünteti.
 *
 * ## A SOR: `campaign` — a LEGALACSONYABB prioritás (66. kritérium)
 *
 * Golden rule 4: a kampány SOHA nem előzheti meg a tranzakciós levelet. Egy százezres
 * kampány mellett a jelszó-visszaállító különben órákat várna.
 */
final class CampaignBatchDispatcher
{
    /** Egy köteg mérete. Elég nagy, hogy a job-overhead ne domináljon, elég kicsi, hogy a progressz mozogjon. */
    public const CHUNK = 250;

    /**
     * @return string|null a batch azonosítója, vagy `null`, ha nincs kinek küldeni
     */
    public function dispatch(Campaign $campaign): ?string
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
            return null;
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

        return $batch->id;
    }
}
