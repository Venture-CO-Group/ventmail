<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-tagság EGYETLEN forrása (golden rule 1): a ventSSO tokenje sosem dönt
 * workspace-hozzáférésről, csak szerepet ad.
 */
final class WorkspaceMember extends Model
{
    // A workspace_id csak a Tenancy modul sanctionált útján (AssignWorkspaceRoles) állítható:
    // egy tagkezelő endpoint tömeges hozzárendeléssel idegen workspace-be írhatna tagságot.
    // Az `owner_mirrored` SZÁNDÉKOSAN nem tömegesen kitölthető: ez BIZTONSÁGI
    // diszkriminátor (ő dönti el, hogy a parent visszaveheti-e a tulajdonosi jogot), és
    // egy jövőbeli `create($request->validated())` csendben megnyitná. A négy sanctionált
    // hely explicit attribútum-értékadással írja.
    protected $fillable = ['workspace_id', 'user_id', 'roles'];

    /** @var list<string> */
    protected $hidden = [];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['roles' => 'array', 'owner_mirrored' => 'boolean'];
    }

    /**
     * A TULAJDONOSI JOG a parent tükrözéséből származik-e?
     *
     * Csak erre a szerepre vonatkozik, és minden emberi kiosztás hamisra állítja: a döntés
     * utolsó gazdája számít, nem az, hogyan keletkezett a sor.
     */
    public function hasMirroredOwnership(): bool
    {
        return (bool) $this->owner_mirrored;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_filter((array) $this->roles));
    }
}
