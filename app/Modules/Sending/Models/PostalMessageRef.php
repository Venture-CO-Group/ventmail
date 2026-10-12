<?php

declare(strict_types=1);

namespace App\Modules\Sending\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A Postal címzettenkénti tokenje → ventMail-üzenet (a kézbesítési webhook egyetlen kapcsolata).
 *
 * Tenant-hez kötött, mint minden `workspace_id`-s sor: a `workspace_id` a küldés
 * kontextusából íródik (nem tömegesen kitölthető), és minden normál lekérdezés scope-olt.
 */
final class PostalMessageRef extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['token', 'pool', 'message_uuid', 'recipient'];

    /**
     * A hivatkozás feloldása a webhookból — KONTEXTUS NÉLKÜL, mert a kontextus épp ebből jön.
     *
     * Ugyanaz a minta, mint az `ApiKey::findByPlainKey()`: a global scope-ot itt, és
     * KIZÁRÓLAG itt, egyetlen pontos egyezésre (pool + Postal-token) kapcsoljuk ki; a hívó
     * (a RSA-aláírással hitelesített `PostalWebhookController`) ezután azonnal a sor
     * workspace-ére állítja a kontextust. A token a Postal saját, véletlen azonosítója,
     * és csak a saját példánya (pool) aláírt webhookjából fogadjuk el.
     */
    public static function resolve(string $pool, string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return self::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('pool', $pool)
            ->where('token', $token)
            ->first();
    }
}
