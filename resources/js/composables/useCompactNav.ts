import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * COMPACT (hamburger) nézet a tablet-töréspont alatt — media query NÉLKÜL.
 *
 * A CSS media query nem tud `var()`-t olvasni, a design-rendszernek pedig nincs
 * töréspont-tokenje; nyers hossz beégetése viszont tilos. A küszöb ezért a spacing-
 * tokenből SZÁMOLT érték (`calc(calc(var(--vt-space-8) * 4) * 6)`), amit egy rejtett próba-elem
 * méretéből olvasunk ki: ez a böngészőben tablet-szélesség, és a token skálájával együtt
 * mozog.
 *
 * JELENTÉS a @venture/design felé: töréspont-tokenek (`--vt-bp-*`) kellenének.
 */
const BREAKPOINT_FACTOR = 6;

export function useCompactNav(): Ref<boolean> {
    const compact = ref(false);
    let threshold = 0;
    let frame = 0;

    function measureThreshold(): number {
        const probe = document.createElement('div');

        probe.setAttribute('aria-hidden', 'true');
        probe.style.position = 'absolute';
        probe.style.visibility = 'hidden';
        probe.style.pointerEvents = 'none';
        probe.style.inlineSize = `calc(calc(var(--vt-space-8) * 4) * ${BREAKPOINT_FACTOR})`;
        document.body.appendChild(probe);

        const width = probe.getBoundingClientRect().width;

        probe.remove();

        return width;
    }

    function update(): void {
        frame = 0;
        compact.value = threshold > 0 && window.innerWidth <= threshold;
    }

    function schedule(): void {
        if (frame === 0) {
            frame = window.requestAnimationFrame(update);
        }
    }

    onMounted(() => {
        threshold = measureThreshold();
        update();
        window.addEventListener('resize', schedule);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('resize', schedule);

        if (frame !== 0) {
            window.cancelAnimationFrame(frame);
        }
    });

    return compact;
}
