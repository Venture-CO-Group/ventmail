<script setup lang="ts">
import { ref } from 'vue';

/**
 * FÁJL-DOBÓZÓNA: húzással vagy kattintással (és billentyűzettel) választható fájl.
 *
 * A valódi `<input type="file">` megmarad (a képernyőolvasó és a teszt is azt éri el), a
 * dobozban csak a felirat és a húzás-állapot él. A kiterjesztést a szerver is ellenőrzi.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: dropzone / file upload.
 */
defineProps<{
    title: string;
    hint: string;
    accept: string;
    inputLabel: string;
}>();

const emit = defineEmits<{ (e: 'select', file: File): void }>();

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);

function pick(files: FileList | null | undefined): void {
    const file = files?.[0];

    if (file) {
        emit('select', file);
    }
}

function onChange(event: Event): void {
    pick((event.target as HTMLInputElement).files);

    // Ugyanaz a fájl újra választható legyen.
    (event.target as HTMLInputElement).value = '';
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    pick(event.dataTransfer?.files);
}
</script>

<template>
    <div
        class="vm-dropzone"
        :class="{ 'is-dragging': dragging }"
        @dragover.prevent="dragging = true"
        @dragleave="dragging = false"
        @drop.prevent="onDrop"
    >
        <strong class="vm-dropzone__title">{{ title }}</strong>
        <span class="vm-dropzone__hint">{{ hint }}</span>
        <input
            ref="input"
            class="vm-dropzone__input"
            type="file"
            :accept="accept"
            :aria-label="inputLabel"
            data-testid="import-file-input"
            @change="onChange"
        />
    </div>
</template>

<style scoped>
.vm-dropzone {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--vt-space-2);
    padding: var(--vt-space-8);
    border: thin dashed var(--vt-border-strong);
    border-radius: var(--vt-radius-md);
    text-align: center;
    min-width: 0;
}

.vm-dropzone.is-dragging {
    border-color: var(--vt-accent);
    background-color: var(--vt-glass-thin-fill);
}

.vm-dropzone__title {
    overflow-wrap: anywhere;
}

.vm-dropzone__hint {
    font-size: var(--vt-fs-body-sm);
    color: var(--vt-text-muted);
}

.vm-dropzone__input {
    max-inline-size: 100%;
    font-family: var(--vt-font-body);
    color: var(--vt-text);
}
</style>
