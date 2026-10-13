<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Models\EngagementClientDaily;
use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ESZKÖZ/KLIENS-SZÁMLÁLÓ írása a tracking-végpontról (v1.1 · 8. modul, D229).
 *
 * Csak kampány- és journey-levélre (`campaign:` / `journey:` tag) — a tranzakciós levél nem
 * marketing-riport. A User-Agent itt OSZTÁLYOZÓDIK és eldobódik: a táblába csak a kategória
 * kerül. Sor-szintű `+1` upsert, az ütközési cél a `workspace_id`-t is tartalmazza (az upsert
 * INSERT, a global scope nem hat rá).
 */
final class ClientStatsRecorder
{
    public function __construct(
        private readonly MailClientClassifier $classifier,
        private readonly WorkspaceContext $context,
    ) {}

    public function record(Message $message, string $type, ?string $userAgent): void
    {
        $kind = match ($type) {
            'opened' => 'open',
            'clicked' => 'click',
            default => null,
        };

        $scope = self::scopeOf((array) $message->tags);

        if ($kind === null || $scope === null) {
            return;
        }

        if (! $this->context->hasWorkspace()) {
            throw new RuntimeException('Az eszköz-statisztika workspace-kontextus nélkül nem írható.');
        }

        ['device' => $device, 'client' => $client] = $this->classifier->classify($userAgent);

        // SAVEPOINT: a hívó (tracking) tranzakcióját egy hibás sor ne mérgezze.
        DB::transaction(fn () => EngagementClientDaily::query()->upsert(
            [[
                'workspace_id' => $this->context->workspaceId(),
                'scope' => $scope,
                'day' => now()->toDateString(),
                'kind' => $kind,
                'device' => $device,
                'client' => $client,
                'total' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['workspace_id', 'scope', 'day', 'kind', 'device', 'client'],
            ['total' => DB::raw('engagement_client_daily.total + 1'), 'updated_at' => now()],
        ));
    }

    /** @param array<int, mixed> $tags */
    public static function scopeOf(array $tags): ?string
    {
        foreach ($tags as $tag) {
            $tag = (string) $tag;

            if (preg_match('/^campaign:[0-9a-fA-F-]{36}$/', $tag) === 1 || preg_match('/^journey:\d+$/', $tag) === 1) {
                return $tag;
            }
        }

        return null;
    }
}
