<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Http\Controllers;

use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\AudienceResolver;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Template\Models\Template;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * KAMPÁNYOK (62–67. kritérium): lista, szerkesztés, célzás, ütemezés, indítás.
 *
 * ## AZ ÁLLAPOTGÉP a modellben van, nem itt
 *
 * A `CampaignLifecycle` dönti el, mi mire válthat (`draft → scheduled → sending → sent`),
 * és a modell `saving` hookja tiltja a nem szerkeszthető állapotban lévő kampány
 * módosítását. A kontroller ezért CSAK hív: egy külön felületi állapot-logika előbb-utóbb
 * megengedne valamit, amit a motor nem.
 *
 * ## A BECSÜLT KÖZÖNSÉG a célzás mellett látszik
 *
 * Mert a „kinek megy ki?" kérdésre az indítás előtt kell válaszolni. A becslés a VALÓDI
 * feloldón (`AudienceResolver`) megy át — ugyanazon, amelyik a küldéskor is dolgozik.
 */
final class CampaignUiController
{
    public function index(): Response
    {
        Gate::authorize('campaign.create');

        return Inertia::render('Campaign/Index', [
            'campaigns' => Campaign::query()
                ->with('template')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Campaign $campaign) => [
                    'uuid' => (string) $campaign->uuid,
                    'name' => (string) $campaign->name,
                    'subject' => (string) $campaign->subject,
                    'status' => (string) $campaign->status,
                    'template' => $campaign->template?->name,
                    'scheduledAt' => $campaign->scheduled_at?->toIso8601String(),
                    'timezone' => $campaign->timezone,
                    // A SZERKESZTHETŐSÉG az állapotgépből jön: a felület nem találgat.
                    'editable' => $campaign->statusEnum()->isEditable(),
                    'variants' => $campaign->variants()->count(),
                ])->values()->all(),
            'templates' => Template::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Template $template) => ['uuid' => (string) $template->uuid, 'name' => (string) $template->name])
                ->values()->all(),
        ]);
    }

    public function show(string $uuid, AudienceResolver $resolver): Response
    {
        Gate::authorize('campaign.create');

        $campaign = Campaign::query()->with(['template', 'variants'])->where('uuid', $uuid)->firstOrFail();

        return Inertia::render('Campaign/Edit', [
            'campaign' => [
                'uuid' => (string) $campaign->uuid,
                'name' => (string) $campaign->name,
                'subject' => (string) $campaign->subject,
                'preheader' => $campaign->preheader,
                'fromEmail' => $campaign->from_email,
                'fromName' => $campaign->from_name,
                'status' => (string) $campaign->status,
                'editable' => $campaign->statusEnum()->isEditable(),
                'templateUuid' => $campaign->template?->uuid,
                'targetLists' => array_values((array) $campaign->target_lists),
                'targetSegments' => array_values((array) $campaign->target_segments),
                'excludeLists' => array_values((array) $campaign->exclude_lists),
                'excludeSegments' => array_values((array) $campaign->exclude_segments),
                'scheduledAt' => $campaign->scheduled_at?->toIso8601String(),
                'timezone' => $campaign->timezone,
                'variants' => $campaign->variants->map(fn ($variant) => [
                    'uuid' => (string) $variant->uuid,
                    'label' => (string) $variant->label,
                    'subject' => (string) $variant->subject,
                    'share' => (int) $variant->share,
                    'winner' => (bool) $variant->winner,
                ])->values()->all(),
            ],
            'templates' => Template::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Template $template) => ['uuid' => (string) $template->uuid, 'name' => (string) $template->name])
                ->values()->all(),
            'lists' => ContactList::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (ContactList $list) => ['uuid' => (string) $list->uuid, 'name' => (string) $list->name])
                ->values()->all(),
            'segments' => Segment::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Segment $segment) => ['uuid' => (string) $segment->uuid, 'name' => (string) $segment->name])
                ->values()->all(),
            // A „KINEK MEGY KI?" kérdésre az indítás ELŐTT kell válaszolni.
            'estimate' => $resolver->estimate($campaign),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('campaign.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255'],
        ]);

        $campaign = Campaign::create([
            'name' => $validated['name'],
            'subject' => $validated['subject'],
        ]);

        return redirect()->route('campaigns.show', ['uuid' => $campaign->uuid]);
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        Gate::authorize('campaign.create');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'fromEmail' => ['nullable', 'string', 'email', 'max:320'],
            'fromName' => ['nullable', 'string', 'max:120'],
            'templateUuid' => ['nullable', 'string'],
            'targetLists' => ['array'],
            'targetSegments' => ['array'],
            'excludeLists' => ['array'],
            'excludeSegments' => ['array'],
        ]);

        $templateId = null;

        if (! empty($validated['templateUuid'])) {
            // A SABLON a global scope-on át oldódik fel: idegen workspace sablonjára nem
            // lehet kampányt kötni.
            $templateId = Template::query()->where('uuid', $validated['templateUuid'])->firstOrFail()->getKey();
        }

        try {
            $campaign->update([
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'preheader' => $validated['preheader'] ?? null,
                'from_email' => $validated['fromEmail'] ?? null,
                'from_name' => $validated['fromName'] ?? null,
                'template_id' => $templateId,
                'target_lists' => array_values($validated['targetLists'] ?? []),
                'target_segments' => array_values($validated['targetSegments'] ?? []),
                'exclude_lists' => array_values($validated['excludeLists'] ?? []),
                'exclude_segments' => array_values($validated['excludeSegments'] ?? []),
            ]);
        } catch (RuntimeException $e) {
            // A NEM SZERKESZTHETŐ állapotot a MODELL tiltja (küldés közben a célzás
            // átírása fél kampányt küldene máshova). A felület a szabály OKÁT mondja el.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.campaigns.saved'));
    }

    public function transition(Request $request, string $uuid, CampaignLifecycle $lifecycle): RedirectResponse
    {
        // AZ INDÍTÁS ÉS AZ ÜTEMEZÉS külön képesség: a `author` szerep írhat kampányt, de
        // nem küldheti ki.
        Gate::authorize('campaign.publish');

        $campaign = Campaign::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['schedule', 'unschedule', 'start', 'pause', 'resume'])],
            'localTime' => ['required_if:action,schedule', 'nullable', 'string'],
            'timezone' => ['required_if:action,schedule', 'nullable', 'string'],
        ]);

        try {
            match ($validated['action']) {
                'schedule' => $lifecycle->schedule($campaign, (string) $validated['localTime'], (string) $validated['timezone']),
                'unschedule' => $lifecycle->unschedule($campaign),
                'start' => $lifecycle->start($campaign),
                'pause' => $lifecycle->pause($campaign),
                'resume' => $lifecycle->resume($campaign),
            };
        } catch (\Throwable $e) {
            // Az állapotgép és a kapuk (hitelesített domain, consent, leiratkozó blokk)
            // hibáit UGYANÚGY jelezzük: a marketinges számára mindkettő ugyanaz a kérdés —
            // „miért nem megy ki?"
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.campaigns.'.$validated['action']));
    }
}
