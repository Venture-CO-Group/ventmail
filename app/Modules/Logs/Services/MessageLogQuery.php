<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Sending\Models\Message;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A kereshető napló (22. kritérium).
 *
 * Szűrés címzettre, tagre, csatornára, domainre, állapotra és időablakra. A lapozás ITT is
 * kurzoros: a napló ugyanúgy nő, mint az eseménytábla, és egy 2. oldalon elcsúszó sor a
 * support munkáját teszi tönkre („az előbb még itt volt").
 *
 * A CÍMZETT-szűrés a `recipients` jsonb-tömbre megy: a batch egyetlen sorban ezer címet
 * tárol, tehát a `where('recipient', …)` nem működne.
 */
final class MessageLogQuery
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 50, ?string $cursor = null): CursorPaginator
    {
        return Message::query()
            ->with(['domain', 'ipAddress'])
            ->when(($filters['recipient'] ?? '') !== '', fn (Builder $query) => $query
                ->whereJsonContains('recipients', mb_strtolower(trim((string) $filters['recipient']))))
            ->when(($filters['tag'] ?? '') !== '', fn (Builder $query) => $query
                ->whereJsonContains('tags', (string) $filters['tag']))
            ->when(($filters['channel'] ?? '') !== '', fn (Builder $query) => $query
                ->where('channel', (string) $filters['channel']))
            ->when(($filters['status'] ?? '') !== '', fn (Builder $query) => $query
                ->where('status', (string) $filters['status']))
            ->when(($filters['domain'] ?? '') !== '', fn (Builder $query) => $query
                ->whereHas('domain', fn (Builder $inner) => $inner->where('name', (string) $filters['domain'])))
            ->when(($filters['since'] ?? '') !== '', fn (Builder $query) => $query
                ->where('created_at', '>=', $filters['since']))
            ->when(($filters['until'] ?? '') !== '', fn (Builder $query) => $query
                ->where('created_at', '<=', $filters['until']))
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }
}
