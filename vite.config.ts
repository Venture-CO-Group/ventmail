import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/landing.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue(),
    ],
    resolve: {
        // Egyetlen Vue-példány: a @venture/ui saját másolata típus- és runtime-ütközést okozna.
        dedupe: ['vue'],
    },
});
