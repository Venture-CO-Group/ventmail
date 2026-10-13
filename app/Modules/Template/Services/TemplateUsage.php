<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignVariant;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Template\Models\Template;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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

    /**
     * ÍRHATÓ-E a sablon a jelenlegi felhasználónak? (review B1) — `null`, ha igen; különben a
     * felhasználónak szóló indoklás.
     *
     * Az AKTÍV kampányhoz (ütemezett / küldés alatti / szüneteltetett, változattal együtt) vagy
     * aktív journey-hez kötött sablon tartalma a JÓVÁHAGYOTT, kiküldés előtt álló levél: a küldés
     * az élő `template->html`-t használja (nincs pillanatkép). Írása ezért `template.publish`
     * képességet kér — a `template.create` (szerző) ezt nem kerülheti meg. A kézi mentés, az
     * autosave és a verzió-visszaállítás egyaránt ezt kérdezi.
     *
     * Miért TILTÁS, és nem „vissza vázlatba" (D116 mintája): a D116 a kampány SAJÁT
     * szerkesztőjében, kifejezett mentésre dolgozik, és a `scheduled → draft` érvényes átmenet.
     * Itt egy sablon TÖBB kampányban és journey-ben is élhet, a `sending` / `paused` kampányt és
     * az aktív journey-t nem lehet vázlatba venni, és egy másik képernyőn futó AUTOSAVE csendben
     * vonna vissza (akár több) publikáló által ütemezett kampányt. Az egységes, kiszámítható
     * szabály: publikálási jog nélkül az aktív sablon nem írható; a szerző másolatot készíthet.
     */
    public function writeDenial(Template $template): ?string
    {
        if (Gate::allows('template.publish')) {
            return null;
        }

        $blockers = $this->blockers($template);

        if ($blockers['campaigns'] === [] && $blockers['journeys'] === []) {
            return null;
        }

        return __('studio.templates.publishLocked', [
            'uses' => implode(', ', [...$blockers['campaigns'], ...$blockers['journeys']]),
        ]);
    }

    /**
     * A KÜLDÉSRE ÉLESÍTETT sablon-sorok MEGOSZTOTT zárja (`FOR SHARE`, review SEC4-S1) — a kampány
     * indítása / ütemezése és a journey publikálása hívja a SAJÁT tranzakciójában. A szerkesztő
     * mentése (`FOR UPDATE`) addig vár; utána a `writeDenial()` már az aktív használatot látja.
     *
     * @param  list<int>  $ids  sablon-id-k
     */
    public function lockForActivation(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id): bool => $id !== null)));

        if ($ids !== []) {
            Template::query()->whereKey($ids)->orderBy('id')->sharedLock()->get(['id']);
        }
    }

    /** @param list<string> $uuids sablon-uuid-k (a journey lépései uuid-dal hivatkoznak) */
    public function lockForActivationByUuid(array $uuids): void
    {
        // CSAK érvényes uuid (review m5-r5 S1): a `uuid` oszlop típusos, egy `"x"` hivatkozás
        // `22P02`-vel 500-at adna a validátor `reference_missing` hibája helyett.
        $uuids = array_values(array_unique(array_filter(
            array_map(static fn ($uuid): string => strtolower(trim((string) $uuid)), $uuids),
            static fn (string $uuid): bool => Str::isUuid($uuid),
        )));

        if ($uuids !== []) {
            Template::query()->whereIn('uuid', $uuids)->orderBy('id')->sharedLock()->get(['id']);
        }
    }

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
