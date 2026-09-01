<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A kérés workspace-kontextusának feloldása és a TAGSÁG ellenőrzése.
 *
 * A tagság forrása kizárólag a `workspace_members` tábla — a ventSSO tokenje sosem dönt
 * hozzáférésről (golden rule 1). Idegen workspace-re 403, nem néma üres lista: a hallgatag
 * kudarc elrejtené a jogosultsági hibát.
 */
final class ResolveWorkspace
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('auth.redirect'));
        }

        $requested = (string) $request->session()->get('workspace_uuid', '');

        $membership = WorkspaceMember::query()
            ->where('user_id', $user->getKey())
            ->when($requested !== '', fn ($query) => $query->whereIn(
                'workspace_id',
                Workspace::query()->where('uuid', $requested)->select('id')
            ))
            ->orderBy('id')
            ->first();

        if ($membership === null) {
            abort(403, 'Nincs hozzáférésed ehhez a workspace-hez.');
        }

        $workspace = Workspace::query()->whereKey($membership->workspace_id)->firstOrFail();

        $this->context->set($workspace);
        $request->session()->put('workspace_uuid', $workspace->uuid);

        return $next($request);
    }
}
