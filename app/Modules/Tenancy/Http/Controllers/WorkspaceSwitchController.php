<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * WORKSPACE-VÁLTÁS — a tagság ellenőrzésével, nem a kérés szavára.
 *
 * A `ResolveWorkspace` middleware a session `workspace_uuid` kulcsát olvassa. Ha ezt a
 * kliens szabadon írhatná, a váltás tenant-határ átlépése lenne; ezért a váltás
 * SZERVEROLDALI művelet, ami előbb tagságot ellenőriz, és csak utána ír session-t.
 */
final class WorkspaceSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $uuid = (string) $request->string('workspace');

        $membership = WorkspaceMember::query()
            ->whereHas('workspace', fn ($query) => $query->where('uuid', $uuid))
            ->where('user_id', $request->user()?->getKey())
            ->first();

        if ($membership === null) {
            // Nem 404: a kérő tudja, hogy létezik-e a workspace — a válasz arról szól, hogy
            // NEKI nincs hozzá köze.
            abort(403, 'Nincs hozzáférésed ehhez a workspace-hez.');
        }

        $request->session()->put('workspace_uuid', $uuid);

        return back()->with('success', __('ventmail.app.workspace_switched'));
    }
}
