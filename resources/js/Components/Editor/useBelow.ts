import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/*
 | „A NÉZET KESKENYEBB-E, MINT N × space-32?" — media query nélkül (lásd `useCompactNav`).
 |
 | A szerkesztőnek a héj kompakt küszöbe (tablet) mellett egy KÖZÉPSŐ küszöb is kell: e
 | fölött a tulajdonságpanel oszlopként áll, alatta fiókként nyílik — különben a vászon
 | egy keskeny csíkra szorulna. A küszöb a spacing-tokenből számolt érték.
 */
export function useBelow(factor: number): Ref<boolean> {
    const below = ref(false);
    let threshold = 0;
    let frame = 0;

    function measure(): number {
        const probe = document.createElement('div');

        probe.setAttribute('aria-hidden', 'true');
        probe.style.position = 'absolute';
        probe.style.visibility = 'hidden';
        probe.style.pointerEvents = 'none';
        probe.style.inlineSize = `calc(var(--vt-space-32) * ${factor})`;
        document.body.appendChild(probe);

        const width = probe.getBoundingClientRect().width;

        probe.remove();

        return width;
    }

    function update(): void {
        frame = 0;
        below.value = threshold > 0 && window.innerWidth < threshold;
    }

    function schedule(): void {
        if (frame === 0) frame = window.requestAnimationFrame(update);
    }

    onMounted(() => {
        threshold = measure();
        update();
        window.addEventListener('resize', schedule);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('resize', schedule);

        if (frame !== 0) window.cancelAnimationFrame(frame);
    });

    return below;
}
