<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Models\Message;
use App\Support\CsvSafe;
use Illuminate\Support\Carbon;

/**
 * A NAPLÓ CSV-EXPORTJA (v1.1 · 7. modul, D173).
 *
 * - STREAMELT: a sorok kulcs-alapú (id) darabokban jönnek, a memória O(darab), nem O(napló).
 * - KORLÁTOS: legfeljebb `logs.export_max_rows` sor; ha több lenne, az utolsó sor jelzi a
 *   csonkolást (a felhasználó szűkít, nem egy félmillió soros fájlt kap).
 * - RETENCIÓ: a legkorábbi exportálható időpont a retenció kezdete — lejárt adat exportban
 *   sem éled fel.
 * - PII-TUDATOS: alapból a címzett helyi része maszkolt és a tárgy kimarad; a teljes alak
 *   csak kifejezett kérésre (`pii`), és az audit-sor ezt is rögzíti.
 * - CSV-INJEKCIÓ: minden cella a `CsvSafe` szűrőn megy át (`=`, `+`, `-`, `@` kezdet).
 * - AUDITÁLT: ki, mikor, milyen szűrővel exportált.
 */
final class LogExport
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly MessageLogQuery $query,
        private readonly AuditLogger $audit,
    ) {}

    public static function maxRows(): int
    {
        return max(1, (int) config('logs.export_max_rows', 50_000));
    }

    public static function retentionStart(): Carbon
    {
        return now()->subDays((int) config('sending.delivery_attempt_retention_days', 90))->startOfDay();
    }

    /**
     * A szűrők normalizálása: a `since` nem lehet a retenció előtt.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function bounded(array $filters): array
    {
        $start = self::retentionStart();
        $since = isset($filters['since']) && $filters['since'] !== '' ? Carbon::parse((string) $filters['since']) : null;

        $filters['since'] = ($since === null || $since->lt($start) ? $start : $since)->toIso8601String();

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    public function audit(array $filters, bool $pii): void
    {
        $this->audit->log('logs.exported', null, context: [
            'filters' => array_filter($filters, static fn ($value) => $value !== null && $value !== ''),
            'pii' => $pii,
            'max_rows' => self::maxRows(),
        ]);
    }

    /**
     * A CSV kiírása a megadott kimenetre (a hívó `php://output`-ot ad a streamelt válaszhoz).
     *
     * @param  array<string, mixed>  $filters
     * @param  resource  $out
     */
    public function write(array $filters, bool $pii, $out): int
    {
        fputcsv($out, ['created_at', 'message_id', 'status', 'reason', 'channel', 'from', 'domain', 'recipient_count', 'recipients', 'subject', 'tags'], ',', '"', '');

        $max = self::maxRows();
        $written = 0;
        $lastId = null;

        while ($written < $max) {
            $chunk = $this->query->query(self::bounded($filters))
                ->when($lastId !== null, fn ($query) => $query->where('id', '<', $lastId))
                ->orderByDesc('id')
                ->limit(min(self::CHUNK, $max - $written))
                ->get();

            if ($chunk->isEmpty()) {
                break;
            }

            foreach ($chunk as $message) {
                fputcsv($out, array_map(CsvSafe::cell(...), $this->row($message, $pii)), ',', '"', '');
                $written++;
            }

            $lastId = $chunk->last()->getKey();

            if ($chunk->count() < self::CHUNK) {
                break;
            }
        }

        // CSONKOLÁS csak akkor, ha TÉNYLEG maradt sor: pontosan `max` találatnál nincs jelzés.
        if ($written >= $max && $lastId !== null
            && $this->query->query(self::bounded($filters))->where('id', '<', $lastId)->exists()) {
            fputcsv($out, ['# truncated', (string) $max], ',', '"', '');
        }

        return $written;
    }

    /** @return list<string> */
    private function row(Message $message, bool $pii): array
    {
        $recipients = array_values(array_map(strval(...), (array) $message->recipients));

        return [
            (string) $message->created_at?->toIso8601String(),
            (string) $message->uuid,
            (string) $message->status,
            (string) $message->reason,
            (string) $message->channel,
            $pii ? (string) $message->from_email : self::mask((string) $message->from_email),
            (string) ($message->domain?->name ?? ''),
            (string) count($recipients),
            implode(';', $pii ? $recipients : array_map(self::mask(...), $recipients)),
            $pii ? (string) $message->subject : '',
            implode(';', array_map(strval(...), (array) $message->tags)),
        ];
    }

    /** `anna.kovacs@pelda.hu` → `a***@pelda.hu` — a domain marad (kézbesíthetőségi elemzéshez kell). */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return $email === '' ? '' : '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
