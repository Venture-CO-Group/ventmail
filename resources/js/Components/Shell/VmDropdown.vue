<script setup lang="ts">
/*
 | ÁLTALÁNOS LEGÖRDÜLŐ a héjhoz (nav-csoport, workspace-váltó, nyelvválasztó, avatar-menü).
 |
 | ## ARIA-minták
 | - `role="disclosure"` (alap): gomb `aria-expanded` + `aria-controls`, a panel linkjei
 |   sima Tab-sorrendben járhatók — a webhely-navigáció ajánlott mintája.
 | - `role="menu"`: `aria-haspopup="menu"`, `role="menu"` panel, a tartalom `menuitem*`
 |   szerepű elemei; nyilakkal (↑ ↓ Home End) járhatók.
 |
 | ## Billentyűzet
 | Esc bezár ÉS a fókusz visszatér a gombra; ↓ a gombon megnyit és az első elemre lép;
 | a fókusz elhagyása (Tab) bezár.
 |
 | ## Egér
 | `hover`: ráhúzásra nyílik (késleltetett záródással, hogy a rés átléphető legyen); a
 | kattintás „rögzíti" (pin) — így a hover- és a kattintás-kezelés nem üti egymást.
 */
import { router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { activeMenuId, activeMenuPinned } from '../../composables/useMenuState';

const props = withDefaults(
    defineProps<{
        id: string;
        /** A gomb elérhető neve (ha a gomb szövege nem elég, pl. csak ikon). */
        label?: string;
        mode?: 'disclosure' | 'menu';
        hover?: boolean;
        align?: 'start' | 'end';
        triggerClass?: string;
        panelClass?: string;
        testid?: string;
        title?: string;
    }>(),
    { mode: 'disclosure', hover: false, align: 'start', label: undefined, triggerClass: '', panelClass: '', testid: undefined, title: undefined },
);

const emit = defineEmits<{ open: []; close: [] }>();

const uid = useId();
const panelId = `vm-dd-${props.id}-${uid}`;
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const shift = ref('0');

const open = ref(false);
let closeTimer: ReturnType<typeof setTimeout> | undefined;

watch(activeMenuId, (id) => {
    if (id !== props.id && open.value) {
        open.value = false;
        emit('close');
    }
});

function focusables(): HTMLElement[] {
    if (!panel.value) return [];

    return Array.from(
        panel.value.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled]), [role^="menuitem"]'),
    ).filter((element) => element.offsetParent !== null);
}

async function show(pinned: boolean, focusFirst = false): Promise<void> {
    window.clearTimeout(closeTimer);

    if (!open.value) {
        open.value = true;
        activeMenuId.value = props.id;
        activeMenuPinned.value = pinned;
        emit('open');
    } else if (pinned) {
        activeMenuPinned.value = true;
    }

    await nextTick();
    keepInViewport();

    if (focusFirst) {
        focusables()[0]?.focus();
    }
}

/*
 | A panel ne lógjon ki a nézetből: a szélesség a tartalomé, a pozíciót a gombhoz igazítjuk,
 | ezért ha túlnyúlna a jobb/bal szélen, eltoljuk. A mérték egy CSS-változóba kerül
 | (`--vm-dd-shift`), a stílus onnan olvassa.
 */
function keepInViewport(): void {
    if (!panel.value) return;

    shift.value = '0';

    const rect = panel.value.getBoundingClientRect();
    const gutter = parseFloat(getComputedStyle(document.documentElement).fontSize) || 0;
    const overflowRight = rect.right - (window.innerWidth - gutter);
    const overflowLeft = gutter - rect.left;

    if (overflowRight > 0) {
        shift.value = `${-Math.round(overflowRight)}px`;
    } else if (overflowLeft > 0) {
        shift.value = `${Math.round(overflowLeft)}px`;
    }
}

function close(returnFocus = false): void {
    window.clearTimeout(closeTimer);

    if (!open.value) return;

    open.value = false;

    if (activeMenuId.value === props.id) {
        activeMenuId.value = null;
        activeMenuPinned.value = false;
    }

    emit('close');

    if (returnFocus) {
        void nextTick(() => trigger.value?.focus());
    }
}

function onTriggerClick(event: MouseEvent): void {
    // `detail === 0` = billentyűzetes aktiválás (Enter/Space): az első elemre lépünk.
    const keyboard = event.detail === 0;

    if (!open.value) {
        void show(true, keyboard && props.mode === 'menu');

        return;
    }

    // Hoverrel nyitott, még nem rögzített panel: a kattintás RÖGZÍTI, nem zárja.
    if (props.hover && !activeMenuPinned.value) {
        activeMenuPinned.value = true;

        return;
    }

    close(true);
}

function onTriggerKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || (event.key === 'ArrowUp' && props.mode === 'menu')) {
        event.preventDefault();
        void show(true, true);
    }
}

function onPanelKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close(true);

        return;
    }

    const items = focusables();

    if (items.length === 0) return;

    const index = items.indexOf(document.activeElement as HTMLElement);
    const isTextField = document.activeElement instanceof HTMLInputElement;

    // Szövegmezőben a nyilak a kurzorhoz tartoznak — csak ↓ lép ki a listába.
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        items[Math.min(index + 1, items.length - 1)]?.focus();
    } else if (event.key === 'ArrowUp' && !(isTextField && index === 0)) {
        event.preventDefault();
        (index <= 0 ? trigger.value : items[index - 1])?.focus();
    } else if (event.key === 'Home' && !isTextField) {
        event.preventDefault();
        items[0]?.focus();
    } else if (event.key === 'End' && !isTextField) {
        event.preventDefault();
        items[items.length - 1]?.focus();
    }
}

function onRootKeydown(event: KeyboardEvent): void {
    // A gombon álló Esc is zár (a panel-kezelő csak a panelen belülit fogja).
    if (event.key === 'Escape' && open.value && event.target === trigger.value) {
        event.stopPropagation();
        close(true);
    }
}

function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget;

    if (next instanceof Node && root.value && !root.value.contains(next)) {
        close();
    }
}

function onPointerEnter(event: PointerEvent): void {
    if (!props.hover || event.pointerType === 'touch') return;

    void show(false);
}

function onPointerLeave(event: PointerEvent): void {
    if (!props.hover || event.pointerType === 'touch') return;

    window.clearTimeout(closeTimer);
    closeTimer = setTimeout(() => {
        if (!activeMenuPinned.value) close();
    }, 160);
}

function onDocumentPointerDown(event: PointerEvent): void {
    if (open.value && root.value && event.target instanceof Node && !root.value.contains(event.target)) {
        close();
    }
}

let stopNavigate: (() => void) | undefined;

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown);
    // Egy navigáció (link-kattintás) után a panel záródik — a következő oldalon nem maradhat nyitva.
    stopNavigate = router.on('navigate', () => close());
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
    window.clearTimeout(closeTimer);
    stopNavigate?.();

    if (activeMenuId.value === props.id) {
        activeMenuId.value = null;
        activeMenuPinned.value = false;
    }
});

defineExpose({ close, open: show });
</script>

<template>
    <div
        ref="root"
        class="vm-dd"
        :class="{ 'vm-dd--open': open }"
        @pointerenter="onPointerEnter"
        @pointerleave="onPointerLeave"
        @focusout="onFocusOut"
        @keydown="onRootKeydown"
    >
        <button
            ref="trigger"
            type="button"
            class="vm-dd__trigger"
            :class="triggerClass"
            :aria-expanded="open"
            :aria-controls="open ? panelId : undefined"
            :aria-haspopup="mode === 'menu' ? 'menu' : undefined"
            :aria-label="label"
            :title="title"
            :data-testid="testid"
            @click="onTriggerClick"
            @keydown="onTriggerKeydown"
        >
            <slot name="trigger" :open="open" />
        </button>

        <div
            v-if="open"
            :id="panelId"
            ref="panel"
            class="vm-dd__panel vt-glass vt-glass--thick"
            :class="[`vm-dd__panel--${align}`, panelClass]"
            :style="{ '--vm-dd-shift': shift }"
            :role="mode === 'menu' ? 'menu' : undefined"
            :aria-label="mode === 'menu' ? label : undefined"
            @keydown="onPanelKeydown"
        >
            <slot :close="close" />
        </div>
    </div>
</template>
