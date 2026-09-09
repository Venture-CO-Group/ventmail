<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Console;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * A LEZÁRT KAMPÁNYOK címzett-listájának redakciója (golden rule 10).
 *
 * ## Miért redakció és nem törlés
 *
 * A sor a kampány-riport alapja: „1000 címzett, 987 elküldve, 9 leiratkozott miatt
 * kihagyva". Ha egy retenciós takarítás sorokat TÖRÖLNE, a fél évvel korábbi kampány
 * riportja visszamenőleg megváltozna — és a tenant azt látná, hogy a számai maguktól
 * romlanak. Ezért a NYERS CÍM tűnik el, a sor és az `email_hash` marad: a riport
 * változatlan, az azonosítás viszont megszűnik.
 *
 * ## Miért kell egyáltalán
 *
 * A `campaign_recipients` a B2-vel jött, és semmilyen lejárat nem érte. Mérve: a
 * `contacts.email` egy `erase()` után `torolt-…@erased.invalid`, a `campaign_recipients`
 * ugyanazon a címen viszont NYERS maradt. A golden rule 10 („lejárat után a nyers esemény
 * törlődik, az aggregátum marad") pontosan ezt a tábla-osztályt írja le, és a naplózó
 * táblákra be is van tartva — ez a tábla kimaradt belőle.
 *
 * TENANTONKÉNT, kontextussal (golden rule 6), és a KORÁBBI kontextust visszaadva: egy
 * szolgáltatásból hívva a `forget()` a hívó lekérdezéseit a `WorkspaceScope` fail-closed
 * ágára vinné, és ÜRES eredményt adna valódi adat helyett.
 */
final class PurgeCampaignRecipientsCommand extends Command
{
    protected $signature = 'campaigns:purge-recipients';

    protected $description = 'A lejárt retenciójú, LEZÁRT kampányok címzett-listájában a nyers cím redakciója.';

    public function handle(WorkspaceContext $context): int
    {
        $days = (int) config('sending.delivery_attempt_retention_days', 90);
        $cutoff = now()->subDays($days);
        $redacted = 0;

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->withTrashed()->orderBy('id')->chunkById(100, function ($workspaces) use ($context, $cutoff, &$redacted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    // CSAK LEZÁRT kampány: egy futó vagy szüneteltetett kampányból a cím
                    // kivétele azt jelentené, hogy a `SendCampaignChunk` nem tudja, kinek
                    // küldjön — a `pending` sorok címe MUNKA-adat, nem archívum.
                    $redacted += CampaignRecipient::query()
                        ->where('email', '!=', self::PLACEHOLDER)
                        // A RÉSZLEKÉRDEZÉS a `Campaign` MODELLEN megy, nem nyers
                        // query-builderen: így a `workspace_id`-szűrőt a global scope adja,
                        // és nem azon múlik, hogy itt kézzel odaírtam-e.
                        ->whereIn('campaign_id', Campaign::query()
                            ->whereIn('status', ['sent', 'canceled'])
                            ->whereNotNull('finished_at')
                            ->where('finished_at', '<=', $cutoff)
                            ->select('id')
                            ->getQuery())
                        ->update(['email' => self::PLACEHOLDER, 'updated_at' => now()]);
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info(sprintf('Redaktált kampány-címzett: %d (retenció: %d nap)', $redacted, $days));

        return self::SUCCESS;
    }

    /** A redaktált cím helyőrzője — ugyanaz, amit a `logs:redact-subject` ír. */
    private const PLACEHOLDER = '(redaktált)';
}
