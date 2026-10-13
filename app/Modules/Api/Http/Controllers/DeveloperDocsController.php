<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Services\ApiReference;
use App\Modules\Webhooks\Http\Controllers\WebhookUiController;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FEJLESZTŐI DOKUMENTÁCIÓ AZ APPON BELÜL (v1.1 · 7. modul, D177).
 *
 * API-referencia a `/v1` szerződésből (OpenAPI) generálva, kódmintákkal (curl, PHP, Node,
 * Python — a kliens generálja a végpontból és a példa-törzsből), a webhook-payload sémája
 * (UGYANAZ a minta, amit a teszt-esemény küld), és a sandbox leírása.
 *
 * A SANDBOX a meglévő seed-allowlistre épül: amíg a küldés nem éles (vagy az allowlist
 * kötelező), csak az allowlistes címzett kap levelet. Az allowlist TARTALMA nem jelenik meg —
 * csak az, hogy aktív-e.
 */
final class DeveloperDocsController
{
    public function __invoke(ApiReference $reference): Response
    {
        // Annak szól, aki integrál vagy üzemeltet: kulcs, webhook vagy napló joga kell hozzá.
        abort_unless(Gate::any(['apiKey.manage', 'webhook.manage', 'logs.view']), 403);

        return Inertia::render('Platform/Docs', [
            'reference' => $reference->build(),
            'webhook' => [
                'events' => WebhookUiController::TEST_TYPES,
                // A VALÓDI esemény alakja: a `test` mező csak a teszt-eseményben szerepel, ezt a
                // séma külön jelöli.
                'sample' => collect(WebhookUiController::samplePayload('delivered'))->except('test')->all(),
                'toleranceSeconds' => 300,
            ],
            'sandbox' => [
                'live' => (bool) config('sending.live'),
                'allowlistEnforced' => (bool) config('sending.allowlist_enforced'),
                'testKeyPrefix' => 'vm_test_',
            ],
        ]);
    }
}
