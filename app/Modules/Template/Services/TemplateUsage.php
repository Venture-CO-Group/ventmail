<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Template\Models\Template;

/**
 * HOL HASZNÁLJÁK a sablont úgy, hogy a törlése egy FUTÓ küldést törne el (B2)?
 *
 * - kampány ütemezve / küldés alatt / szüneteltetve (a folytatás `no_template`-re futna);
 * - ilyen kampány változata (A/B);
 * - aktív vagy szüneteltetett journey `send_email` lépése.
 *
 * A piszkozat kampány nem blokkol (még nem küld, a szerkesztője választhat másikat), a
 * `sent` / `canceled` végállapot sem (a levél már kiment, a soft delete a tartalmát nem
 * érinti — és egy egyszer használt sablon különben soha nem lenne törölhető).
 */
final class TemplateUsage
{
    private const ACTIVE_CAMPAIGN = ['scheduled', 'sending', 'paused'];

    private const ACTIVE_JOURNEY = ['active', 'paused'];

    /** @return array{campaigns: list<string>, journeys: list<string>} a blokkoló hivatkozások nevei */
    public function blockers(Template $template): array
    {
        $activeCampaigns = Campaign::query()->whereIn('status', self::ACTIVE_CAMPAIGN);

        $campaigns = (clone $activeCampaigns)
            ->where(function ($query) use ($template): void {
                $query->where('template_id', $template->getKey())
                    ->orWhereIn('id', CampaignVariant::query()->where('template_id', $template->getKey())->select('campaign_id'));
            })
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($name) => (string) $name)
            ->all();

        $journeys = Journey::query()
            ->whereIn('status', self::ACTIVE_JOURNEY)
            ->whereIn('id', JourneyStep::query()
                ->where('type', 'send_email')
                ->where('config->template', (string) $template->uuid)
                ->select('journey_id'))
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($name) => (string) $name)
            ->all();

        return ['campaigns' => array_values($campaigns), 'journeys' => array_values($journeys)];
    }
}
