<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MiniMap } from '@vue-flow/minimap';
import { Background } from '@vue-flow/background';
import { VueFlow, useVueFlow, type Connection, type Edge, type Node, type NodeMouseEvent } from '@vue-flow/core';
import '@vue-flow/core/dist/style.css';
import { VtBadge, VtButton, VtInput } from '@venture/ui';
import axios, { AxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, provide, ref, watch, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import JourneyNode from '../../Components/Journey/JourneyNode.vue';
import NodeConfigPanel from '../../Components/Journey/NodeConfigPanel.vue';
import NodePalette from '../../Components/Journey/NodePalette.vue';
import TestRunPanel from '../../Components/Journey/TestRunPanel.vue';
import {
    CanvasKey,
    DRAG_MIME,
    defaultConfig,
    isTrigger,
    newId,
    outputs,
    type CatalogDef,
    type Config,
    type Issue,
    type JGraph,
    type NodeStat,
    type Options,
    type TestRunResult,
} from '../../Components/Journey/catalog';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import { useCompactNav } from '../../composables/useCompactNav';
import AppLayout from '../../Layouts/AppLayout.vue';
import '../../journey.css';

/*
 | A JOURNEY VÁSZON (v1.1 · 6. modul, D4/D5).
 |
 | Végtelen, zoomolható, pásztázható vászon (`@vue-flow/core`, MIT), minimap, rácsra illesztés.
 | Csomópont a palettáról húzással vagy kattintással; él a kimenetről a bemenetre húzással —
 | vagy a jobb panel „Kimenetek" választóival (billentyűzettel is bejárható).
 |
 | A VÁSZON ÁLLAPOTA a Vue Flow csomópont-listája (`data: {kind, label, config}`); mentéskor a
 | szerver `JourneyGraph` alakjára fordul. A validálás a szerveré — itt csak megjelenik.
 |
 | Szerkesztő mód (`<AppLayout editor-mode>`): kompakt felső sáv, mint a sablonszerkesztőnél.
 */
type FlowData = { kind: string; label: string; config: Config };

const props = defineProps<{
    journey: { uuid: string; name: string; status: string; version: number | null; dirty: boolean };
    graph: JGraph;
    catalog: CatalogDef;
    options: Options;
    issues: Issue[];
    stats: { available: boolean; nodes: Record<string, NodeStat> };
    versions: Array<{ number: number; publishedAt: string | null }>;
    can: { publish: boolean };
}>();

const { t } = useI18n();
const compact = useCompactNav();

const flowId = `journey-${props.journey.uuid}`;
const { project, zoomIn, zoomOut, fitView, viewport, vueFlowRef } = useVueFlow(flowId);

const name = ref(props.journey.name);
const status = ref(props.journey.status);
const version = ref(props.journey.version);
const unpublished = ref(props.journey.dirty);
const issues = ref<Issue[]>(props.issues);
// A STATISZTIKA csak publikált journey-nél jelenik meg — egy vázlaton a nullák zajt adnának.
const stats = ref<Record<string, NodeStat>>(props.journey.version === null ? {} : props.stats.nodes);
const options = ref<Options>(props.options);
const testPath = ref<string[]>([]);

provide(CanvasKey, { stats, issues, options, testPath });

// `Ref` kasztolással: a Vue Flow `Node` típusának mély `UnwrapRef`-je a tsc-t végtelen
// példányosításba vinné (TS2589).
const nodes = ref(
    props.graph.nodes.map((node) => ({
        id: node.id,
        type: 'journey',
        position: { ...node.position },
        data: { kind: node.type, label: node.label, config: { ...node.config } },
    })),
) as Ref<Node<FlowData>[]>;

const edges = ref(props.graph.edges.map((edge) => toFlowEdge(edge.id, edge.source, edge.sourceHandle, edge.target))) as Ref<Edge[]>;

const selectedId = ref<string | null>(null);
const changed = ref(false);
const saveState = ref<'saved' | 'dirty' | 'saving' | 'error'>('saved');
const notice = ref<{ tone: 'info' | 'danger'; text: string } | null>(null);
const issuesOpen = ref(false);
const busy = ref<'' | 'validate' | 'publish' | 'pause' | 'test'>('');
const testEmail = ref('');
const testResult = ref<TestRunResult | null>(null);

function toFlowEdge(id: string, source: string, sourceHandle: string, target: string): Edge {
    return { id, source, sourceHandle, target, targetHandle: 'in', type: 'smoothstep', class: 'vm-jedge' };
}

const selected = computed(() => nodes.value.find((node) => node.id === selectedId.value) ?? null);
const hasTrigger = computed(() => nodes.value.some((node) => isTrigger(node.data?.kind ?? '')));
const errorCount = computed(() => issues.value.filter((issue) => issue.severity === 'error').length);
const warningCount = computed(() => issues.value.length - errorCount.value);

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

/* ------------------------------------------------------------------ csomópontok */

function addNode(type: string, position?: { x: number; y: number }): void {
    if (isTrigger(type) && hasTrigger.value) {
        notice.value = { tone: 'danger', text: t('journeyCanvas.palette.oneTrigger') };

        return;
    }

    const taken = new Set(nodes.value.map((node) => node.id));
    const id = isTrigger(type) && !taken.has('trigger') ? 'trigger' : newId(type.replace(/_/g, '-'), taken);
    const at = position ?? nextFreePosition();

    nodes.value = [...nodes.value, { id, type: 'journey', position: at, data: { kind: type, label: '', config: defaultConfig(type) } }];
    selectedId.value = id;
    touch();
}

/** A kattintással hozzáadott csomópont helye: a kijelölt mellé jobbra, különben a látható közép. */
function nextFreePosition(): { x: number; y: number } {
    const anchor = selected.value;
    const grid = 16;
    let x: number;
    let y: number;

    if (anchor) {
        x = anchor.position.x + 18 * grid;
        y = anchor.position.y;
    } else {
        const rect = vueFlowRef.value?.getBoundingClientRect();
        const center = project({ x: (rect?.width ?? 600) / 2, y: (rect?.height ?? 400) / 2 });

        x = center.x;
        y = center.y;
    }

    return clear({ x, y });
}

/** Rácsra illeszt, és jobbra tolja (a folyam iránya), amíg egy meglévő csomópontra esne. */
function clear(point: { x: number; y: number }): { x: number; y: number } {
    const grid = 16;
    let x = Math.round(point.x / grid) * grid;
    const y = Math.round(point.y / grid) * grid;

    while (nodes.value.some((node) => Math.abs(node.position.x - x) < 15 * grid && Math.abs(node.position.y - y) < 7 * grid)) {
        x += 18 * grid;
    }

    return { x, y };
}

function onDragOver(event: DragEvent): void {
    if (event.dataTransfer?.types.includes(DRAG_MIME)) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }
}

function onDrop(event: DragEvent): void {
    const type = event.dataTransfer?.getData(DRAG_MIME);

    if (!type) return;

    event.preventDefault();

    const rect = vueFlowRef.value?.getBoundingClientRect();
    const point = project({ x: event.clientX - (rect?.left ?? 0), y: event.clientY - (rect?.top ?? 0) });

    addNode(type, clear(point));
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

/* ------------------------------------------------------------------ élek */

/** EGY kimenet EGY cél: az új él lecseréli a kimenet régi élét. */
function connect(source: string, handle: string, target: string | null): void {
    edges.value = edges.value.filter((edge) => !(edge.source === source && (edge.sourceHandle ?? 'out') === handle));

    if (target && target !== source) {
        edges.value = [...edges.value, toFlowEdge(`e-${source}-${handle}-${target}`, source, handle, target)];
    }

    touch();
}

function onConnect(connection: Connection): void {
    const target = nodes.value.find((node) => node.id === connection.target);

    if (!target?.data || isTrigger(target.data.kind)) return;

    connect(connection.source, connection.sourceHandle ?? 'out', connection.target);
}

function onNodeClick(event: NodeMouseEvent): void {
    selectedId.value = event.node.id;
}

function onNodesChange(changes: Array<{ type: string; id?: string; dragging?: boolean }>): void {
    for (const change of changes) {
        if (change.type === 'remove' && change.id) removeNode(change.id);
        if (change.type === 'position' && change.dragging === false) touch();
    }
}

function onEdgesChange(changes: Array<{ type: string; id?: string }>): void {
    if (changes.some((change) => change.type === 'remove')) touch();
}

const connections = computed<Record<string, string>>(() => {
    const map: Record<string, string> = {};

    for (const edge of edges.value) {
        if (edge.source === selectedId.value) map[edge.sourceHandle ?? 'out'] = edge.target;
    }

    return map;
});

const targets = computed(() =>
    nodes.value
        .filter((node) => node.id !== selectedId.value && node.data && !isTrigger(node.data.kind))
        .map((node) => ({ value: node.id, label: titleOf(node.id) })),
);

function titleOf(id: string | null): string {
    const node = nodes.value.find((item) => item.id === id);

    if (!node?.data) return id ?? '';

    return node.data.label || t(`journeyCanvas.nodes.${node.data.kind}`);
}

const nodeIssues = computed(() => issues.value.filter((issue) => issue.node === selectedId.value));

/* ------------------------------------------------------------------ szerver */

function failure(error: unknown): string {
    const response = (error as AxiosError<{ message?: string; issues?: Issue[] }>).response;

    if (response?.data?.issues) issues.value = response.data.issues;

    return response?.data?.message ?? t('journeyCanvas.errors.generic');
}

async function save(): Promise<boolean> {
    saveState.value = 'saving';

    try {
        const response = await axios.put<{ issues: Issue[]; dirty: boolean }>(`/journeys/${props.journey.uuid}/graph`, { graph: graphPayload(), name: name.value.trim() || undefined });

        issues.value = response.data.issues;
        unpublished.value = response.data.dirty;
        changed.value = false;
        saveState.value = 'saved';

        return true;
    } catch (error) {
        saveState.value = 'error';
        notice.value = { tone: 'danger', text: failure(error) };

        return false;
    }
}

async function validate(): Promise<void> {
    busy.value = 'validate';

    try {
        const response = await axios.post<{ issues: Issue[] }>(`/journeys/${props.journey.uuid}/validate`, { graph: graphPayload() });

        issues.value = response.data.issues;
        issuesOpen.value = true;
        notice.value = { tone: errorCount.value ? 'danger' : 'info', text: errorCount.value ? t('journeyCanvas.validation.hasErrors', { n: errorCount.value }) : t('journeyCanvas.validation.ok') };
    } catch (error) {
        notice.value = { tone: 'danger', text: failure(error) };
    } finally {
        busy.value = '';
    }
}

async function publish(): Promise<void> {
    busy.value = 'publish';

    try {
        const response = await axios.post<{ message: string; version: number; status: string }>(`/journeys/${props.journey.uuid}/publish`, { graph: graphPayload() });

        status.value = response.data.status;
        version.value = response.data.version;
        unpublished.value = false;
        changed.value = false;
        saveState.value = 'saved';
        notice.value = { tone: 'info', text: response.data.message };
        router.reload({ only: ['stats', 'versions', 'issues'] });
    } catch (error) {
        issuesOpen.value = true;
        notice.value = { tone: 'danger', text: failure(error) };
    } finally {
        busy.value = '';
    }
}

async function pause(): Promise<void> {
    busy.value = 'pause';

    try {
        const response = await axios.post<{ message: string; status: string }>(`/journeys/${props.journey.uuid}/pause`, {}, { headers: { Accept: 'application/json' } });

        status.value = response.data.status;
        notice.value = { tone: 'info', text: response.data.message };
    } catch (error) {
        notice.value = { tone: 'danger', text: failure(error) };
    } finally {
        busy.value = '';
    }
}

async function testRun(): Promise<void> {
    if (!testEmail.value.trim()) return;

    busy.value = 'test';

    try {
        const response = await axios.post<TestRunResult>(`/journeys/${props.journey.uuid}/test-run`, { email: testEmail.value.trim(), graph: graphPayload() });

        testResult.value = response.data;
        testPath.value = response.data.steps.map((step) => step.node).filter((node): node is string => node !== null);
    } catch (error) {
        notice.value = { tone: 'danger', text: failure(error) };
    } finally {
        busy.value = '';
    }
}

watch(
    () => props.stats,
    (value) => {
        stats.value = version.value === null ? {} : value.nodes;
    },
);

watch(
    () => props.issues,
    (value) => {
        if (!changed.value) issues.value = value;
    },
);

/* ------------------------------------------------------------------ állapot-feliratok */

const statusTone = computed(() => (status.value === 'active' ? 'success' : status.value === 'paused' ? 'warning' : 'neutral'));
const statusLabel = computed(() => t(`journeyCanvas.status.${status.value}`, { version: version.value ?? '' }));
const publishLabel = computed(() => {
    if (status.value === 'active') return t('journeyCanvas.actions.publishVersion');
    if (status.value === 'paused' && !unpublished.value && !changed.value) return t('journeyCanvas.actions.resume');

    return t('journeyCanvas.actions.activate');
});
const saveLabel = computed(() => t(`journeyCanvas.save.${saveState.value}`));
const zoomPercent = computed(() => Math.round(viewport.value.zoom * 100));

function warnUnload(event: BeforeUnloadEvent): void {
    if (changed.value) event.preventDefault();
}

onMounted(() => window.addEventListener('beforeunload', warnUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnload));

function focusNode(id: string): void {
    selectedId.value = id;
    fitView({ nodes: [id], duration: 300, maxZoom: 1.2 });
}

function issueText(issue: Issue): string {
    return t(`journeyCanvas.issues.${issue.code}`, { field: issue.field ?? '' });
}
</script>

<template>
    <Head :title="name" />

    <AppLayout editor-mode>
        <div class="vm-jc" :class="{ 'vm-jc--compact': compact }" data-testid="journey-canvas" :data-status="status" :data-save="saveState">
            <!-- FELSŐ SÁV -->
            <header class="vm-jc-top">
                <div class="vm-jc-top__name">
                    <input v-model="name" :aria-label="t('journeyCanvas.top.name')" maxlength="120" data-testid="journey-canvas-name" @input="touch" />
                    <VtBadge :tone="statusTone" data-testid="journey-status">{{ statusLabel }}</VtBadge>
                    <span v-if="unpublished && status !== 'draft'" class="vm-jc-top__hint" data-testid="journey-unpublished">{{ t('journeyCanvas.top.unpublished') }}</span>
                    <span class="vm-ed-status" :class="`vm-ed-status--${saveState}`" role="status" data-testid="journey-save-state">
                        <span class="vm-ed-status__dot" aria-hidden="true" />{{ saveLabel }}
                    </span>
                </div>

                <div class="vm-jc-top__actions">
                    <form class="vm-jc-top__test" @submit.prevent="testRun">
                        <VtInput v-model="testEmail" type="email" size="sm" :placeholder="t('journeyCanvas.test.placeholder')" :aria-label="t('journeyCanvas.test.contact')" data-testid="test-run-email" />
                        <VtButton type="submit" variant="ghost" size="sm" :loading="busy === 'test'" :disabled="!testEmail.trim()" data-testid="test-run"><VmIcon name="send" /> {{ t('journeyCanvas.actions.testRun') }}</VtButton>
                    </form>
                    <VtButton variant="ghost" size="sm" :loading="busy === 'validate'" data-testid="journey-validate" @click="validate"><VmIcon name="check" /> {{ t('journeyCanvas.actions.validate') }}</VtButton>
                    <VtButton variant="ghost" size="sm" :loading="saveState === 'saving'" data-testid="journey-save" @click="save">{{ t('journeyCanvas.actions.saveDraft') }}</VtButton>
                    <VtButton v-if="can.publish && status === 'active'" variant="ghost" size="sm" :loading="busy === 'pause'" data-testid="journey-pause" @click="pause">{{ t('journeyCanvas.actions.pause') }}</VtButton>
                    <VtButton v-if="can.publish" size="sm" :loading="busy === 'publish'" data-testid="journey-publish" @click="publish">{{ publishLabel }}</VtButton>
                </div>

                <p v-if="notice" class="vm-banner vm-jc-top__notice" :class="{ 'vm-banner--danger': notice.tone === 'danger' }" role="status" data-testid="journey-notice" @click="notice = null">
                    {{ notice.text }}
                </p>
            </header>

            <div class="vm-jc-body">
                <!-- PALETTA -->
                <aside class="vm-jc-palette">
                    <NodePalette :triggers="catalog.triggers" :actions="catalog.actions" :has-trigger="hasTrigger" @add="addNode($event)" />
                </aside>

                <!-- VÁSZON -->
                <main class="vm-jc-stage">
                    <div class="vm-jc-issues" :class="{ 'vm-jc-issues--bad': errorCount > 0 }" data-testid="journey-issues" :data-errors="errorCount" :data-warnings="warningCount">
                        <button type="button" class="vm-jc-issues__toggle" :aria-expanded="issuesOpen" data-testid="journey-issues-toggle" @click="issuesOpen = !issuesOpen">
                            <VmIcon :name="errorCount ? 'warning' : 'check'" />
                            <span>{{ errorCount || warningCount ? t('journeyCanvas.validation.summary', { errors: errorCount, warnings: warningCount }) : t('journeyCanvas.validation.ok') }}</span>
                            <VmIcon name="chevron" />
                        </button>
                        <ul v-if="issuesOpen && issues.length" class="vm-jc-issues__list">
                            <li v-for="(issue, index) in issues" :key="index" :class="`vm-jc-issues__item--${issue.severity}`" :data-testid="`issue-${issue.code}`">
                                <button v-if="issue.node" type="button" @click="focusNode(issue.node)">{{ titleOf(issue.node) }}</button>
                                <span>{{ issueText(issue) }}</span>
                            </li>
                        </ul>
                    </div>

                    <div class="vm-jc-flow" data-testid="journey-flow" @dragover="onDragOver" @drop="onDrop">
                        <VueFlow
                            :id="flowId"
                            v-model:nodes="nodes"
                            v-model:edges="edges"
                            :snap-to-grid="true"
                            :snap-grid="[16, 16]"
                            :min-zoom="0.2"
                            :max-zoom="2"
                            :default-viewport="{ zoom: 1 }"
                            :delete-key-code="['Delete', 'Backspace']"
                            @pane-ready="fitView({ maxZoom: 1, padding: 0.2 })"
                            @connect="onConnect"
                            @node-click="onNodeClick"
                            @pane-click="selectedId = null"
                            @nodes-change="onNodesChange"
                            @edges-change="onEdgesChange"
                        >
                            <template #node-journey="nodeProps">
                                <JourneyNode :id="nodeProps.id" :data="nodeProps.data" :selected="nodeProps.selected" />
                            </template>

                            <Background variant="dots" :gap="16" class="vm-jc-bg" />
                            <MiniMap pannable zoomable :width="160" :height="100" class="vm-jc-minimap" node-class-name="vm-jc-minimap__node" :aria-label="t('journeyCanvas.minimap')" />
                        </VueFlow>

                        <div class="vm-jc-zoom" role="group" :aria-label="t('journeyCanvas.zoom.label')">
                            <button type="button" :aria-label="t('journeyCanvas.zoom.in')" data-testid="zoom-in" @click="zoomIn()"><VmIcon name="plus" /></button>
                            <span class="vm-visually-hidden" aria-live="polite">{{ zoomPercent }}%</span>
                            <button type="button" :aria-label="t('journeyCanvas.zoom.out')" data-testid="zoom-out" @click="zoomOut()"><span class="vm-jc-zoom__minus" aria-hidden="true" /></button>
                            <button type="button" :aria-label="t('journeyCanvas.zoom.fit')" data-testid="zoom-fit" @click="fitView({ duration: 300 })"><VmIcon name="layout" /></button>
                        </div>
                    </div>
                </main>

                <!-- JOBB PANEL -->
                <aside class="vm-jc-panel" :aria-label="t('journeyCanvas.panel.label')">
                    <TestRunPanel v-if="testResult" :result="testResult" :title-of="titleOf" @close="testResult = null; testPath = []" @focus="focusNode" />

                    <NodeConfigPanel
                        v-else-if="selected && selected.data"
                        :key="selected.id"
                        :node="{ id: selected.id, type: selected.data.kind, position: selected.position, label: selected.data.label, config: selected.data.config }"
                        :targets="targets"
                        :connections="connections"
                        :options="options"
                        :catalog="catalog"
                        :stats="stats[selected.id] ?? null"
                        :issues="nodeIssues"
                        @config="updateNode(selected.id, { config: $event })"
                        @label="updateNode(selected.id, { label: $event })"
                        @connect="(handle, target) => connect(selected!.id, handle, target)"
                        @duplicate="duplicateNode(selected.id)"
                        @remove="removeNode(selected.id)"
                    />

                    <div v-else class="vm-jc-panel__empty" data-testid="node-config-empty">
                        <p>{{ t('journeyCanvas.panel.empty') }}</p>
                        <p v-if="!props.stats.available" class="vm-jcfg__note">{{ t('journeyCanvas.stats.unavailable') }}</p>
                        <dl v-if="versions.length" class="vm-jc-versions" data-testid="journey-versions">
                            <dt>{{ t('journeyCanvas.panel.versions') }}</dt>
                            <dd v-for="item in versions" :key="item.number">
                                v{{ item.number }}<span v-if="item.publishedAt"> · {{ new Date(item.publishedAt).toLocaleString() }}</span>
                            </dd>
                        </dl>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
