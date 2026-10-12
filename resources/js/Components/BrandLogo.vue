<script setup lang="ts">
/*
 | A ventMail logó: jelkép (borítékos csempe a Venture-színátmenetben) + „vent" + félkövér
 | „Mail" szó-jel. A szó-jel szöveg, nem kép: a Space Grotesk csak így töltődik be, és a
 | képernyőolvasó is így kapja. A jelkép dekoratív (üres alt) — a név a szövegben van.
 */
withDefaults(defineProps<{ href?: string }>(), { href: '/' });

// Kötött `src`: a statikus abszolút út a Vue-fordítóban modul-importtá válna; ez a public/ mappából szolgált eszköz.
const mark = '/brand/logomark.svg';
</script>

<template>
    <a class="brand-logo" :href="href" aria-label="ventMail">
        <img class="brand-logo__mark" :src="mark" alt="" />
        <span class="brand-logo__word vt-text-display">vent<strong>Mail</strong></span>
    </a>
</template>
