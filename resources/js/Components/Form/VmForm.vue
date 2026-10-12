<script setup lang="ts">
/**
 * ŰRLAP — a szakaszok közti távolságot (`--vt-space-8`) EZ adja, nem az oldal.
 *
 * Az űrlap-családot három komponens alkotja, és a távolságok táblázata itt van leírva,
 * hogy egy új oldalnak ne kelljen CSS-t írnia:
 *
 *   cím ↔ mező (a `VtField`-ben)  --vt-space-2   (8pt-rács)
 *   mező ↔ mező                  --vt-space-5   (`VmFormSection`)
 *   szakasz ↔ szakasz            --vt-space-8   (`VmForm`)
 *   kártya belső margó           --vt-space-6   (`VtCard`)
 *
 * A natív `submit` esemény átmegy (a szülő `@submit.prevent`-je a gyökér `<form>`-ra kerül).
 */
</script>

<template>
    <form class="vm-form"><slot /></form>
</template>

<style scoped>
.vm-form {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-8);
    min-width: 0;
}
</style>
