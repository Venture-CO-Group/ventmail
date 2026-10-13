import { describe, expect, it } from 'vitest';
import { createApp, h, nextTick, ref } from 'vue';
import { createI18n } from 'vue-i18n';
import NodePalette from '../../../resources/js/Components/Journey/NodePalette.vue';
import { DRAG_MIME } from '../../../resources/js/Components/Journey/catalog';
import en from '../../../resources/js/locales/en.json';

/*
 | A csomópont-paletta: a még nem bekötött trigger „Hamarosan" jelölést kap, nem húzható és
 | nem kattintható; egy trigger után a többi trigger is zárva; az akciók mindig elérhetők.
 */

const TRIGGERS = ['list_subscribe', 'tag_added', 'api_event'];
const ACTIONS = ['send_email', 'wait'];

function mount(props: { hasTrigger: boolean; unavailable: string[] }) {
    const added: string[] = [];
    const hasTrigger = ref(props.hasTrigger);
    const root = document.createElement('div');
    const app = createApp({
        render: () =>
            h(NodePalette, {
                triggers: TRIGGERS,
                actions: ACTIONS,
                hasTrigger: hasTrigger.value,
                unavailable: props.unavailable,
                onAdd: (type: string) => added.push(type),
            }),
    });

    app.use(createI18n({ legacy: false, locale: 'en', messages: { en } }));
    app.mount(root);

    const item = (type: string) => root.querySelector<HTMLButtonElement>(`[data-testid="palette-${type}"]`)!;

    return { root, added, item, hasTrigger, unmount: () => app.unmount() };
}

describe('NodePalette', () => {
    it('a nem bekötött trigger „Hamarosan" jelölést kap, zárt és nem húzható', () => {
        const palette = mount({ hasTrigger: false, unavailable: ['tag_added'] });
        const soon = palette.item('tag_added');

        expect(palette.root.querySelector('[data-testid="palette-soon-tag_added"]')?.textContent).toBe('Soon');
        expect(soon.disabled).toBe(true);
        expect(soon.getAttribute('draggable')).toBe('false');
        expect(soon.title).toBe('This trigger is not wired yet — coming soon.');

        soon.click();
        expect(palette.added).toEqual([]);

        // A bekötött trigger elérhető, és nincs „hamarosan" jelölése.
        expect(palette.root.querySelector('[data-testid="palette-soon-list_subscribe"]')).toBeNull();
        expect(palette.item('list_subscribe').disabled).toBe(false);
        expect(palette.item('list_subscribe').getAttribute('draggable')).toBe('true');

        palette.item('list_subscribe').click();
        expect(palette.added).toEqual(['list_subscribe']);
        palette.unmount();
    });

    it('egy trigger után minden trigger zárva, az akciók továbbra is hozzáadhatók', async () => {
        const palette = mount({ hasTrigger: false, unavailable: [] });

        palette.hasTrigger.value = true;
        await nextTick();

        for (const type of TRIGGERS) {
            expect(palette.item(type).disabled).toBe(true);
            expect(palette.item(type).title).toBe('A journey can have one trigger.');
        }

        palette.item('wait').click();
        expect(palette.added).toEqual(['wait']);
        expect(palette.item('send_email').getAttribute('draggable')).toBe('true');
        palette.unmount();
    });

    it('a húzás a csomópont típusát a journey MIME-típussal adja át', () => {
        const palette = mount({ hasTrigger: false, unavailable: [] });
        const data = new Map<string, string>();
        const event = new Event('dragstart', { bubbles: true }) as DragEvent;

        Object.defineProperty(event, 'dataTransfer', {
            value: { setData: (type: string, value: string) => data.set(type, value), effectAllowed: 'none' },
        });
        palette.item('send_email').dispatchEvent(event);

        expect(data.get(DRAG_MIME)).toBe('send_email');
        expect(event.dataTransfer?.effectAllowed).toBe('move');
        palette.unmount();
    });
});
