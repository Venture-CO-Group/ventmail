import { describe, expect, it } from 'vitest';
import {
    defaultConfig,
    formatMinutes,
    isTerminal,
    isTrigger,
    newId,
    outputLabel,
    outputs,
    splitMinutes,
    summarize,
    TRIGGER_TYPES,
    type Options,
} from '../../../resources/js/Components/Journey/catalog';

/*
 | A csomópont-katalógus kliens-tükre: kimenetek (a szerver `NodeCatalog::outputs()`),
 | ág-feliratok, alap-konfiguráció, időtartam és összefoglaló.
 */

const t = (key: string, values?: Record<string, unknown>) => (values ? `${key}${JSON.stringify(values)}` : key);

const noOptions: Options = { templates: [], lists: [], tags: [], segments: [], forms: [], endpoints: [], campaigns: [], routes: [], fields: [] };

describe('típusok', () => {
    it('trigger és végpont felismerése', () => {
        expect(TRIGGER_TYPES).toHaveLength(10);
        expect(TRIGGER_TYPES.every(isTrigger)).toBe(true);
        expect(isTrigger('send_email')).toBe(false);
        expect(isTerminal('goal')).toBe(true);
        expect(isTerminal('exit')).toBe(true);
        expect(isTerminal('wait')).toBe(false);
    });
});

describe('kimenetek', () => {
    it('trigger és egyszerű akció: egy `out`', () => {
        expect(outputs('list_subscribe', {})).toEqual(['out']);
        expect(outputs('send_email', {})).toEqual(['out']);
        expect(outputs('webhook', {})).toEqual(['out']);
    });

    it('feltétel: igen / nem; végpont: nincs kimenet', () => {
        expect(outputs('condition', {})).toEqual(['yes', 'no']);
        expect(outputs('goal', {})).toEqual([]);
        expect(outputs('exit', {})).toEqual([]);
    });

    it('várakozás: eseményre várva időtúllépés-kimenet is', () => {
        expect(outputs('wait', { minutes: 60 })).toEqual(['out']);
        expect(outputs('wait', { mode: 'event' })).toEqual(['out', 'timeout']);
    });

    it('elágazás: áganként egy kimenet + „egyébként"; A/B: változatonként', () => {
        expect(outputs('split', { branches: [{ key: 'b1' }, { key: 'b2' }] })).toEqual(['b1', 'b2', 'else']);
        expect(outputs('split', {})).toEqual(['else']);
        expect(outputs('ab_split', defaultConfig('ab_split'))).toEqual(['a', 'b']);
        expect(outputs('ab_split', { variants: 'broken' })).toEqual([]);
    });
});

describe('ág-feliratok', () => {
    it('az `out` felirat nélküli, a fix kimenetek fordítva', () => {
        expect(outputLabel('out', {}, t)).toBe('');
        expect(outputLabel('yes', {}, t)).toBe('journeyCanvas.handles.yes');
        expect(outputLabel('timeout', {}, t)).toBe('journeyCanvas.handles.timeout');
        expect(outputLabel('else', {}, t)).toBe('journeyCanvas.handles.else');
    });

    it('elágazás: az ág saját felirata, üresen a kulcsa', () => {
        const config = { branches: [{ key: 'b1', label: 'VIP' }, { key: 'b2', label: '' }] };

        expect(outputLabel('b1', config, t)).toBe('VIP');
        expect(outputLabel('b2', config, t)).toBe('b2');
    });

    it('A/B: nagybetűs kulcs és súly', () => {
        expect(outputLabel('a', { variants: [{ key: 'a', weight: 70 }, { key: 'b', weight: 30 }] }, t)).toBe('A · 70%');
        expect(outputLabel('c', { variants: [] }, t)).toBe('C · 0%');
    });
});

describe('alap-konfiguráció', () => {
    it('típusonkénti alapérték, és minden hívás új objektum', () => {
        expect(defaultConfig('wait')).toEqual({ minutes: 1440 });
        expect(defaultConfig('condition')).toEqual({ type: 'opened_email', within_hours: 168 });
        expect(defaultConfig('split')).toMatchObject({ branches: [{ key: 'b1' }] });
        expect(defaultConfig('send_email')).toEqual({});

        const first = defaultConfig('ab_split');
        (first.variants as Array<{ weight: number }>)[0].weight = 1;
        expect(defaultConfig('ab_split')).toEqual({ variants: [{ key: 'a', weight: 50 }, { key: 'b', weight: 50 }] });
    });

    it('az új azonosító a foglaltakkal nem ütközik', () => {
        const taken = new Set<string>();

        for (let i = 0; i < 200; i++) {
            const id = newId('wait', taken);

            expect(id.startsWith('wait-')).toBe(true);
            expect(taken.has(id)).toBe(false);
            taken.add(id);
        }
    });
});

describe('időtartam és összefoglaló', () => {
    it('perc → a legnagyobb egész egység', () => {
        expect(splitMinutes(2880)).toEqual({ amount: 2, unit: 'days' });
        expect(splitMinutes(120)).toEqual({ amount: 2, unit: 'hours' });
        expect(splitMinutes(90)).toEqual({ amount: 90, unit: 'minutes' });
        expect(splitMinutes(0)).toEqual({ amount: 0, unit: 'minutes' });
        expect(formatMinutes(1440, t)).toBe('journeyCanvas.duration.days{"n":1}');
    });

    it('a csomópont egysoros összefoglalója a választható elemek feliratából', () => {
        const options: Options = { ...noOptions, templates: [{ value: 't1', label: 'Welcome mail' }], lists: [{ value: 'l1', label: 'Newsletter' }] };

        expect(summarize('send_email', { template: 't1' }, options, t)).toBe('Welcome mail');
        expect(summarize('list_subscribe', { list: 'l1' }, options, t)).toBe('Newsletter');
        expect(summarize('list_subscribe', { list: 'gone' }, options, t)).toBe('');
        expect(summarize('form_signup', {}, options, t)).toBe('journeyCanvas.config.any');
        expect(summarize('split', { branches: [{ key: 'b1' }] }, options, t)).toBe('journeyCanvas.summary.branches{"n":2}');
        expect(summarize('ab_split', defaultConfig('ab_split'), options, t)).toBe('A 50% · B 50%');
    });
});
