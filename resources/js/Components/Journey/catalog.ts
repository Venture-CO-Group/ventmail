import type { InjectionKey, Ref } from 'vue';

/*
 | A JOURNEY-VÁSZON csomópont-katalógusa (v1.1 · 6. modul) — a szerver `NodeCatalog`-jának
 | kliens-oldali tükre: ikon, kategória, alap-konfiguráció, kimenetek. A validálás a szerveré
 | (`JourneyGraphValidator`); itt csak az kell, amit a rajz és a panel megjelenít.
 */

export type Config = Record<string, unknown>;

export interface JNode {
    id: string;
    type: string;
    position: { x: number; y: number };
    label: string;
    config: Config;
}

export interface JEdge {
    id: string;
    source: string;
    sourceHandle: string;
    target: string;
}

export interface JGraph {
    nodes: JNode[];
    edges: JEdge[];
}

export interface Issue {
    code: string;
    severity: 'error' | 'warning';
    node?: string;
    field?: string;
}

export interface NodeStat {
    entered: number;
    waiting: number;
    movedOn: number;
}

export interface Option {
    value: string;
    label: string;
}

export interface CatalogDef {
    triggers: string[];
    actions: string[];
    conditionTypes: string[];
    goalTypes: string[];
    waitEvents: string[];
}

export type Options = Record<'templates' | 'lists' | 'tags' | 'segments' | 'forms' | 'endpoints' | 'campaigns' | 'routes' | 'fields', Option[]>;

export interface TestRunStep {
    node: string | null;
    type: string | null;
    outcome: string;
    detail: string | null;
    handle: string | null;
    at: string;
    minutes: number;
}

export interface TestRunResult {
    contact: string;
    found: boolean;
    steps: TestRunStep[];
    sends: number;
    realSends: number;
    simulatedMinutes: number;
    stopped: string | null;
}

/** A palettáról húzott csomópont MIME-típusa. */
export const DRAG_MIME = 'application/x-ventmail-journey-node';

export const TRIGGER_TYPES = [
    'list_subscribe',
    'tag_added',
    'tag_removed',
    'field_changed',
    'date_anniversary',
    'form_signup',
    'api_event',
    'campaign_interaction',
    'segment_entered',
    'inbound_email',
];

export const ICONS: Record<string, string> = {
    list_subscribe: 'users',
    tag_added: 'tag',
    tag_removed: 'tag',
    field_changed: 'sliders',
    date_anniversary: 'history',
    form_signup: 'layout',
    api_event: 'code',
    campaign_interaction: 'megaphone',
    segment_entered: 'filter',
    inbound_email: 'inbox',
    send_email: 'mail',
    wait: 'history',
    condition: 'condition',
    split: 'route',
    ab_split: 'columns',
    update_contact: 'user',
    list_membership: 'list',
    webhook: 'webhook',
    goal: 'check',
    exit: 'logout',
};

export function isTrigger(type: string): boolean {
    return TRIGGER_TYPES.includes(type);
}

export function isTerminal(type: string): boolean {
    return type === 'goal' || type === 'exit';
}

/** A csomópont kimenetei — a szerver `NodeCatalog::outputs()` tükre. */
export function outputs(type: string, config: Config): string[] {
    if (isTrigger(type)) return ['out'];

    switch (type) {
        case 'condition':
            return ['yes', 'no'];
        case 'wait':
            return config.mode === 'event' ? ['out', 'timeout'] : ['out'];
        case 'split':
            return [...branches(config).map((branch) => String(branch.key ?? '')), 'else'];
        case 'ab_split':
            return variants(config).map((variant) => String(variant.key ?? ''));
        case 'goal':
        case 'exit':
            return [];
        default:
            return ['out'];
    }
}

export function branches(config: Config): Array<{ key?: string; label?: string; condition?: Config }> {
    return Array.isArray(config.branches) ? (config.branches as Array<{ key?: string; label?: string; condition?: Config }>) : [];
}

export function variants(config: Config): Array<{ key?: string; weight?: number }> {
    return Array.isArray(config.variants) ? (config.variants as Array<{ key?: string; weight?: number }>) : [];
}

/** Az új csomópont alap-konfigurációja. */
export function defaultConfig(type: string): Config {
    switch (type) {
        case 'wait':
            return { minutes: 1440 };
        case 'condition':
            return { type: 'opened_email', within_hours: 168 };
        case 'split':
            return { branches: [{ key: 'b1', label: '', condition: { type: 'opened_email', within_hours: 168 } }] };
        case 'ab_split':
            return {
                variants: [
                    { key: 'a', weight: 50 },
                    { key: 'b', weight: 50 },
                ],
            };
        case 'update_contact':
            return { operation: 'add_tag' };
        case 'list_membership':
            return { operation: 'add' };
        case 'campaign_interaction':
            return { interaction: 'open' };
        case 'date_anniversary':
            return { offset_days: 0 };
        default:
            return {};
    }
}

/** Új, ütközésmentes csomópont-azonosító. */
export function newId(prefix: string, taken: Set<string>): string {
    for (;;) {
        const id = `${prefix}-${Math.random().toString(36).slice(2, 8)}`;

        if (!taken.has(id)) return id;
    }
}

/** Időtartam percben → (érték, egység) a panelhez. */
export function splitMinutes(minutes: number): { amount: number; unit: 'minutes' | 'hours' | 'days' } {
    if (minutes > 0 && minutes % 1440 === 0) return { amount: minutes / 1440, unit: 'days' };
    if (minutes > 0 && minutes % 60 === 0) return { amount: minutes / 60, unit: 'hours' };

    return { amount: minutes, unit: 'minutes' };
}

export const UNIT_MINUTES = { minutes: 1, hours: 60, days: 1440 } as const;

/** A vászon közös állapota a csomópontoknak (statisztika, hibák, választható elemek, teszt-út). */
export interface CanvasContext {
    stats: Ref<Record<string, NodeStat>>;
    issues: Ref<Issue[]>;
    options: Ref<Options>;
    testPath: Ref<string[]>;
}

export const CanvasKey: InjectionKey<CanvasContext> = Symbol('journey-canvas');

type Translate = (key: string, values?: Record<string, unknown>) => string;

function labelOf(list: Option[], value: unknown): string {
    const match = list.find((option) => option.value === value);

    return match ? match.label : '';
}

/** Egy időtartam emberi alakja (perc → „2 nap"). */
export function formatMinutes(minutes: number, t: Translate): string {
    const { amount, unit } = splitMinutes(minutes);

    return t(`journeyCanvas.duration.${unit}`, { n: amount });
}

/** A csomópont egysoros összefoglalója a vásznon. */
export function summarize(type: string, config: Config, options: Options, t: Translate): string {
    switch (type) {
        case 'list_subscribe':
        case 'list_membership':
            return labelOf(options.lists, config.list);
        case 'tag_added':
        case 'tag_removed':
            return labelOf(options.tags, config.tag);
        case 'field_changed':
        case 'date_anniversary':
            return labelOf(options.fields, config.field);
        case 'form_signup':
            return labelOf(options.forms, config.form) || t('journeyCanvas.config.any');
        case 'api_event':
            return String(config.event ?? '');
        case 'campaign_interaction':
            return t(`journeyCanvas.config.${config.interaction === 'click' ? 'interactionClick' : 'interactionOpen'}`);
        case 'segment_entered':
            return labelOf(options.segments, config.segment);
        case 'send_email':
            return labelOf(options.templates, config.template);
        case 'wait':
            return config.mode === 'event'
                ? t('journeyCanvas.summary.waitEvent', { event: t(`journeyCanvas.events.${String(config.event ?? 'opened')}`), time: formatMinutes(Number(config.timeout_minutes ?? 0), t) })
                : formatMinutes(Number(config.minutes ?? 0), t);
        case 'condition':
            return config.type ? t(`journeyCanvas.conditions.${String(config.type)}`) : '';
        case 'split':
            return t('journeyCanvas.summary.branches', { n: branches(config).length + 1 });
        case 'ab_split':
            return variants(config)
                .map((variant) => `${String(variant.key ?? '').toUpperCase()} ${Number(variant.weight ?? 0)}%`)
                .join(' · ');
        case 'update_contact':
            return config.operation ? t(`journeyCanvas.operations.${String(config.operation)}`) : '';
        case 'webhook':
            return labelOf(options.endpoints, config.endpoint);
        default:
            return '';
    }
}

/** Egy kimenet felirata a csomóponton. */
export function outputLabel(handle: string, config: Config, t: Translate): string {
    if (handle === 'out') return '';
    if (['yes', 'no', 'timeout', 'else'].includes(handle)) return t(`journeyCanvas.handles.${handle}`);

    const branch = branches(config).find((item) => item.key === handle);

    if (branch) return branch.label ? String(branch.label) : handle;

    const variant = variants(config).find((item) => item.key === handle);

    return `${handle.toUpperCase()} · ${Number(variant?.weight ?? 0)}%`;
}
