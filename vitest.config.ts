import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/*
 | FRONTEND UNIT TESZTEK (v1.1 · 13. pont): a sablonszerkesztő és a journey-vászon
 | állapotkezelése. `npm run test:unit`. A DOM a `happy-dom` (MIT) — a böngészős flow-kat
 | a Playwright fedi (`e2e/`), itt csak a tiszta logika és a composable-ök.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        dedupe: ['vue'],
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.ts'],
        restoreMocks: true,
    },
});
