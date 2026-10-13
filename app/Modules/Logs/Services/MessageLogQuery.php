<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A kereshető napló (22. kritérium).
 *
 * Szűrés címzettre, tagre, csatornára, domainre, állapotra és időablakra. A lapozás ITT is
 * kurzoros: a napló ugyanúgy nő, mint az eseménytábla, és egy 2. oldalon elcsúszó sor a
 * support munkáját teszi tönkre („az előbb még itt volt").
 *
 * A CÍMZETT-szűrés a `recipients` jsonb-tömbre megy: a batch egyetlen sorban ezer címet
 * tárol, tehát a `where('recipient', …)` nem működne.
 *
 * A keresett címet UGYANAZZAL a normalizálóval alakítjuk, amivel tároltuk. Ez nem
 * kozmetika: a `whereJsonContains` PONTOS egyezést vár, a tárolt alakban pedig a helyi rész
 * VÁLTOZATLAN, a domain viszont punycode. Naiv `mb_strtolower()`-rel a `Ugyfel@pelda.hu`-ra
 * küldött levél a naplóban `Ugyfel@pelda.hu`-ra keresve NEM jönne elő, a `user@példa.hu`
 * pedig soha — vagyis a 22. kritérium fő szűrője csendben rossz választ adna.
 */
final class MessageLogQuery
{
    public function __construct(private readonly DomainNormalizer $normalizer) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 50, ?string $cursor = null): CursorPaginator
    {
        return $this->query($filters)
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
    }

    /**
     * A szűrt lekérdezés — a lapozott lista ÉS az export közös forrása (v1.1 · 7. modul), hogy
     * a letöltött CSV pontosan azt tartalmazza, amit a képernyő mutat.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Message>
     */
    public function query(array $filters): Builder
    {
        $messageId = self::messageUuid((string) ($filters['message_id'] ?? ''));

        return Message::query()
            ->with('domain')
            // A Message-ID szűrő: az üzenet azonosítója (uuid), akár `<uuid@host>` fejléc-alakban
            // is beillesztve. Érvénytelen alakra SEMMI (nem 500: a `uuid` oszlop típusos).
            ->when(($filters['message_id'] ?? '') !== '', fn (Builder $query) => $messageId === null
                ? $query->whereRaw('false')
                : $query->where('uuid', $messageId))
            ->when(($filters['recipient'] ?? '') !== '', fn (Builder $query) => $query
                ->whereJsonContains('recipients', $this->normalizer->email(trim((string) $filters['recipient']))))
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
                ->where('created_at', '<=', $filters['until']));
    }

    /** `uuid`, `<uuid>` vagy `<uuid@host>` → uuid; más alak → null. */
    public static function messageUuid(string $raw): ?string
    {
        $value = trim($raw, " \t<>");
        $value = explode('@', $value, 2)[0];

        return Str::isUuid($value) ? strtolower($value) : null;
    }
}
