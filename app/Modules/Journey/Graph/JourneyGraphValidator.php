<?php

declare(strict_types=1);

namespace App\Modules\Journey\Graph;

use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Form;
use App\Modules\Audience\Models\Segment;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Journey\Services\JourneyEngine;
use App\Modules\Template\Models\Template;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * A GRÁF VALIDÁLÁSA (v1.1 · 6. modul): árva csomópont, hiányzó trigger, végtelen hurok,
 * csomópontonként a kötelező konfiguráció és a hivatkozások léte.
 *
 * A hibák (`error`) a PUBLIKÁLÁST blokkolják, a figyelmeztetések (`warning`) nem — a
 * vázlat mindig menthető: egy félkész journey-t el kell tudni tenni holnapra.
 *
 * A HIVATKOZÁSOK a workspace global scope-ján keresztül oldódnak fel: egy idegen tenant
 * sablonjának uuid-ja itt „nem létezik" (`reference_missing`), nem „rendben".
 *
 * Egy hiba alakja: `{code, severity, node?, field?}` — a felület a kódot fordítja.
 */
final class JourneyGraphValidator
{
    /** Az ismert, beépített kontakt-mezők (a `ContactField` mellett). */
    private const BUILTIN_FIELDS = ['first_name', 'last_name', 'company', 'phone'];

    /** @var list<array{code: string, severity: string, node?: string, field?: string}> */
    private array $issues = [];

    /** @var array<string, array<string, true>> típus → létező uuid-ok */
    private array $known = [];

    /**
     * @return list<array{code: string, severity: string, node?: string, field?: string}>
     */
    public function validate(JourneyGraph $graph): array
    {
        $this->issues = [];
        $this->known = $this->loadReferences($graph);

        $triggers = $graph->triggers();

        if ($triggers === []) {
            $this->error('missing_trigger');
        } elseif (count($triggers) > 1) {
            foreach (array_slice($triggers, 1) as $extra) {
                $this->error('multiple_triggers', $extra['id']);
            }
        }

        $goals = 0;

        foreach ($graph->nodes as $node) {
            if (! NodeCatalog::isKnown($node['type'])) {
                $this->error('unknown_node_type', $node['id']);

                continue;
            }

            if (in_array($node['type'], NodeCatalog::UNAVAILABLE_TRIGGERS, true)) {
                $this->error('trigger_unavailable', $node['id']);
            }

            if ($node['type'] === 'goal' && is_array($node['config']['goal'] ?? null)) {
                $goals++;

                if ($goals > 1) {
                    $this->error('multiple_goals', $node['id']);
                }
            }

            $this->validateConfig($node);
        }

        $this->validateEdges($graph);

        if (count($triggers) === 1) {
            $this->validateReachability($graph, $triggers[0]['id']);
        }

        $this->validateLoops($graph);

        return $this->issues;
    }

    /** @param list<array{severity: string}> $issues */
    public static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }

    private function validateEdges(JourneyGraph $graph): void
    {
        $used = [];

        foreach ($graph->edges as $edge) {
            $source = $graph->node($edge['source']);
            $target = $graph->node($edge['target']);

            if ($source === null || $target === null || $edge['source'] === $edge['target']) {
                $this->error('invalid_edge', $source['id'] ?? null);

                continue;
            }

            // Triggerbe nem vezethet él: a trigger a belépés, nem lépés.
            if (NodeCatalog::isTrigger($target['type'])) {
                $this->error('invalid_edge', $target['id']);

                continue;
            }

            if (! in_array($edge['sourceHandle'], NodeCatalog::outputs($source['type'], $source['config']), true)) {
                $this->error('invalid_edge', $source['id']);

                continue;
            }

            // EGY kimenet EGY cél: a motor egy lépésre léptet, nem párhuzamosan többre.
            $key = $edge['source'].'|'.$edge['sourceHandle'];

            if (isset($used[$key])) {
                $this->error('duplicate_output', $source['id']);
            }

            $used[$key] = true;
        }

        foreach ($graph->nodes as $node) {
            if (! NodeCatalog::isKnown($node['type'])) {
                continue;
            }

            if (NodeCatalog::isTrigger($node['type']) && $graph->outgoing($node['id']) === []) {
                $this->error('trigger_unconnected', $node['id']);

                continue;
            }

            foreach (NodeCatalog::outputs($node['type'], $node['config']) as $handle) {
                if (! NodeCatalog::isTrigger($node['type']) && ! isset($used[$node['id'].'|'.$handle])) {
                    // A bekötetlen kimenet a futás VÉGE — legitim, de jelezni kell.
                    $this->warning('unconnected_output', $node['id'], $handle);
                }
            }
        }
    }

    /** ÁRVA csomópont: a triggerből nem érhető el. */
    private function validateReachability(JourneyGraph $graph, string $start): void
    {
        $reached = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ($graph->outgoing($id) as $edge) {
                if (! isset($reached[$edge['target']]) && $graph->node($edge['target']) !== null) {
                    $reached[$edge['target']] = true;
                    $queue[] = $edge['target'];
                }
            }
        }

        foreach ($graph->nodes as $node) {
            // A FELTÉTELES cél-csomópont bekötetlenül is értelmes: a futás bármely pontján
            // kiléptet, ha a feltétel teljesül (a journey cél-ellenőrzése) — nem árva.
            if ($node['type'] === 'goal' && is_array($node['config']['goal'] ?? null)) {
                continue;
            }

            if (! isset($reached[$node['id']]) && ! NodeCatalog::isTrigger($node['type'])) {
                $this->error('orphan_node', $node['id']);
            }
        }
    }

    /**
     * VÉGTELEN HUROK: kör, amelyben NINCS várakozás.
     *
     * A `wait`-es kör legitim ismétlődő automatizálás (heti emlékeztető) — a motor ott
     * felfüggeszt. A várakozás nélküli kör azonnal végrehajtható lépésekből áll, és a
     * lépés-korlátig égeti a workert. Ezért a `wait` csomópontokat kivesszük, és a maradékban
     * keresünk kört (háromszínű DFS, iteratívan).
     */
    private function validateLoops(JourneyGraph $graph): void
    {
        $adjacency = [];

        foreach ($graph->nodes as $node) {
            if ($node['type'] !== 'wait') {
                $adjacency[$node['id']] = [];
            }
        }

        foreach ($graph->edges as $edge) {
            if (isset($adjacency[$edge['source']], $adjacency[$edge['target']])) {
                $adjacency[$edge['source']][] = $edge['target'];
            }
        }

        $color = array_fill_keys(array_keys($adjacency), 0);
        $flagged = [];

        foreach (array_keys($adjacency) as $root) {
            if ($color[$root] !== 0) {
                continue;
            }

            $stack = [[$root, 0]];
            $color[$root] = 1;

            while ($stack !== []) {
                [$id, $index] = $stack[count($stack) - 1];

                if ($index < count($adjacency[$id])) {
                    $stack[count($stack) - 1][1]++;
                    $next = $adjacency[$id][$index];

                    if ($color[$next] === 1) {
                        if (! isset($flagged[$next])) {
                            $flagged[$next] = true;
                            $this->error('loop_without_wait', (string) $next);
                        }
                    } elseif ($color[$next] === 0) {
                        $color[$next] = 1;
                        $stack[] = [$next, 0];
                    }

                    continue;
                }

                $color[$id] = 2;
                array_pop($stack);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function validateConfig(array $node): void
    {
        $id = $node['id'];
        $config = $node['config'];

        match ($node['type']) {
            'list_subscribe' => $this->ref($id, $config, 'list', 'list'),
            'tag_added', 'tag_removed' => $this->ref($id, $config, 'tag', 'tag'),
            'field_changed' => $this->field($id, $config, 'field'),
            'date_anniversary' => $this->dateTrigger($id, $config),
            'form_signup' => $this->ref($id, $config, 'form', 'form', optional: true),
            'api_event' => $this->text($id, $config, 'event', 120),
            'campaign_interaction' => $this->campaignTrigger($id, $config),
            'segment_entered' => $this->ref($id, $config, 'segment', 'segment'),
            'inbound_email' => $this->ref($id, $config, 'route', 'route', optional: true),
            'send_email' => $this->sendEmail($id, $config),
            'wait' => $this->wait($id, $config),
            'condition' => $this->condition($id, is_array($config) ? $config : [], ''),
            'split' => $this->split($id, $config),
            'ab_split' => $this->abSplit($id, $config),
            'update_contact' => $this->updateContact($id, $config),
            'list_membership' => $this->listMembership($id, $config),
            'webhook' => $this->ref($id, $config, 'endpoint', 'endpoint'),
            'goal' => is_array($config['goal'] ?? null) ? $this->goal($id, $config['goal']) : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $config */
    private function sendEmail(string $id, array $config): void
    {
        $this->ref($id, $config, 'template', 'template');

        $from = trim((string) ($config['from_email'] ?? ''));

        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('config_invalid', $id, 'from_email');
        }

        if (mb_strlen((string) ($config['subject'] ?? '')) > 255) {
            $this->error('config_invalid', $id, 'subject');
        }
    }

    /** @param array<string, mixed> $config */
    private function wait(string $id, array $config): void
    {
        if (($config['mode'] ?? 'time') === 'event') {
            if (! in_array($config['event'] ?? null, NodeCatalog::WAIT_EVENTS, true)) {
                $this->error('config_required', $id, 'event');
            }

            $this->minutes($id, $config, 'timeout_minutes');

            return;
        }

        $this->minutes($id, $config, 'minutes');
    }

    /** @param array<string, mixed> $config */
    private function minutes(string $id, array $config, string $field): void
    {
        $value = $config[$field] ?? null;

        if (! is_numeric($value) || (int) $value < 1 || (int) $value > JourneyEngine::MAX_WAIT_MINUTES) {
            $this->error('config_required', $id, $field);
        }
    }

    /** @param array<string, mixed> $config */
    private function condition(string $id, array $config, string $prefix): void
    {
        $type = (string) ($config['type'] ?? '');

        match ($type) {
            'in_segment' => $this->ref($id, $config, 'segment', 'segment', field: $prefix.'segment'),
            'has_tag' => $this->ref($id, $config, 'tag', 'tag', field: $prefix.'tag'),
            'opened_email', 'clicked_email' => null,
            'context_equals' => $this->text($id, $config, 'key', 120, $prefix.'key'),
            'field_equals' => $this->field($id, $config, 'key', $prefix.'key'),
            default => $this->error('config_required', $id, $prefix.'type'),
        };
    }

    /** @param array<string, mixed> $config */
    private function goal(string $id, array $config): void
    {
        $type = (string) ($config['type'] ?? '');

        if (! in_array($type, NodeCatalog::GOAL_TYPES, true)) {
            $this->error('config_required', $id, 'goal.type');

            return;
        }

        $this->condition($id, $config, 'goal.');
    }

    /** @param array<string, mixed> $config */
    private function split(string $id, array $config): void
    {
        $branches = array_values(array_filter((array) ($config['branches'] ?? []), 'is_array'));

        if ($branches === [] || count($branches) > NodeCatalog::MAX_SPLIT_BRANCHES) {
            $this->error('config_required', $id, 'branches');

            return;
        }

        $keys = [];

        foreach ($branches as $index => $branch) {
            $key = (string) ($branch['key'] ?? '');

            if (preg_match('/^[a-z0-9_]{1,16}$/', $key) !== 1 || $key === 'else' || isset($keys[$key])) {
                $this->error('config_invalid', $id, 'branches.'.$index.'.key');
            }

            $keys[$key] = true;
            $this->condition($id, is_array($branch['condition'] ?? null) ? $branch['condition'] : [], 'branches.'.$index.'.');
        }
    }

    /** @param array<string, mixed> $config */
    private function abSplit(string $id, array $config): void
    {
        $variants = array_values(array_filter((array) ($config['variants'] ?? []), 'is_array'));

        if (count($variants) < 2 || count($variants) > NodeCatalog::MAX_AB_VARIANTS) {
            $this->error('ab_weights_invalid', $id, 'variants');

            return;
        }

        $keys = [];
        $total = 0;

        foreach ($variants as $variant) {
            $key = (string) ($variant['key'] ?? '');
            $weight = $variant['weight'] ?? null;

            if (preg_match('/^[a-z0-9_]{1,16}$/', $key) !== 1 || isset($keys[$key])
                || ! is_numeric($weight) || (int) $weight < 1 || (int) $weight > 100) {
                $this->error('ab_weights_invalid', $id, 'variants');

                return;
            }

            $keys[$key] = true;
            $total += (int) $weight;
        }

        if ($total !== 100) {
            $this->error('ab_weights_invalid', $id, 'variants');
        }
    }

    /** @param array<string, mixed> $config */
    private function updateContact(string $id, array $config): void
    {
        match ((string) ($config['operation'] ?? '')) {
            'set_field' => $this->setField($id, $config),
            'add_tag', 'remove_tag' => $this->ref($id, $config, 'tag', 'tag'),
            default => $this->error('config_required', $id, 'operation'),
        };
    }

    /** @param array<string, mixed> $config */
    private function setField(string $id, array $config): void
    {
        $this->field($id, $config, 'field');

        if (mb_strlen((string) ($config['value'] ?? '')) > 500) {
            $this->error('config_invalid', $id, 'value');
        }
    }

    /** @param array<string, mixed> $config */
    private function listMembership(string $id, array $config): void
    {
        if (! in_array($config['operation'] ?? null, ['add', 'remove'], true)) {
            $this->error('config_required', $id, 'operation');
        }

        $this->ref($id, $config, 'list', 'list');
    }

    /** @param array<string, mixed> $config */
    private function dateTrigger(string $id, array $config): void
    {
        $this->field($id, $config, 'field');

        if (! in_array($config['mode'] ?? 'anniversary', NodeCatalog::DATE_MODES, true)) {
            $this->error('config_invalid', $id, 'mode');
        }

        $offset = $config['offset_days'] ?? 0;

        if (! is_numeric($offset) || abs((int) $offset) > 365) {
            $this->error('config_invalid', $id, 'offset_days');
        }
    }

    /** @param array<string, mixed> $config */
    private function campaignTrigger(string $id, array $config): void
    {
        if (! in_array($config['interaction'] ?? null, ['open', 'click'], true)) {
            $this->error('config_required', $id, 'interaction');
        }

        $this->ref($id, $config, 'campaign', 'campaign', optional: true);
    }

    /** @param array<string, mixed> $config */
    private function text(string $id, array $config, string $key, int $max, ?string $field = null): void
    {
        $value = trim((string) ($config[$key] ?? ''));

        if ($value === '' || mb_strlen($value) > $max) {
            $this->error('config_required', $id, $field ?? $key);
        }
    }

    /** @param array<string, mixed> $config */
    private function field(string $id, array $config, string $key, ?string $field = null): void
    {
        $value = trim((string) ($config[$key] ?? ''));

        if ($value === '') {
            $this->error('config_required', $id, $field ?? $key);

            return;
        }

        if (! in_array($value, self::BUILTIN_FIELDS, true) && ! isset($this->known['field'][$value])) {
            $this->error('reference_missing', $id, $field ?? $key);
        }
    }

    /** @param array<string, mixed> $config */
    private function ref(string $id, array $config, string $key, string $kind, bool $optional = false, ?string $field = null): void
    {
        $value = trim((string) ($config[$key] ?? ''));

        if ($value === '') {
            if (! $optional) {
                $this->error('config_required', $id, $field ?? $key);
            }

            return;
        }

        if (! isset($this->known[$kind][strtolower($value)])) {
            $this->error('reference_missing', $id, $field ?? $key);
        }
    }

    /**
     * A hivatkozott uuid-ok EGY lekérdezéssel típusonként — a global scope a tenant-határ.
     *
     * @return array<string, array<string, true>>
     */
    private function loadReferences(JourneyGraph $graph): array
    {
        $wanted = ['template' => [], 'list' => [], 'tag' => [], 'segment' => [], 'form' => [], 'endpoint' => [], 'campaign' => [], 'route' => []];
        $collect = function (string $kind, mixed $value) use (&$wanted): void {
            $value = strtolower(trim((string) $value));

            if (Str::isUuid($value)) {
                $wanted[$kind][$value] = true;
            }
        };

        foreach ($graph->nodes as $node) {
            $config = $node['config'];

            foreach (['template' => 'template', 'list' => 'list', 'tag' => 'tag', 'segment' => 'segment', 'form' => 'form', 'endpoint' => 'endpoint', 'campaign' => 'campaign', 'route' => 'route'] as $key => $kind) {
                $collect($kind, $config[$key] ?? null);
            }

            foreach ((array) ($config['branches'] ?? []) as $branch) {
                if (is_array($branch) && is_array($branch['condition'] ?? null)) {
                    $collect('segment', $branch['condition']['segment'] ?? null);
                    $collect('tag', $branch['condition']['tag'] ?? null);
                }
            }

            if (is_array($config['goal'] ?? null)) {
                $collect('segment', $config['goal']['segment'] ?? null);
                $collect('tag', $config['goal']['tag'] ?? null);
            }
        }

        $models = [
            'template' => Template::class, 'list' => ContactList::class, 'tag' => ContactTag::class,
            'segment' => Segment::class, 'form' => Form::class, 'endpoint' => WebhookEndpoint::class,
            'campaign' => Campaign::class, 'route' => InboundRoute::class,
        ];

        $known = [];

        foreach ($models as $kind => $model) {
            $known[$kind] = $wanted[$kind] === []
                ? []
                : array_fill_keys(
                    array_map('strtolower', $model::query()
                        ->whereIn('uuid', array_keys($wanted[$kind]))
                        // A KIKAPCSOLT webhook-végpont nem cél: a kézbesítés úgyis elmaradna.
                        ->when($kind === 'endpoint', fn ($query) => $query->where('active', true))
                        ->get()
                        // A végpontnak a `journey.step` eseményre FEL KELL iratkoznia (review SEC-S2).
                        ->filter(fn ($row) => $kind !== 'endpoint' || $row->listensTo('journey.step'))
                        ->pluck('uuid')->map(fn ($uuid) => (string) $uuid)->all()),
                    true,
                );
        }

        $known['field'] = array_fill_keys(ContactField::query()->pluck('key')->map(fn ($key) => (string) $key)->all(), true);

        return $known;
    }

    private function error(string $code, ?string $node = null, ?string $field = null): void
    {
        $this->push('error', $code, $node, $field);
    }

    private function warning(string $code, ?string $node = null, ?string $field = null): void
    {
        $this->push('warning', $code, $node, $field);
    }

    private function push(string $severity, string $code, ?string $node, ?string $field): void
    {
        $issue = ['code' => $code, 'severity' => $severity];

        if ($node !== null) {
            $issue['node'] = $node;
        }

        if ($field !== null) {
            $issue['field'] = $field;
        }

        $this->issues[] = $issue;
    }
}
