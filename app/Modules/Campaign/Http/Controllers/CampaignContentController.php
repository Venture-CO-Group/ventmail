<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Template\Http\Controllers\TemplateUiController;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\TemplateVersions;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * A KAMPÁNY TARTALMA HELYBEN (UI-polírozás · 6. pont).
 *
 * Az alapértelmezett út az „Új template készítése": a kampány SAJÁT tartalma egy sablon-sor
 * `owner_campaign_id`-vel. Ugyanaz a block-tree → MJML lánc, ugyanaz a szerkesztő, ugyanaz a
 * küldőmotor (a kampány `template_id`-je mutat rá) — csak a könyvtár nem listázza. Önálló
 * sablonként a szerkesztő „Mentés sablonként" menüpontja menti el (másolat, gazda nélkül).
 *
 * A szerkesztés `campaign.create` ÉS `template.create` képesség; új tartalom csak
 * szerkeszthető (vázlat / ütemezett) kampányhoz készül — küldés közben a tartalom nem cserélhető.
 */
final class CampaignContentController
{
    public function store(string $uuid, WorkspaceContext $context, TemplateVersions $versions): RedirectResponse
    {
        Gate::authorize('campaign.create');
        Gate::authorize('template.create');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        if (! $campaign->statusEnum()->isEditable()) {
            return back()->with('error', __('ventmail.campaigns.contentLocked'));
        }

        DB::transaction(function () use ($campaign, $context, $versions): void {
            $campaign = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            // A MEGLÉVŐ saját tartalom újra bekötődik (a „Meglévő" ágról visszaváltva sem vész el).
            $template = Template::query()->where('owner_campaign_id', $campaign->getKey())->latest('id')->first();

            if ($template === null) {
                $content = TemplateUiController::blankContent($context->workspace(), true);

                $template = new Template([
                    // A név belső (a könyvtár nem mutatja), de a parciális egyedi index miatt egyedi.
                    'name' => Str::limit((string) $campaign->name, 100, '').' · '.substr((string) $campaign->uuid, 0, 8),
                    'subject' => (string) $campaign->subject,
                    'preheader' => $campaign->preheader,
                    'marketing' => true,
                    'blocks' => $content['blocks'],
                    'styles' => $content['styles'],
                ]);
                $template->forceFill(['owner_campaign_id' => $campaign->getKey()])->save();

                $versions->snapshot($template, 'create');
                app(AuditLogger::class)->log('campaign.content_created', $campaign, context: ['template' => (string) $template->uuid]);
            }

            if ($campaign->template_id !== $template->getKey()) {
                $campaign->forceFill(['template_id' => $template->getKey()])->save();
            }
        });

        return redirect()->route('campaigns.content', ['uuid' => $campaign->uuid]);
    }

    public function show(string $uuid): Response|RedirectResponse
    {
        Gate::authorize('campaign.create');
        Gate::authorize('template.create');

        $campaign = Campaign::query()->with('template')->where('uuid', $uuid)->firstOrFail();
        $template = $campaign->template;

        // Csak a kampány SAJÁT tartalma nyílik itt; a könyvtári sablon a saját szerkesztőjében.
        if ($template === null || (int) $template->owner_campaign_id !== (int) $campaign->getKey()) {
            return redirect()->route('campaigns.show', ['uuid' => $campaign->uuid]);
        }

        /** @var Response $response */
        $response = app()->call([app(TemplateUiController::class), 'show'], ['uuid' => (string) $template->uuid]);

        return $response->with('campaign', [
            'uuid' => (string) $campaign->uuid,
            'name' => (string) $campaign->name,
        ]);
    }
}
