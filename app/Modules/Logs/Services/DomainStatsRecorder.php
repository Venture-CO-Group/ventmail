<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Modules\Sending\Models\Message;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /** @var array<string, array{0: int, 1: bool}> üzenet → [domain_id, testmode] */
    private array $domains = [];

    /**
     * A még ki nem írt növekmények KÜLSŐ tranzakciónként (RS4 D194, review 2 N1 D199):
     * gyökér-tranzakció azonosító → `workspace|domain|day` → oszlop → N.
     *
     * @var array<int, array<string, array<string, int>>>
     */
    private array $batches = [];

    public function record(MessageEvent $event): void
    {
        $column = self::COLUMNS[(string) $event->type] ?? null;

        if ($column === null || $event->message_uuid === null) {
            return;
        }

        if ($column === 'sent' && isset(((array) $event->payload)['provider_message_id'])) {
            return;
        }

        // Kapu-elfogadás, de NEM kézbesítésre szánt (testmode) — a „küldött" nem nőhet tőle.
        [$domainId, $testmode] = $this->messageOf((string) $event->message_uuid);

        if ($column === 'sent' && $testmode) {
            return;
        }

        $key = (int) $event->workspace_id.'|'.$domainId.'|'.($event->occurred_at ?? now())->toDateString();

        $root = $this->rootTransaction();

        // Tranzakción kívül nincs mit összevonni: azonnal kiírjuk.
        if ($root === null) {
            $this->write([$key => [$column => 1]]);

            return;
        }

        $id = spl_object_id($root);

        if (! isset($this->batches[$id])) {
            $this->batches[$id] = [];

            /*
             | EGY kiírás a KÜLSŐ (gyökér) tranzakció commitja után (RS4, D194): egy 1000
             | címzettes batch 1000 eseménye EGY `+1000`-es upsert a forró soron. A visszahívások
             | a GYÖKÉR rekordon ülnek (D199): egy belső savepoint commitja/rollbackje nem viszi el
             | őket, a gyökér rollbackje pedig a köteget is eldobja — a következő külső tranzakció
             | ÚJ köteget nyit, tehát egy korábbi rollback után sem vesznek el események.
             */
            $root->addCallback(function () use ($id): void {
                $batch = $this->batches[$id] ?? [];
                unset($this->batches[$id]);
                $this->write($batch);
            });
            $root->addCallbackForRollback(function () use ($id): void {
                unset($this->batches[$id]);
            });
        }

        $this->batches[$id][$key][$column] = ($this->batches[$id][$key][$column] ?? 0) + 1;
    }

    /**
     * A jelenlegi kapcsolat KÜLSŐ, alkalmazás-szintű tranzakciója (a visszahívásokra
     * alkalmas legkülső rekord), vagy `null`, ha nincs ilyen.
     */
    private function rootTransaction(): ?DatabaseTransactionRecord
    {
        $connection = DB::connection()->getName();

        return app('db.transactions')
            ->callbackApplicableTransactions()
            ->first(fn (DatabaseTransactionRecord $transaction) => $transaction->connection === $connection);
    }

    /** @param array<string, array<string, int>> $pending */
    private function write(array $pending): void
    {
        foreach ($pending as $key => $columns) {
            [$workspaceId, $domainId, $day] = explode('|', $key);

            $row = [
                'workspace_id' => (int) $workspaceId,
                'domain_id' => (int) $domainId,
                'day' => $day,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $update = ['updated_at' => now()];

            foreach ($columns as $column => $n) {
                $row[$column] = $n;
                $update[$column] = DB::raw('domain_daily_stats.'.$column.' + '.(int) $n);
            }

            // SAVEPOINT (RS4): egy hibás statisztika-sor nem mérgezheti a hívó tranzakcióját, és
            // a commit utáni kiírás hibája sem buktathatja el a már elfogadott küldést.
            try {
                DB::transaction(fn () => DomainDailyStat::query()->upsert([$row], ['workspace_id', 'domain_id', 'day'], $update));
            } catch (Throwable $e) {
                Log::warning('A domain-statisztika nem frissült', ['exception' => $e::class]);
            }
        }
    }

    /** @return array{0: int, 1: bool} */
    private function messageOf(string $messageUuid): array
    {
        if (array_key_exists($messageUuid, $this->domains)) {
            return $this->domains[$messageUuid];
        }

        if (count($this->domains) >= self::MEMO_LIMIT) {
            $this->domains = [];
        }

        $row = Message::query()->where('uuid', $messageUuid)->first(['domain_id', 'testmode']);

        return $this->domains[$messageUuid] = [(int) ($row?->domain_id ?? 0), (bool) ($row?->testmode ?? false)];
    }
}
