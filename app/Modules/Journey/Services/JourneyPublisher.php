<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Journey\Graph\JourneyGraph;
use App\Modules\Journey\Graph\JourneyGraphValidator;
use App\Modules\Journey\Graph\NodeCatalog;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Journey\Models\JourneyVersion;
use App\Modules\Template\Services\TemplateUsage;
use Illuminate\Support\Facades\DB;

/**
 * A VÁSZON PUBLIKÁLÁSA (v1.1 · 6. modul, D5): validált gráf → megváltoztathatatlan verzió +
 * a verzió lépései (`journey_steps`, `journey_version_id`-vel).
 *
 * ## Miért materializálunk lépéseket, és miért nem a gráf JSON-t futtatjuk
 *
 * A motor a `journey_steps` sorain lépteti a futást, és erre épül minden védelme: az
 * idempotencia (`journey_run_steps` egyedi kulcsa a LÉPÉS-id-re), az adatbázis lánc-őre (a
 * `next_step_id` / `else_step_id` nem léphet át journey-határt), a sablon-publikálási kapu
 * (`TemplateUsage` a `send_email` lépések `config->template`-jét nézi) és a címke-hivatkozás
 * keresése. Egy JSON-futtató mindezt újra feltalálná. A gráf a SZERKESZTŐ dokumentuma; a
 * verzió lépései a motoré.
 *
 * ## Verziózás
 *
 * Minden publikálás ÚJ verziót hoz (ha a végrehajtást meghatározó tartalom változott). A
 * régi verzió lépései MEGMARADNAK: a rajtuk álló futások (`journey_runs.current_step_id`,
 * `journey_version_id`) ott haladnak tovább, ahol tartottak. Az új belépések a
 * `journeys.current_version_id` verzió belépési lépésén indulnak.
 */
final class JourneyPublisher
{
    public function __construct(
        private readonly JourneyGraphValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws JourneyGraphInvalid ha a gráf hibás
     */
    public function publish(Journey $journey, ?User $actor = null): JourneyVersion
    {
        return DB::transaction(function () use ($journey, $actor): JourneyVersion {
            /** @var Journey $locked */
            $locked = Journey::query()->whereKey($journey->getKey())->lockForUpdate()->firstOrFail();

            $graph = JourneyGraph::fromArray(is_array($locked->graph) ? $locked->graph : null);

            // A `send_email` lépések SABLONJAINAK megosztott zárja (review SEC4-S1), a validálás ELŐTT:
            // a szerkesztő mentése (FOR UPDATE) ezalatt vár, és a commit után már az aktív
            // journey-t látja — publikálási jog nélkül nem írhatja át a kiküldendő tartalmat.
            app(TemplateUsage::class)->lockForActivationByUuid(array_values(array_map(
                static fn (array $node): string => (string) ($node['config']['template'] ?? ''),
                array_filter($graph->nodes, static fn (array $node): bool => $node['type'] === 'send_email'),
            )));

            $issues = $this->validator->validate($graph);

            if (JourneyGraphValidator::hasErrors($issues)) {
                throw new JourneyGraphInvalid($issues);
            }

            $hash = $graph->hash();
            $current = $locked->currentVersion;
            $previousStatus = (string) $locked->status;

            // VÁLTOZATLAN gráf: nincs új verzió — a szüneteltetett journey folytatása. Az AKTÍV,
            // változatlan journey újrapublikálása no-op: se állapotváltás, se audit-sor (review NIT).
            if ($current !== null && $current->graph_hash === $hash) {
                if ($previousStatus === 'active') {
                    return $current;
                }

                $locked->forceFill(['status' => 'active'])->save();

                $this->audit->log('journey.resumed', $locked, context: [
                    'version' => (int) $current->number,
                    'previous_status' => $previousStatus,
                ], actor: $actor);

                return $current;
            }

            /*
             | A CÉL A VERZIÓ RÉSZE (review REV-B2): a futás a SAJÁT verziójának célját nézi. A
             | journey-szintű `goal_type` / `goal_config` NEM íródik felül — azt a v1.1 előtti,
             | verzió nélküli láncon haladó futások használják, és egy publikálás nem veheti el
             | tőlük a kilépési feltételt.
             */
            $goal = $this->goal($graph);

            $version = JourneyVersion::query()->create([
                'goal_type' => $goal['type'] ?: null,
                'goal_config' => $goal['type'] ? $goal['config'] : null,
                'journey_id' => $locked->getKey(),
                'number' => (int) JourneyVersion::query()->where('journey_id', $locked->getKey())->max('number') + 1,
                'graph' => $graph->toArray(),
                'graph_hash' => $hash,
                'published_by' => $actor?->getKey(),
                'published_at' => now(),
            ]);

            $steps = $this->materialize($locked, $version, $graph);

            $trigger = $graph->triggers()[0];
            $entryNode = $graph->target($trigger['id'], 'out');
            $entry = $entryNode === null ? null : ($steps[$entryNode] ?? null);

            if ($entry !== null) {
                $version->forceFill(['entry_step_id' => $entry->getKey()])->save();
            }

            /*
             | Az ÉVFORDULÓ évente ismétlődik: az újrabelépés alapból tiltott (egy kontakt egy
             | journey-ben egyszer), ami az évfordulós journey-t egyetlen évre korlátozná. Ennél
             | a triggernél (`mode = anniversary`) az újrabelépés engedélyezett, legalább 300 napos
             | cooldown-nal. Ha egy KORÁBBI verzió kapcsolta be (évfordulós volt), és az új már nem
             | az, a publikáló VISSZAÁLLÍTJA az alapértéket (review N4) — a kézi beállítást (nincs
             | rá felület, csak adat) nem írja felül.
             */
            $anniversary = static fn (?array $node): bool => $node !== null
                && $node['type'] === 'date_anniversary'
                && ($node['config']['mode'] ?? 'anniversary') !== 'once';
            $previousTrigger = $current === null ? null : (JourneyGraph::fromArray((array) $current->graph)->triggers()[0] ?? null);

            if ($anniversary($trigger)) {
                $locked->forceFill([
                    'allow_reentry' => true,
                    'reentry_cooldown_hours' => max((int) $locked->reentry_cooldown_hours, 300 * 24),
                ]);
            } elseif ($anniversary($previousTrigger)) {
                $locked->forceFill(['allow_reentry' => false, 'reentry_cooldown_hours' => 720]);
            }

            $locked->forceFill([
                'trigger_type' => $trigger['type'],
                'trigger_config' => $this->triggerConfig($trigger),
                'first_step_id' => $entry?->getKey(),
                'current_version_id' => $version->getKey(),
                'status' => 'active',
            ])->save();

            // AZ ADMIN-MŰVELET AUDITÁLT (golden rule 11): ki, melyik verziót, hány lépéssel.
            $this->audit->log('journey.published', $locked, context: [
                'version' => (int) $version->number,
                'version_uuid' => (string) $version->uuid,
                'nodes' => count($graph->nodes),
                'edges' => count($graph->edges),
                'previous_status' => $previousStatus,
                'previous_version' => $current?->number,
            ], actor: $actor);

            return $version;
        });
    }

    /**
     * A gráf AKCIÓ-csomópontjai → a verzió lépései, majd a kimenetek bekötése.
     *
     * @return array<string, JourneyStep> csomópont-id → lépés
     */
    private function materialize(Journey $journey, JourneyVersion $version, JourneyGraph $graph): array
    {
        $nodes = array_values(array_filter($graph->nodes, static fn (array $node): bool => NodeCatalog::isAction($node['type'])));

        // A MEGJELENÍTÉSI sorrend balról jobbra, fentről lefelé — a végrehajtás a kimeneteket követi.
        usort($nodes, static fn (array $a, array $b): int => [$a['position']['x'], $a['position']['y']] <=> [$b['position']['x'], $b['position']['y']]);

        $steps = [];

        foreach ($nodes as $position => $node) {
            $steps[$node['id']] = JourneyStep::query()->create([
                'journey_id' => $journey->getKey(),
                'journey_version_id' => $version->getKey(),
                'node_key' => $node['id'],
                'type' => $node['type'],
                'config' => $this->stepConfig($node),
                'position' => $position,
            ]);
        }

        foreach ($nodes as $node) {
            $step = $steps[$node['id']];
            $links = ['next_step_id' => null, 'else_step_id' => null, 'branches' => null];
            $resolve = static fn (?string $target): ?int => $target === null || ! isset($steps[$target]) ? null : (int) $steps[$target]->getKey();

            match ($node['type']) {
                'condition' => $links = [
                    'next_step_id' => $resolve($graph->target($node['id'], 'yes')),
                    'else_step_id' => $resolve($graph->target($node['id'], 'no')),
                    'branches' => null,
                ],
                'wait' => $links = [
                    'next_step_id' => $resolve($graph->target($node['id'], 'out')),
                    'else_step_id' => $resolve($graph->target($node['id'], 'timeout')),
                    'branches' => null,
                ],
                'split', 'ab_split' => $links['branches'] = array_filter(
                    array_combine(
                        $outputs = NodeCatalog::outputs($node['type'], $node['config']),
                        array_map(static fn (string $handle): ?int => $resolve($graph->target($node['id'], $handle)), $outputs),
                    ),
                    static fn (?int $id): bool => $id !== null,
                ),
                default => $links['next_step_id'] = $resolve($graph->target($node['id'], 'out')),
            };

            $step->forceFill($links)->save();
        }

        return $steps;
    }

    /**
     * A csomópont konfigurációja a MOTOR alakjára — csak az ismert mezők.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function stepConfig(array $node): array
    {
        $config = $node['config'];
        $pick = static fn (array $keys): array => array_filter(
            array_intersect_key($config, array_flip($keys)),
            static fn ($value): bool => $value !== null && $value !== '',
        );

        return match ($node['type']) {
            'send_email' => $pick(['template', 'from_email', 'from_name', 'subject']),
            'wait' => ($config['mode'] ?? 'time') === 'event'
                ? ['mode' => 'event', 'event' => (string) $config['event'], 'timeout_minutes' => (int) $config['timeout_minutes']]
                : ['minutes' => (int) $config['minutes']],
            'condition' => self::conditionConfig($config),
            'split' => ['branches' => array_values(array_map(
                static fn (array $branch): array => ['key' => (string) $branch['key'], 'condition' => self::conditionConfig((array) ($branch['condition'] ?? []))],
                array_values(array_filter((array) ($config['branches'] ?? []), 'is_array')),
            ))],
            'ab_split' => ['variants' => array_values(array_map(
                static fn (array $variant): array => ['key' => (string) $variant['key'], 'weight' => (int) $variant['weight']],
                array_values(array_filter((array) ($config['variants'] ?? []), 'is_array')),
            ))],
            'update_contact' => $pick(['operation', 'field', 'value', 'tag']),
            'list_membership' => $pick(['operation', 'list']),
            'webhook' => $pick(['endpoint']),
            'goal' => is_array($config['goal'] ?? null) ? ['goal' => self::conditionConfig($config['goal'])] : [],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function conditionConfig(array $config): array
    {
        return array_filter(
            array_intersect_key($config, array_flip(['type', 'segment', 'tag', 'within_hours', 'key', 'value'])),
            static fn ($value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @return array<string, mixed>
     */
    private function triggerConfig(array $trigger): array
    {
        return array_filter(
            array_intersect_key($trigger['config'], array_flip(['list', 'tag', 'field', 'mode', 'offset_days', 'form', 'event', 'campaign', 'interaction', 'segment', 'route'])),
            static fn ($value): bool => $value !== null && $value !== '',
        );
    }

    /** @return array{type: ?string, config: array<string, mixed>} */
    private function goal(JourneyGraph $graph): array
    {
        foreach ($graph->nodes as $node) {
            if ($node['type'] === 'goal' && is_array($node['config']['goal'] ?? null)) {
                $config = self::conditionConfig($node['config']['goal']);

                return ['type' => (string) ($config['type'] ?? ''), 'config' => array_diff_key($config, ['type' => true])];
            }
        }

        return ['type' => null, 'config' => []];
    }
}
