<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Console;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Jobs\StartCampaignJob;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignChecklist;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AZ ESEDÉKES KAMPÁNYOK INDÍTÁSA (65. kritérium).
 *
 * Percenként fut. Az esedékesség az UTC-időpont szerint dől el, a tenant időzónája a
 * MEGJELENÍTÉSHEZ kell — a `scheduled_at` már UTC-re konvertált érték, tehát a nyári
 * időszámítás váltása nem tolja el a kampányt.
 *
 * ## A HIBA EGY KAMPÁNYNÁL nem állítja meg a többit
 *
 * Egy hiányos kampány (törölt sablon, elfogyott kvóta) kivételt dob az indításnál. Ha ez
 * megszakítaná a ciklust, EGYETLEN rossz kampány miatt az összes utána következő ütemezett
 * kampány elmaradna — és a felhasználók csak órákkal később vennék észre.
 *
 * A kampány ilyenkor `scheduled` marad, tehát a következő futás újrapróbálja: egy időleges
 * ok (kvóta, DNS-kimaradás) nem veszíti el a kampányt, egy tartós ok viszont minden percben
 * látszik a naplóban.
 */
final class DispatchScheduledCampaignsCommand extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';

    protected $description = 'Az esedékes, ütemezett kampányok indítása.';

    public function handle(WorkspaceContext $context, CampaignLifecycle $lifecycle, CampaignChecklist $checklist, AuditLogger $audit): int
    {
        $started = 0;
        $failed = 0;

        /*
         | A HÍVÓ KONTEXTUSÁT VISSZAÁLLÍTJUK, nem eldobjuk.
         |
         | A `forget()` egy tiszta CLI-futásnál helyes — de ezt a parancsot egy szolgáltatás
         | is meghívhatja (`Artisan::call`), és akkor a hívó tenant-kontextusa elveszik: az
         | utána következő lekérdezések a `WorkspaceScope` fail-closed ágára futnak (`1 = 0`),
         | és a hívó ÜRES eredményt lát valódi adat helyett.
         |
         | Ez mérve megtörtént a `ContactGdpr`-ban (a `logs:redact-subject` hívása után a
         | művelet HAMISAN jelentette, hogy nincs mit megőrizni), és a saját tesztem is
         | ebbe futott bele. A `finally` így a KORÁBBI kontextust adja vissza.
         */
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $lifecycle, $checklist, $audit, &$started, &$failed): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $due = Campaign::query()
                        ->where('status', 'scheduled')
                        ->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '<=', now())
                        ->orderBy('scheduled_at')
                        ->get();

                    foreach ($due as $campaign) {
                        try {
                            $outcome = DB::transaction(function () use ($campaign, $checklist, $lifecycle, $audit): string {
                                // SOR-ZÁR + FRISS esedékesség (review BN2/NIT): a kiválasztás óta egy
                                // szerkesztés átütemezhette vagy vázlatba vehette. A szerkesztés
                                // ugyanezt a sort zárolja, tehát a kettő sorban fut, nem egymásba.
                                $locked = Campaign::query()
                                    ->whereKey($campaign->getKey())
                                    ->where('status', 'scheduled')
                                    ->where('scheduled_at', '<=', now())
                                    ->lockForUpdate()
                                    ->first();

                                if ($locked === null) {
                                    return 'skipped';
                                }

                                // A SZERVER-ELLENŐRZÉSEK az indítás pillanatában is (review RS1): az
                                // ütemezés óta a domain hitelesítése lejárhatott, a sablon postai
                                // címe helyőrzőre változhatott, a közönség elveszíthette a
                                // megerősített hozzájárulást. A hiányos kampány VÁZLAT lesz, nem
                                // indul el — és ez auditált.
                                $failing = $checklist->failingChecks($locked);

                                if ($failing !== []) {
                                    $lifecycle->unschedule($locked);
                                    $audit->log('campaign.unscheduled_by_checklist', $locked, context: ['failing' => $failing]);

                                    return 'failed';
                                }

                                // LEFOGLALÁS + háttér-materializálás (review RB1): az ütemező sem
                                // tart egy 300k-s felírást a percenkénti futásában. A job a commit
                                // UTÁN kerül a sorba (`afterCommit`).
                                $claimed = $lifecycle->start($locked);
                                StartCampaignJob::dispatch((string) $claimed->uuid, (int) $claimed->workspace_id);

                                return 'started';
                            });

                            if ($outcome === 'started') {
                                $started++;
                            } elseif ($outcome === 'failed') {
                                $failed++;
                            }
                        } catch (Throwable $e) {
                            report($e);
                            $failed++;
                        }
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Indítva: {$started}, hibás: {$failed}");

        return self::SUCCESS;
    }
}
