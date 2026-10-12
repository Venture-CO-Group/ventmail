<script setup lang="ts">
/*
 | Dekoratív vonal-ikon (24-es rácsú viewBox, `currentColor`).
 |
 | JELENTÉS: hiányzó @venture/ui komponens: ikon-készlet (`VtIcon`). Amíg nincs, a héj
 | minimális, lokális ikon-térképet használ; a méretet és a színt a környezet adja
 | (`currentColor`, token-méret), nincs beégetett érték. Az ikon mindig DEKORATÍV
 | (`aria-hidden`): a jelentést a mellette álló szöveg / `aria-label` hordozza.
 */
import { computed } from 'vue';

const props = defineProps<{ name: string }>();

const PATHS: Record<string, string[]> = {
    home: ['M3 10.5 12 3l9 7.5', 'M5 9.5V21h14V9.5', 'M10 21v-6h4v6'],
    megaphone: ['M3 11v3a1 1 0 0 0 1 1h2l7 4V6L6 10H4a1 1 0 0 0-1 1Z', 'M17 9a4 4 0 0 1 0 6'],
    code: ['m8 8-5 4 5 4', 'm16 8 5 4-5 4', 'm14 5-4 14'],
    sliders: ['M4 7h10', 'M18 7h2', 'M4 17h2', 'M10 17h10', 'M14 5v4', 'M6 15v4'],
    terminal: ['m5 8 4 4-4 4', 'M12 17h7'],
    chevron: ['m6 9 6 6 6-6'],
    globe: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M3 12h18', 'M12 3a14 14 0 0 1 0 18', 'M12 3a14 14 0 0 0 0 18'],
    search: ['M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z', 'm20 20-4-4'],
    plus: ['M12 5v14', 'M5 12h14'],
    check: ['m5 12 5 5 9-10'],
    user: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M4 20a8 8 0 0 1 16 0'],
    shield: ['M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z'],
    external: ['M14 4h6v6', 'M20 4 11 13', 'M18 14v5H5V6h5'],
    logout: ['M9 4H5v16h4', 'm16 8 4 4-4 4', 'M20 12H9'],
    menu: ['M4 7h16', 'M4 12h16', 'M4 17h16'],
    close: ['m6 6 12 12', 'm18 6-12 12'],
    users: ['M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z', 'M2.5 20a6.5 6.5 0 0 1 13 0', 'M16 4.5a3.5 3.5 0 0 1 0 7', 'M18 14a6.5 6.5 0 0 1 3.5 6'],
    'file-text': ['M6 3h8l4 4v14H6V3Z', 'M14 3v4h4', 'M9 12h6', 'M9 16h6'],
    layout: ['M4 4h16v16H4V4Z', 'M4 10h16', 'M10 10v10'],
    send: ['m21 3-9 18-2-8-8-2 19-8Z', 'm21 3-11 10'],
    route: ['M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z', 'M18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z', 'M8 17h6a3 3 0 0 0 0-6h-4a3 3 0 0 1 0-6h6'],
    chart: ['M4 20V10', 'M10 20V4', 'M16 20v-8', 'M21 20H3'],
    key: ['M8 15a4 4 0 1 1 3.5-6H21v3h-2v2h-3v-2h-4.5A4 4 0 0 1 8 15Z'],
    server: ['M4 4h16v7H4V4Z', 'M4 13h16v7H4v-7Z', 'M8 7.5h.01', 'M8 16.5h.01'],
    webhook: ['M9 17a4 4 0 1 1 3-6.7', 'M15 17h-4l-3-6', 'M14 6a3 3 0 1 1 5 2.2L16 14'],
    'file-code': ['M6 3h8l4 4v14H6V3Z', 'M14 3v4h4', 'm10 13-2 2 2 2', 'm14 13 2 2-2 2'],
    network: ['M12 9V4', 'M5 15v-3h14v3', 'M12 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z', 'M5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z', 'M19 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
    activity: ['M3 12h4l3-8 4 16 3-8h4'],
    list: ['M8 6h13', 'M8 12h13', 'M8 18h13', 'M3.5 6h.01', 'M3.5 12h.01', 'M3.5 18h.01'],
    'credit-card': ['M3 6h18v12H3V6Z', 'M3 10h18'],
    inbox: ['M4 13 6 5h12l2 8', 'M4 13v6h16v-6h-5a3 3 0 0 1-6 0H4Z'],
    'check-circle': ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'm8 12 3 3 5-6'],
    mail: ['M3 5h18v14H3V5Z', 'm3 7 9 6 9-6'],
};

const paths = computed(() => PATHS[props.name] ?? []);
</script>

<template>
    <svg
        class="vm-icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        <path v-for="(d, index) in paths" :key="index" :d="d" />
    </svg>
</template>
