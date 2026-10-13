<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Actions\CreateWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceCreateController
{
    public function __invoke(Request $request, CreateWorkspace $create): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        // A `TrimStrings` + `ConvertEmptyStringsToNull` a szóközös nevet üresre viszi.
        $name = (string) $validated['name'];

        $workspace = $create($request->user(), $name);

        // A váltó rögtön az újra áll: az új workspace-ben a létrehozó tulajdonos.
        $request->session()->put('workspace_uuid', (string) $workspace->uuid);

        return redirect('/dashboard')->with('success', __('ventmail.workspaces.created', ['name' => $name]));
    }
}
