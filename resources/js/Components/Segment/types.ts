/**
 * A SZEGMENS-DSL kliens-oldali alakja (v1.1 · 5. modul) — a `SegmentEngine` szerződése.
 *
 * A katalógus (mezők, típusok, operátorok) a SZERVERRŐL jön: a kliens nem tart saját
 * engedélylistát, csak megjeleníti azt, amit a motor elfogad.
 */
export interface SegmentRule {
    field: string;
    op: string;
    value?: string | number | null;
}

export interface SegmentGroup {
    match: 'all' | 'any';
    rules: Array<SegmentRule | { group: SegmentGroup }>;
}

export interface CatalogField {
    key: string;
    type: 'string' | 'number' | 'date' | 'boolean' | 'enum' | 'engagement' | 'list' | 'tag';
    group: string;
    label?: string;
    values?: string[];
}

export interface SegmentCatalog {
    fields: CatalogField[];
    operators: Record<string, string[]>;
    maxRules: number;
    maxDepth: number;
}

export interface NamedRef {
    uuid: string;
    name: string;
}

/** Operátorok, amelyekhez nem tartozik érték. */
export const VALUELESS = ['set', 'not_set', 'never_opened'];

/** „Az elmúlt N napban" típusú operátorok — az érték napok száma. */
export const DAY_OPERATORS = [
    'within_days',
    'not_within_days',
    'opened_within_days',
    'clicked_within_days',
    'not_opened_within_days',
    'not_clicked_within_days',
];

export function isGroup(rule: SegmentRule | { group: SegmentGroup }): rule is { group: SegmentGroup } {
    return typeof rule === 'object' && rule !== null && 'group' in rule;
}

/** Egy új, alapértelmezett szabály a katalógus első mezőjével. */
export function blankRule(catalog: SegmentCatalog): SegmentRule {
    const field = catalog.fields[0];
    const op = catalog.operators[field.type]?.[0] ?? 'is';

    return { field: field.key, op, value: '' };
}

/** A szabályok száma a teljes fában (a szerver plafonja ugyanígy számol). */
export function countRules(group: SegmentGroup): number {
    return group.rules.reduce((sum, rule) => sum + (isGroup(rule) ? countRules(rule.group) : 1), 0);
}

/** A KÜLDÖTT alak: a szövegként bevitt számok számmá, az érték nélküli operátorok érték nélkül. */
export function normalise(group: SegmentGroup): SegmentGroup {
    return {
        match: group.match,
        rules: group.rules.map((rule) => {
            if (isGroup(rule)) return { group: normalise(rule.group) };

            if (VALUELESS.includes(rule.op)) return { field: rule.field, op: rule.op };

            if (DAY_OPERATORS.includes(rule.op)) return { field: rule.field, op: rule.op, value: Number(rule.value) || 0 };

            return { field: rule.field, op: rule.op, value: rule.value ?? '' };
        }),
    };
}
