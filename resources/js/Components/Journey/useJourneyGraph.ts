import type { Connection, Edge, Node } from '@vue-flow/core';
import { computed, ref, type Ref } from 'vue';
import { defaultConfig, isTrigger, newId, outputs, type Config, type Issue, type JGraph } from './catalog';

/*
 | A JOURNEY-VÁSZON GRÁF-ÁLLAPOTA (v1.1 · 6. modul) — a `Canvas.vue`-ból kiemelve, hogy a
 | tiszta logika (csomópontok, élek, kapcsolódási szabályok, szerializálás) unit-tesztelhető
 | legyen. A vászon csak a megjelenítést, a Vue Flow eseményeit és a szerver-hívásokat viszi.
 |
 | Szabályok (a szerver `JourneyGraphValidator`-a UGYANEZT validálja — a kliens csak elébe megy):
 | - egy journey-nek EGY triggere lehet; a „hamarosan" (még nem bekötött) trigger nem tehető le;
 | - trigger nem lehet él célja, és csomópont nem köthető önmagába;
 | - EGY kimenet EGY cél: az új él lecseréli a kimenet régi élét;
 | - egy kimenet megszűnésekor (pl. törölt ág) az éle is megy.
 */

export type FlowData = { kind: string; label: string; config: Config };

export type AddResult = { ok: true; id: string } | { ok: false; reason: 'oneTrigger' | 'unavailable' };

const GRID = 16;

export function toFlowEdge(id: string, source: string, sourceHandle: string, target: string): Edge {
    return { id, source, sourceHandle, target, targetHandle: 'in', type: 'smoothstep', class: 'vm-jedge' };
}

export function useJourneyGraph(graph: JGraph, options: { unavailable?: string[]; issues?: Issue[] } = {}) {
    const unavailable = options.unavailable ?? [];

    // `Ref` kasztolással: a Vue Flow `Node` típusának mély `UnwrapRef`-je a tsc-t végtelen
    // példányosításba vinné (TS2589).
    const nodes = ref(
        graph.nodes.map((node) => ({
            id: node.id,
            type: 'journey',
            position: { ...node.position },
            data: { kind: node.type, label: node.label, config: { ...node.config } },
        })),
    ) as Ref<Node<FlowData>[]>;

    const edges = ref(graph.edges.map((edge) => toFlowEdge(edge.id, edge.source, edge.sourceHandle, edge.target))) as Ref<Edge[]>;

    const selectedId = ref<string | null>(null);
    const changed = ref(false);
    const saveState = ref<'saved' | 'dirty' | 'saving' | 'error'>('saved');
    const testPath = ref<string[]>([]);
    const issues = ref<Issue[]>(options.issues ?? []);

    const selected = computed(() => nodes.value.find((node) => node.id === selectedId.value) ?? null);
    const hasTrigger = computed(() => nodes.value.some((node) => isTrigger(node.data?.kind ?? '')));
    const errorCount = computed(() => issues.value.filter((issue) => issue.severity === 'error').length);
    const warningCount = computed(() => issues.value.length - errorCount.value);
    const nodeIssues = computed(() => issues.value.filter((issue) => issue.node === selectedId.value));

    /** A szerver `JourneyGraph` alakja (mentés, validálás, publikálás, teszt-futtatás). */
    function graphPayload(): JGraph {
        return {
            nodes: nodes.value.map((node) => ({
                id: node.id,
                type: node.data?.kind ?? '',
                position: { x: Math.round(node.position.x), y: Math.round(node.position.y) },
                label: node.data?.label ?? '',
                config: node.data?.config ?? {},
            })),
            edges: edges.value.map((edge) => ({ id: edge.id, source: edge.source, sourceHandle: edge.sourceHandle ?? 'out', target: edge.target })),
        };
    }

    function touch(): void {
        changed.value = true;
        saveState.value = 'dirty';
        testPath.value = [];
    }

    /** Rácsra illeszt, és jobbra tolja (a folyam iránya), amíg egy meglévő csomópontra esne. */
    function clear(point: { x: number; y: number }): { x: number; y: number } {
        let x = Math.round(point.x / GRID) * GRID;
        const y = Math.round(point.y / GRID) * GRID;

        while (nodes.value.some((node) => Math.abs(node.position.x - x) < 15 * GRID && Math.abs(node.position.y - y) < 7 * GRID)) {
            x += 18 * GRID;
        }

        return { x, y };
    }

    /** Új csomópont. A hely lehet függvény is — csak akkor számolódik, ha a csomópont tényleg létrejön. */
    function addNode(type: string, position: { x: number; y: number } | (() => { x: number; y: number })): AddResult {
        if (isTrigger(type) && unavailable.includes(type)) {
            return { ok: false, reason: 'unavailable' };
        }

        if (isTrigger(type) && hasTrigger.value) {
            return { ok: false, reason: 'oneTrigger' };
        }

        const taken = new Set(nodes.value.map((node) => node.id));
        const id = isTrigger(type) && !taken.has('trigger') ? 'trigger' : newId(type.replace(/_/g, '-'), taken);
        const at = typeof position === 'function' ? position() : position;

        nodes.value = [...nodes.value, { id, type: 'journey', position: at, data: { kind: type, label: '', config: defaultConfig(type) } }];
        selectedId.value = id;
        touch();

        return { ok: true, id };
    }

    function updateNode(id: string, patch: Partial<FlowData>): void {
        nodes.value = nodes.value.map((node) => (node.id === id && node.data ? { ...node, data: { ...node.data, ...patch } } : node));

        // A megszűnt kimenetek (pl. törölt ág) éle is megy.
        const node = nodes.value.find((item) => item.id === id);

        if (node?.data) {
            const valid = new Set(outputs(node.data.kind, node.data.config));

            edges.value = edges.value.filter((edge) => edge.source !== id || valid.has(edge.sourceHandle ?? 'out'));
        }

        touch();
    }

    function removeNode(id: string): void {
        nodes.value = nodes.value.filter((node) => node.id !== id);
        edges.value = edges.value.filter((edge) => edge.source !== id && edge.target !== id);

        if (selectedId.value === id) selectedId.value = null;

        touch();
    }

    function duplicateNode(id: string): void {
        const source = nodes.value.find((node) => node.id === id);

        if (!source?.data || isTrigger(source.data.kind)) return;

        const taken = new Set(nodes.value.map((node) => node.id));
        const copy = newId(source.data.kind.replace(/_/g, '-'), taken);

        nodes.value = [
            ...nodes.value,
            { id: copy, type: 'journey', position: { x: source.position.x + 32, y: source.position.y + 96 }, data: { ...source.data, config: JSON.parse(JSON.stringify(source.data.config)) } },
        ];
        selectedId.value = copy;
        touch();
    }

    /** EGY kimenet EGY cél: az új él lecseréli a kimenet régi élét. `null` cél = a kimenet bontása. */
    function connect(source: string, handle: string, target: string | null): void {
        edges.value = edges.value.filter((edge) => !(edge.source === source && (edge.sourceHandle ?? 'out') === handle));

        if (target && target !== source) {
            edges.value = [...edges.value, toFlowEdge(`e-${source}-${handle}-${target}`, source, handle, target)];
        }

        touch();
    }

    /** A vászon húzásos kapcsolata: triggerbe nem köthető él. */
    function onConnect(connection: Connection): boolean {
        const target = nodes.value.find((node) => node.id === connection.target);

        if (!target?.data || isTrigger(target.data.kind)) return false;

        connect(connection.source, connection.sourceHandle ?? 'out', connection.target);

        return true;
    }

    /** A kijelölt csomópont kimenetei → célok (a jobb panel választóihoz). */
    const connections = computed<Record<string, string>>(() => {
        const map: Record<string, string> = {};

        for (const edge of edges.value) {
            if (edge.source === selectedId.value) map[edge.sourceHandle ?? 'out'] = edge.target;
        }

        return map;
    });

    /** A kijelöltből elérhető célok: minden más, nem-trigger csomópont. */
    const targetIds = computed(() => nodes.value.filter((node) => node.id !== selectedId.value && node.data && !isTrigger(node.data.kind)).map((node) => node.id));

    return {
        nodes,
        edges,
        selectedId,
        selected,
        changed,
        saveState,
        testPath,
        issues,
        hasTrigger,
        errorCount,
        warningCount,
        nodeIssues,
        connections,
        targetIds,
        graphPayload,
        touch,
        clear,
        addNode,
        updateNode,
        removeNode,
        duplicateNode,
        connect,
        onConnect,
    };
}

export type JourneyGraphApi = ReturnType<typeof useJourneyGraph>;
