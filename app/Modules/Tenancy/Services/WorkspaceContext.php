<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Az aktuális kérés workspace-kontextusa.
 *
 * Fail-closed: amíg nincs beállítva, a WorkspaceScope egyetlen sort sem enged át.
 * A parent workspace a gyerekei ÖSSZESÍTETT használatát láthatja, de a child NYERS
 * adataihoz külön kontextus-váltás kell, amit csak explicit jogosultság engedélyez
 * (spec 3.2). Child ↔ child átjárás soha.
 */
final class WorkspaceContext
{
    private ?Workspace $workspace = null;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function forget(): void
    {
        $this->workspace = null;
    }

    public function hasWorkspace(): bool
    {
        return $this->workspace instanceof Workspace;
    }

    public function workspace(): Workspace
    {
        return $this->workspace ?? throw new RuntimeException(
            'Nincs workspace-kontextus. Minden tenant-adatot érintő művelet előtt be kell állítani.'
        );
    }

    public function workspaceId(): int
    {
        return (int) $this->workspace()->getKey();
    }

    /**
     * Parent → child kontextus-váltás a child NYERS adataihoz.
     *
     * Csak akkor engedélyezett, ha (a) az aktuális kontextus a child szülője, és
     * (b) a kapcsolat `permissions.raw_data` jogot ad. Child ↔ child átjárás soha
     * (spec 3.2, golden rule 6).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws AuthorizationException
     */
    public function runForChild(Workspace $child, callable $callback): mixed
    {
        $current = $this->workspace();

        // A kapcsolat joga (pivot) ÉS a kérő felhasználó joga is kell: egy parent-beli
        // `analyst` nem olvashat child nyers adatot pusztán azért, mert a pivot engedi.
        $user = Auth::user();

        if ($user === null || ! Gate::forUser($user)->allows('subAccount.manage')) {
            throw new AuthorizationException('A felhasználó nem jogosult sub-account adat olvasására.');
        }

        if (! $child->isChildOf($current)) {
            throw new AuthorizationException('A workspace nem sub-accountja az aktuális workspace-nek.');
        }

        $permissions = (array) json_decode(
            (string) ($child->parents()->where('workspaces.id', $current->getKey())->first()?->pivot?->permissions ?? '{}'),
            associative: true
        );

        if (($permissions['raw_data'] ?? false) !== true) {
            throw new AuthorizationException('A parent nem jogosult a sub-account nyers adataihoz.');
        }

        return $this->runFor($child, $callback);
    }

    /**
     * Ideiglenes kontextus-váltás. NEM végez jogosultság-ellenőrzést — a hívó felelőssége;
     * parent → child irányban használd a `runForChild()`-ot.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runFor(Workspace $workspace, callable $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}
