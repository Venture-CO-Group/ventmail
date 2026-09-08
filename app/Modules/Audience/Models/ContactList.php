<?php

declare(strict_types=1);

namespace App\Modules\Audience\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Egy lista (Mailchimp „audience") — a leiratkozás LEGKISEBB egysége.
 *
 * A saját feladó és leiratkozási szöveg nem díszítés: a preference center ebből tudja
 * megmutatni a címzettnek, MIRŐL iratkozik le. Egy „leiratkozom mindenről" gomb a
 * gyakorlatban azt jelenti, hogy a tranzakciós értesítéseket is elveszti — ezért a
 * listánkénti választás a helyes alapérték.
 */
final class ContactList extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'description', 'from_email', 'from_name'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function contacts(): BelongsToMany
    {
        // A pivot `workspace_id`-ját itt is ki kell tölteni (lásd `Contact::lists()`): a
        // `belongsToMany` nem tud a saját pivotján lévő extra oszlopról.
        $context = app(WorkspaceContext::class);

        return $this->belongsToMany(Contact::class, 'contact_list_memberships')
            ->withPivot(['status', 'subscribed_at', 'unsubscribed_at'])
            ->withPivotValue(
                'workspace_id',
                $context->hasWorkspace() ? $context->workspaceId() : (int) $this->workspace_id
            )
            ->withTimestamps();
    }

    public function subscribers(): BelongsToMany
    {
        return $this->contacts()->wherePivot('status', 'subscribed');
    }
}
