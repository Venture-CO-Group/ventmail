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
        $page = $store->listForWorkspace(50, $request->string('cursor')->toString() ?: null, $search);

        return Inertia::render('Suppression/Index', [
            'search' => $search,
            'suppressions' => [
                'items' => collect($page->items())->map(fn (Suppression $suppression) => [
                    'uuid' => (string) $suppression->uuid,
                    'email' => (string) $suppression->email,
                    'reason' => (string) $suppression->reason,
                    'appliesTo' => (string) $suppression->applies_to,
                    'source' => (string) $suppression->source,
                    'expiresAt' => $suppression->expires_at?->toIso8601String(),
                    'createdAt' => $suppression->created_at?->toIso8601String(),
                    // A FELÜLETNEK tudnia kell, mit NEM lehet feloldani — különben a gomb
                    // ott van, a kattintás pedig hibát ad.
                    'revocable' => SuppressionReason::tryFrom((string) $suppression->reason)?->isIrrevocableByTenant() !== true,
                ])->values()->all(),
                'nextCursor' => $page->nextCursor()?->encode(),
            ],
            'reasons' => array_map(fn (SuppressionReason $reason) => $reason->value, SuppressionReason::cases()),
            'scopes' => array_map(fn (SuppressionScope $scope) => $scope->value, SuppressionScope::cases()),
        ]);
    }

    public function store(Request $request, SuppressionStore $store): RedirectResponse
    {
        Gate::authorize('suppression.manage');

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:320'],
            'reason' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, SuppressionReason::cases()))],
            'appliesTo' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, SuppressionScope::cases()))],
        ]);

        $store->addFromUi($validated['email'], $validated['reason'], $validated['appliesTo']);

        return back()->with('success', __('ventmail.suppressions.added'));
    }

    public function destroy(Request $request, SuppressionStore $store): RedirectResponse
    {
        Gate::authorize('suppression.manage');

        $validated = $request->validate(['email' => ['required', 'string', 'max:320']]);

        try {
            $removed = $store->removeForWorkspace($validated['email']);
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
