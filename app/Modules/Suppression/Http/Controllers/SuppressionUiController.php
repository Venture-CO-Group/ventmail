<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Http\Controllers;

use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Enums\SuppressionScope;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\SuppressionStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TILTÓLISTA képernyője (PreSendGate 2. kapuja).
 *
 * ## Amit a lista NEM mutat
 *
 * A PLATFORM-SZINTŰ tiltásokat nem lehet innen feloldani, és a képernyő nem is árulja el,
 * hogy egy cím globálisan tiltott-e: az orákulum lenne. A `SuppressionStore` ezt a
 * szabályt egy helyen tartja — a felület nem kerüli meg, hanem ugyanazt a szolgáltatást
 * hívja, mint a `/v1`.
 *
 * ## Amit NEM lehet visszavonni
 *
 * A panasz és a leiratkozás az ÉRINTETT akarata. A tenant nem törölheti, hogy újra
 * küldhessen — a felület ezért hibaüzenetet ad, nem csendes „nem történt semmit".
 */
final class SuppressionUiController
{
    public function index(Request $request, SuppressionStore $store): Response
    {
        Gate::authorize('suppression.manage');

        $search = $request->string('search')->toString();
        $categories = SuppressionReason::categories();
        $list = $request->string('list')->toString();
        $list = array_key_exists($list, $categories) ? $list : 'bounces';

        $page = $store->listForWorkspace(50, $request->string('cursor')->toString() ?: null, $search, $categories[$list]);
        $byReason = $store->countsByReason();

        return Inertia::render('Suppression/Index', [
            'search' => $search,
            'list' => $list,
            'counts' => collect($categories)->map(
                static fn (array $reasons): int => array_sum(array_map(static fn ($reason) => $byReason[$reason] ?? 0, $reasons))
            )->all(),
            'suppressions' => [
                'items' => collect($page->items())->map(fn (Suppression $suppression) => [
                    'uuid' => (string) $suppression->uuid,
                    'email' => (string) $suppression->email,
                    'reason' => SuppressionReason::fromStored((string) $suppression->reason)?->value ?? (string) $suppression->reason,
                    'appliesTo' => (string) $suppression->applies_to,
                    'source' => (string) $suppression->source,
                    'detail' => $suppression->detail === null
                        ? SuppressionReason::legacyDetail((string) $suppression->reason)
                        : mb_substr((string) $suppression->detail, 0, 160),
                    'expiresAt' => $suppression->expires_at?->toIso8601String(),
                    'createdAt' => $suppression->created_at?->toIso8601String(),
                    // A FELÜLETNEK tudnia kell, mit NEM lehet feloldani — különben a gomb
                    // ott van, a kattintás pedig hibát ad.
                    'revocable' => SuppressionReason::fromStored((string) $suppression->reason)?->isIrrevocableByTenant() !== true,
                ])->values()->all(),
                'nextCursor' => $page->nextCursor()?->encode(),
            ],
            'reasons' => SuppressionReason::tenantWritable(),
            'scopes' => array_map(fn (SuppressionScope $scope) => $scope->value, SuppressionScope::cases()),
        ]);
    }

    public function store(Request $request, SuppressionStore $store): RedirectResponse
    {
        Gate::authorize('suppression.manage');

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:320'],
            // CSAK a tenant által írható okok (D186): a `policy` platform-operátori.
            'reason' => ['required', 'string', Rule::in(SuppressionReason::tenantWritable())],
            'appliesTo' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, SuppressionScope::cases()))],
        ]);

        $saved = $store->addFromUi($validated['email'], $validated['reason'], $validated['appliesTo']);

        // A cím a MENTETT ok listáján jelenik meg (N2, D200): egy ragadós leiratkozásra
        // felvett „blocked" a leiratkozások között marad — oda kell vinni a felhasználót.
        $list = collect(SuppressionReason::categories())->search(fn (array $reasons) => in_array(SuppressionReason::fromStored((string) $saved->reason)?->value, $reasons, true)) ?: 'manual';

        return redirect()->route('suppressions.index', ['list' => $list])->with('success', __('ventmail.suppressions.added'));
    }

    public function destroy(Request $request, SuppressionStore $store): RedirectResponse
    {
        Gate::authorize('suppression.manage');

        // Az INDOKLÁS kötelező (v1.1 · 7. modul): a törlés újra küldhetővé teszi a címet, és
        // egy későbbi reputáció-vizsgálatnál ez mondja meg, miért.
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:320'],
            'justification' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $removed = $store->removeWithJustification($validated['email'], $validated['justification'], 'ui');
        } catch (AuthorizationException) {
            // A SZABÁLY OKÁT kimondjuk: enélkül a felhasználó a felületet hibáztatná, és
            // legközelebb az API-n próbálná meg ugyanezt.
            return back()->with('error', __('ventmail.suppressions.irrevocable'));
        }

        return back()->with(
            $removed ? 'success' : 'error',
            $removed ? __('ventmail.suppressions.removed') : __('ventmail.suppressions.missing')
        );
    }
}
