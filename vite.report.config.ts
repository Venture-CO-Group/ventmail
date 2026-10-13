import { defineConfig } from 'vite';

/*
 | v1.1 · 8. modul (D239) — a PDF-riport KÜLÖN, hálózat nélküli csomagja.
 |
 | Klasszikus (IIFE) szkript, nem ES-modul: a Gotenberg a riportot `file://`-ról tölti, ahonnan a
 | Chromium a modul-szkriptet CORS-hibával tiltja. Egyetlen fájl (`report.js`) + egy stíluslap
 | (`report.css`), kód-darabolás nélkül — a renderer ezt a kettőt csatolja a kéréshez. A `npm run
 | build` a fő build UTÁN futtatja (a fő build kiüríti a `public/build`-et).
 */
export default defineConfig({
    publicDir: false,
    define: { 'process.env.NODE_ENV': JSON.stringify('production') },
    build: {
        outDir: 'public/build/report',
        emptyOutDir: true,
        cssCodeSplit: false,
        minify: true,
        lib: {
            entry: 'resources/js/report/main.ts',
            name: 'VmReport',
            formats: ['iife'],
            fileName: () => 'report.js',
        },
        rollupOptions: {
            output: {
                assetFileNames: (asset) => ((asset.names?.[0] ?? asset.name ?? '').endsWith('.css') ? 'report.css' : '[name][extname]'),
            },
        },
    },
});
