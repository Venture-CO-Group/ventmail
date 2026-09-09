<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Enums\Feature;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\Dunning;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Billing\Services\Metering;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SZÁMLÁZÁS ÉS FOGYÁS képernyője (75–80. kritérium).
 *
 * ## Miért `billing.manage`, és miért csak az `owner`
 *
 * Mert ez a képernyő pénzről szól: a kivonat, a fizetési állapot és a felfüggesztés
 * kockázata. Az `AbilityMap` szerint még az `admin` sem költhet — a jogosultsági döntés
 * ott van, ez a képernyő csak követi.
 *
 * ## A FELFÜGGESZTÉS nem meglepetés
 *
 * A dunning-állapot itt LÁTSZIK, mielőtt a küldés leáll. A PreSendGate 4. kapuja a
 * felfüggesztett fióknak `QUOTA_EXCEEDED`-et ad — ha ezt csak a hibaüzenetből tudná meg az
 * ügyfél, a támogatás értesülne róla először.
 */
final class BillingController
{
    public function __invoke(
        WorkspaceContext $context,
        Metering $metering,
        EntitlementChecker $entitlements,
        Dunning $dunning,
    ): Response {
        Gate::authorize('billing.manage');

        $workspace = $context->workspace();

        $usage = [];

        foreach (UsageDimension::cases() as $dimension) {
            $usage[] = [
                'dimension' => $dimension->value,
                'current' => $metering->current($dimension),
            ];
        }

        $features = [];

        foreach (Feature::cases() as $feature) {
            $features[] = [
                'feature' => $feature->value,
                // A CSOMAG-FUNKCIÓ az `EntitlementChecker`-en jön: így a platform-admin
                // plan-override-ját is látja (C2 · 83. kritérium), nem csak a ventPay-t.
                'enabled' => $entitlements->allows($workspace, $feature),
            ];
        }

        return Inertia::render('Billing/Index', [
            'period' => $metering->period(),
            'usage' => $usage,
            'features' => $features,
            'remainingQuota' => $entitlements->remainingQuota($workspace),
            'suspended' => $dunning->isSuspended($workspace),

            'invoices' => Invoice::query()
                ->orderByDesc('period')
                ->limit(24)
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'uuid' => (string) $invoice->uuid,
                    'period' => (string) $invoice->period,
                    'state' => (string) $invoice->dunning_state,
                    'dueAt' => $invoice->due_at?->toIso8601String(),
                    'paidAt' => $invoice->paid_at?->toIso8601String(),
                    'suspendedAt' => $invoice->suspended_at?->toIso8601String(),
                    'closedAt' => $invoice->closed_at?->toIso8601String(),
                    // A KIVONAT dimenziónkénti bontása: a „miért ennyi?" kérdés válasza.
                    'usage' => (array) $invoice->usage,
                ])->values()->all(),
        ]);
    }
}
