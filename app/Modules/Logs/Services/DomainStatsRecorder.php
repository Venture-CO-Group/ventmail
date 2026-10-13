<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Modules\Sending\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * A domainenkénti napi számlálók írása az esemény-folyamból (D175).
 *
 * ## „Küldött" = a KAPU elfogadása, címzettenként egyszer
 *
 * Az `accepted` esemény az éles driver-úton KÉTSZER keletkezik (a `SendMessage` a kapun, a
 * `MessageDispatcher` a driver válaszán — lásd `EventCounters`). A dispatcher-féle sor a
 * payloadban `provider_message_id`-t hordoz; azt nem számoljuk, így a „küldött" nem duplázódik.
 *
 * ## A domain az ÜZENETBŐL jön
 *
 * Az esemény nem hordoz domaint; a `messages.domain_id`-t egy indexelt lekérdezés adja, a
 * kérés / job idejére memoizálva (egy 1000 címzettes batch 1000 eseménye EGY lekérdezés).
 */
final class DomainStatsRecorder
{
    /** @var array<string, string> esemény-típus → számláló-oszlop */
    private const COLUMNS = [
        'accepted' => 'sent',
        'delivered' => 'delivered',
        'bounced' => 'bounced',
        'complained' => 'complained',
        'opened' => 'opened',
        'clicked' => 'clicked',
    ];

    private const MEMO_LIMIT = 1000;

    /** @var array<string, int> */
    private array $domains = [];

    public function record(MessageEvent $event): void
    {
        $column = self::COLUMNS[(string) $event->type] ?? null;

        if ($column === null || $event->message_uuid === null) {
            return;
        }

        if ($column === 'sent' && isset(((array) $event->payload)['provider_message_id'])) {
            return;
        }

        $domainId = $this->domainOf((string) $event->message_uuid);
        $day = ($event->occurred_at ?? now())->toDateString();

        DomainDailyStat::query()->upsert(
            [[
                'workspace_id' => (int) $event->workspace_id,
                'domain_id' => $domainId,
                'day' => $day,
                $column => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['workspace_id', 'domain_id', 'day'],
            [
                $column => DB::raw('domain_daily_stats.'.$column.' + 1'),
                'updated_at' => now(),
            ],
        );
    }

    private function domainOf(string $messageUuid): int
    {
        if (array_key_exists($messageUuid, $this->domains)) {
            return $this->domains[$messageUuid];
        }

        if (count($this->domains) >= self::MEMO_LIMIT) {
            $this->domains = [];
        }

        return $this->domains[$messageUuid] = (int) (Message::query()->where('uuid', $messageUuid)->value('domain_id') ?? 0);
    }
}
