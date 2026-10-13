<?php

declare(strict_types=1);

namespace App\Modules\Journey\Graph;

use InvalidArgumentException;

/**
 * A JOURNEY-GRÁF (v1.1 · 6. modul, D5) — a szerkesztő dokumentuma, normalizálva.
 *
 * A böngészőből érkező JSON-ból CSAK az ismert mezők maradnak meg, korlátos méretben: a gráf
 * a `journeys.graph` jsonb-be, publikáláskor a megváltoztathatatlan `journey_versions`-be
 * kerül, tehát egy tetszőleges, megabájtos payload ott ragadna örökre.
 *
 * Szerkezet:
 *   nodes: [{id, type, position: {x, y}, label, config}]
 *   edges: [{id, source, sourceHandle, target}]
 */
final class JourneyGraph
{
    public const MAX_NODES = 150;

    public const MAX_EDGES = 300;

    /** Egy csomópont konfigurációjának JSON-mérete (bájt). */
    public const MAX_CONFIG_BYTES = 4096;

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @param  list<array{id: string, type: string, position: array{x: float, y: float}, label: string, config: array<string, mixed>}>  $nodes
     * @param  list<array{id: string, source: string, sourceHandle: string, target: string}>  $edges
     */
    private function __construct(public readonly array $nodes, public readonly array $edges) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param  array<string, mixed>|null  $raw
     *
     * @throws InvalidArgumentException szerkezeti hibánál (méret, azonosító, duplikátum)
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return self::empty();
        }

        $rawNodes = array_values(array_filter((array) ($raw['nodes'] ?? []), 'is_array'));
        $rawEdges = array_values(array_filter((array) ($raw['edges'] ?? []), 'is_array'));

        if (count($rawNodes) > self::MAX_NODES) {
            throw new InvalidArgumentException('A journey legfeljebb '.self::MAX_NODES.' csomópontot tartalmazhat.');
        }

        if (count($rawEdges) > self::MAX_EDGES) {
            throw new InvalidArgumentException('A journey legfeljebb '.self::MAX_EDGES.' élt tartalmazhat.');
        }

        $nodes = [];
        $seen = [];

        foreach ($rawNodes as $node) {
            $id = (string) ($node['id'] ?? '');

            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new InvalidArgumentException('Érvénytelen csomópont-azonosító.');
            }

            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Ismétlődő csomópont-azonosító: '.$id);
            }

            $seen[$id] = true;

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            if (strlen((string) json_encode($config)) > self::MAX_CONFIG_BYTES) {
                throw new InvalidArgumentException('A csomópont konfigurációja túl nagy: '.$id);
            }

            $nodes[] = [
                'id' => $id,
                'type' => mb_substr((string) ($node['type'] ?? ''), 0, 40),
                'position' => [
                    'x' => self::coordinate($node['position']['x'] ?? 0),
                    'y' => self::coordinate($node['position']['y'] ?? 0),
                ],
                'label' => mb_substr(trim((string) ($node['label'] ?? '')), 0, 120),
                'config' => $config,
            ];
        }

        $edges = [];
        $edgeIds = [];

        foreach ($rawEdges as $edge) {
            $id = (string) ($edge['id'] ?? '');
            $handle = (string) ($edge['sourceHandle'] ?? 'out');

            if (preg_match(self::ID_PATTERN, $id) !== 1 || preg_match(self::ID_PATTERN, $handle) !== 1) {
                throw new InvalidArgumentException('Érvénytelen él-azonosító.');
            }

            if (isset($edgeIds[$id])) {
                continue;
            }

            $edgeIds[$id] = true;

            $edges[] = [
                'id' => $id,
                'source' => mb_substr((string) ($edge['source'] ?? ''), 0, 64),
                'sourceHandle' => $handle,
                'target' => mb_substr((string) ($edge['target'] ?? ''), 0, 64),
            ];
        }

        return new self($nodes, $edges);
    }

    private static function coordinate(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return round(max(-100000, min(100000, $number)), 1);
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['nodes' => $this->nodes, 'edges' => $this->edges];
    }

    /** A VÉGREHAJTÁST meghatározó tartalom lenyomata — a pozíció és a felirat nem számít. */
    public function hash(): string
    {
        $nodes = array_map(static fn (array $node): array => [$node['id'], $node['type'], $node['config']], $this->nodes);
        $edges = array_map(static fn (array $edge): array => [$edge['source'], $edge['sourceHandle'], $edge['target']], $this->edges);

        usort($nodes, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        usort($edges, static fn (array $a, array $b): int => strcmp(implode('|', $a), implode('|', $b)));

        return hash('sha256', (string) json_encode(self::sortKeys([$nodes, $edges])));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }

    /** @return array<string, mixed>|null */
    public function node(string $id): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function triggers(): array
    {
        return array_values(array_filter($this->nodes, static fn (array $node): bool => NodeCatalog::isTrigger($node['type'])));
    }

    /** @return list<array<string, mixed>> */
    public function outgoing(string $id): array
    {
        return array_values(array_filter($this->edges, static fn (array $edge): bool => $edge['source'] === $id));
    }

    /** @return list<array<string, mixed>> */
    public function incoming(string $id): array
    {
        return array_values(array_filter($this->edges, static fn (array $edge): bool => $edge['target'] === $id));
    }

    /** A kimenet célja (az első él) — vagy `null`. */
    public function target(string $id, string $handle): ?string
    {
        foreach ($this->edges as $edge) {
            if ($edge['source'] === $id && $edge['sourceHandle'] === $handle) {
                return $edge['target'];
            }
        }

        return null;
    }
}
