import { describe, expect, it, vi } from 'vitest';
import type { JGraph } from '../../../resources/js/Components/Journey/catalog';
import { useJourneyGraph } from '../../../resources/js/Components/Journey/useJourneyGraph';

/*
 | A journey-vászon gráf-állapota: csomópont hozzáadás / törlés / duplikálás, élek és
 | kapcsolódási szabályok, a „hamarosan" triggerek elutasítása, az ág-kimenetek élei,
 | a hibalista számlálása és a szerver felé menő `JourneyGraph` szerializálás.
 */

// A szerver `NodeCatalog::UNAVAILABLE_TRIGGERS` értéke (a vászon a `catalog.unavailableTriggers`-ből kapja).
const UNAVAILABLE = ['tag_added', 'tag_removed', 'field_changed'];

function graph(): JGraph {
    return {
        nodes: [
            { id: 'trigger', type: 'list_subscribe', position: { x: 0, y: 0 }, label: '', config: { list: 'l1' } },
            { id: 'cond', type: 'condition', position: { x: 288, y: 0 }, label: 'Opened?', config: { type: 'opened_email', within_hours: 168 } },
            { id: 'mail', type: 'send_email', position: { x: 576, y: -112 }, label: '', config: { template: 't1' } },
            { id: 'done', type: 'exit', position: { x: 576, y: 112 }, label: '', config: {} },
        ],
        edges: [
            { id: 'e1', source: 'trigger', sourceHandle: 'out', target: 'cond' },
            { id: 'e2', source: 'cond', sourceHandle: 'yes', target: 'mail' },
            { id: 'e3', source: 'cond', sourceHandle: 'no', target: 'done' },
        ],
    };
}

function emptyGraph(): JGraph {
    return { nodes: [], edges: [] };
}

describe('betöltés és szerializálás', () => {
    it('a szerver gráfja oda-vissza változatlan (Vue Flow alakon át)', () => {
        const g = useJourneyGraph(graph());

        expect(g.nodes.value[1]).toMatchObject({ id: 'cond', type: 'journey', data: { kind: 'condition', label: 'Opened?' } });
        expect(g.edges.value[1]).toMatchObject({ source: 'cond', sourceHandle: 'yes', target: 'mail', targetHandle: 'in' });
        expect(g.graphPayload()).toEqual(graph());
        expect(g.changed.value).toBe(false);
        expect(g.saveState.value).toBe('saved');
    });

    it('a pozíció egészre kerekítve megy', () => {
        const g = useJourneyGraph(graph());

        g.nodes.value[0].position = { x: 10.4, y: 20.6 };

        expect(g.graphPayload().nodes[0].position).toEqual({ x: 10, y: 21 });
    });

    it('a betöltött konfiguráció másolat — a szerkesztés nem írja a props-ot', () => {
        const source = graph();
        const g = useJourneyGraph(source);

        g.updateNode('mail', { config: { template: 't2' } });

        expect(source.nodes[2].config).toEqual({ template: 't1' });
    });
});

describe('csomópont hozzáadása', () => {
    it('az első trigger a `trigger` azonosítót kapja, alap-konfigurációval, kijelölve', () => {
        const g = useJourneyGraph(emptyGraph(), { unavailable: UNAVAILABLE });

        const result = g.addNode('form_signup', { x: 0, y: 0 });

        expect(result).toEqual({ ok: true, id: 'trigger' });
        expect(g.selectedId.value).toBe('trigger');
        expect(g.hasTrigger.value).toBe(true);
        expect(g.changed.value).toBe(true);
        expect(g.saveState.value).toBe('dirty');
    });

    it('egy journey-nek EGY triggere lehet', () => {
        const g = useJourneyGraph(graph(), { unavailable: UNAVAILABLE });

        expect(g.addNode('api_event', { x: 0, y: 0 })).toEqual({ ok: false, reason: 'oneTrigger' });
        expect(g.nodes.value).toHaveLength(4);
        expect(g.changed.value).toBe(false);
    });

    it('a „hamarosan" (nem bekötött) trigger elutasítva — üres vásznon is', () => {
        const g = useJourneyGraph(emptyGraph(), { unavailable: UNAVAILABLE });
        const position = vi.fn(() => ({ x: 0, y: 0 }));

        for (const type of UNAVAILABLE) {
            expect(g.addNode(type, position)).toEqual({ ok: false, reason: 'unavailable' });
        }
        expect(g.nodes.value).toHaveLength(0);
        expect(position).not.toHaveBeenCalled();
    });

    it('akció-csomópont: típus-prefixes, ütközésmentes azonosító és típusonkénti alap-konfiguráció', () => {
        const g = useJourneyGraph(graph());

        const wait = g.addNode('wait', { x: 0, y: 400 });
        const split = g.addNode('ab_split', () => ({ x: 400, y: 400 }));

        expect(wait.ok && wait.id).toMatch(/^wait-[a-z0-9]+$/);
        expect(split.ok && split.id).toMatch(/^ab-split-[a-z0-9]+$/);
        expect(g.nodes.value.find((node) => node.data?.kind === 'wait')?.data?.config).toEqual({ minutes: 1440 });
        expect(g.nodes.value.find((node) => node.data?.kind === 'ab_split')?.position).toEqual({ x: 400, y: 400 });
        expect(new Set(g.nodes.value.map((node) => node.id)).size).toBe(6);
    });

    it('a hely rácsra illeszkedik, és jobbra tolódik, amíg csomópontra esne', () => {
        const g = useJourneyGraph(graph());

        expect(g.clear({ x: 1000, y: 1000 })).toEqual({ x: 1008, y: 1008 });
        // A (0,0)-n a trigger ül, a (288,0)-n a feltétel → két lépés jobbra.
        expect(g.clear({ x: 5, y: 3 })).toEqual({ x: 576, y: 0 });
    });
});

describe('csomópont törlése és duplikálása', () => {
    it('a törlés a be- és kimenő éleket is viszi, és a kijelölést bontja', () => {
        const g = useJourneyGraph(graph());

        g.selectedId.value = 'cond';
        g.removeNode('cond');

        expect(g.nodes.value.map((node) => node.id)).toEqual(['trigger', 'mail', 'done']);
        expect(g.edges.value).toEqual([]);
        expect(g.selectedId.value).toBeNull();
    });

    it('a duplikátum új azonosítót, eltolt helyet és FÜGGETLEN konfigurációt kap, élek nélkül', () => {
        const g = useJourneyGraph(graph());

        g.duplicateNode('cond');
        const copy = g.nodes.value[4];

        expect(copy.id).not.toBe('cond');
        expect(copy.position).toEqual({ x: 320, y: 96 });
        expect(copy.data).toMatchObject({ kind: 'condition', label: 'Opened?' });
        expect(g.selectedId.value).toBe(copy.id);
        expect(g.edges.value.some((edge) => edge.source === copy.id || edge.target === copy.id)).toBe(false);

        (copy.data!.config as { within_hours: number }).within_hours = 1;
        expect(g.nodes.value[1].data?.config.within_hours).toBe(168);
    });

    it('a trigger nem duplikálható', () => {
        const g = useJourneyGraph(graph());

        g.duplicateNode('trigger');

        expect(g.nodes.value).toHaveLength(4);
        expect(g.changed.value).toBe(false);
    });
});

describe('élek és kapcsolódási szabályok', () => {
    it('EGY kimenet EGY cél: az új él lecseréli a kimenet régi élét', () => {
        const g = useJourneyGraph(graph());

        g.connect('cond', 'yes', 'done');

        const yes = g.edges.value.filter((edge) => edge.source === 'cond' && edge.sourceHandle === 'yes');
        expect(yes).toHaveLength(1);
        expect(yes[0]).toMatchObject({ id: 'e-cond-yes-done', target: 'done' });
        expect(g.edges.value.find((edge) => edge.sourceHandle === 'no')?.target).toBe('done');
    });

    it('`null` cél a kimenet bontása; önmagába kötés nem jön létre', () => {
        const g = useJourneyGraph(graph());

        g.connect('cond', 'no', null);
        expect(g.edges.value.some((edge) => edge.source === 'cond' && edge.sourceHandle === 'no')).toBe(false);

        g.connect('mail', 'out', 'mail');
        expect(g.edges.value.some((edge) => edge.source === 'mail')).toBe(false);
    });

    it('húzásos kapcsolat: triggerbe és nem létező csomópontba nem köthető', () => {
        const g = useJourneyGraph(graph());

        expect(g.onConnect({ source: 'mail', sourceHandle: 'out', target: 'trigger', targetHandle: 'in' })).toBe(false);
        expect(g.onConnect({ source: 'mail', sourceHandle: null, target: 'ghost', targetHandle: null })).toBe(false);
        expect(g.edges.value).toHaveLength(3);

        expect(g.onConnect({ source: 'mail', sourceHandle: null, target: 'done', targetHandle: 'in' })).toBe(true);
        expect(g.edges.value.at(-1)).toMatchObject({ source: 'mail', sourceHandle: 'out', target: 'done' });
    });

    it('a kijelölt csomópont kimenet → cél térképe és a választható célok (trigger és önmaga nélkül)', () => {
        const g = useJourneyGraph(graph());

        g.selectedId.value = 'cond';

        expect(g.connections.value).toEqual({ yes: 'mail', no: 'done' });
        expect(g.targetIds.value).toEqual(['mail', 'done']);
    });

    it('a megszűnt kimenet (törölt ág) éle is megy, a megmaradóé marad', () => {
        const g = useJourneyGraph({
            nodes: [
                { id: 'split', type: 'split', position: { x: 0, y: 0 }, label: '', config: { branches: [{ key: 'b1', label: 'VIP' }, { key: 'b2', label: 'Rest' }] } },
                { id: 'a', type: 'exit', position: { x: 300, y: 0 }, label: '', config: {} },
                { id: 'b', type: 'exit', position: { x: 300, y: 200 }, label: '', config: {} },
            ],
            edges: [
                { id: 'x1', source: 'split', sourceHandle: 'b1', target: 'a' },
                { id: 'x2', source: 'split', sourceHandle: 'b2', target: 'b' },
                { id: 'x3', source: 'split', sourceHandle: 'else', target: 'b' },
            ],
        });

        g.updateNode('split', { config: { branches: [{ key: 'b1', label: 'VIP' }] } });

        expect(g.edges.value.map((edge) => edge.sourceHandle)).toEqual(['b1', 'else']);
    });

    it('a várakozás eseményre kapja az időtúllépés-kimenetet; visszaváltva az éle megy', () => {
        const g = useJourneyGraph(graph());
        const wait = g.addNode('wait', { x: 0, y: 600 });
        const id = wait.ok ? wait.id : '';

        g.updateNode(id, { config: { mode: 'event', event: 'opened', timeout_minutes: 60 } });
        g.connect(id, 'timeout', 'done');
        expect(g.edges.value.some((edge) => edge.source === id && edge.sourceHandle === 'timeout')).toBe(true);

        g.updateNode(id, { config: { minutes: 60 } });
        expect(g.edges.value.some((edge) => edge.source === id && edge.sourceHandle === 'timeout')).toBe(false);
    });

    it('minden módosítás piszkossá tesz és törli a teszt-futás kiemelt útját', () => {
        const g = useJourneyGraph(graph());

        g.testPath.value = ['trigger', 'cond'];
        g.connect('cond', 'yes', 'done');

        expect(g.testPath.value).toEqual([]);
        expect(g.saveState.value).toBe('dirty');
    });
});

describe('validálási hibalista', () => {
    it('hibák és figyelmeztetések számlálása, csomópontonkénti szűrés', () => {
        const g = useJourneyGraph(graph(), {
            issues: [
                { code: 'trigger_unavailable', severity: 'error', node: 'trigger' },
                { code: 'output_unconnected', severity: 'error', node: 'mail' },
                { code: 'no_goal', severity: 'warning' },
            ],
        });

        expect(g.errorCount.value).toBe(2);
        expect(g.warningCount.value).toBe(1);

        g.selectedId.value = 'trigger';
        expect(g.nodeIssues.value.map((issue) => issue.code)).toEqual(['trigger_unavailable']);

        g.issues.value = [];
        expect(g.errorCount.value).toBe(0);
    });
});
