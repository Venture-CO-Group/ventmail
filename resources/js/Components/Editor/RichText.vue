<script setup lang="ts">
import { VtButton, VtInput } from '@venture-co-group/ui';
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import MergeTagPicker from './MergeTagPicker.vue';
import { mergeLink } from './mergeTags';
import { isAllowedUrl, sanitizeHtml, type SanitizeProfile } from './sanitize';
import type { MergeTagDef } from './types';

/*
 | INLINE SZÖVEGSZERKESZTŐ a vásznon (v1.1): félkövér, dőlt, link, lista, igazítás,
 | betűszín, merge-tag.
 |
 | ## Miért `contenteditable` + `execCommand`, és nem egy szerkesztő-könyvtár
 |
 | A tárolt alak egy SZŰK HTML (a szerver `HtmlSanitizer` allowlistje) — egy általános
 | rich-text motor saját dokumentum-modellt és sémát hozna, amit úgyis erre kellene
 | visszaszűkíteni. A böngésző natív szerkesztője elég, a kimenetét pedig minden
 | bevitelkor ugyanarra az allowlistre tisztítjuk (`sanitize.ts`); a szerver mentéskor
 | újra. Az `execCommand` elavultnak jelölt, de minden böngészőben működik, és nincs
 | szabványos utódja a contenteditable formázásra.
 |
 | ## A kurzor védelme
 |
 | Fókusz alatt a DOM-ot NEM írjuk vissza (a kurzor ugrana): a kibocsátott érték tisztított,
 | a DOM a fókusz elvesztésekor áll be a tisztított alakra.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        profile?: SanitizeProfile;
        tags: MergeTagDef[];
        swatches: string[];
        testid?: string;
        label: string;
    }>(),
    { profile: 'rich', testid: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: string]; focus: []; blur: [] }>();

const { t } = useI18n();
const el = ref<HTMLElement | null>(null);
const focused = ref(false);
const panel = ref<'none' | 'link' | 'color' | 'merge'>('none');
const linkUrl = ref('https://');
const linkError = ref(false);
let savedRange: Range | null = null;

function render(): void {
    if (el.value && !focused.value) {
        el.value.innerHTML = sanitizeHtml(props.modelValue ?? '', props.profile);
    }
}

onMounted(render);
watch(() => props.modelValue, render);

function onInput(): void {
    if (!el.value) return;

    emit('update:modelValue', sanitizeHtml(el.value.innerHTML, props.profile));
}

function onFocus(): void {
    focused.value = true;
    emit('focus');
}

function onBlur(event: FocusEvent): void {
    // A SAJÁT eszköztárra kattintás nem fókuszvesztés.
    const next = event.relatedTarget as Node | null;

    if (next && el.value?.parentElement?.contains(next)) return;

    focused.value = false;
    panel.value = 'none';
    render();
    emit('blur');
}

function saveRange(): void {
    const selection = window.getSelection();

    savedRange = selection && selection.rangeCount > 0 && el.value?.contains(selection.anchorNode) ? selection.getRangeAt(0).cloneRange() : null;
}

function restoreRange(): void {
    el.value?.focus();

    if (!savedRange) return;

    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(savedRange);
}

function exec(command: string, value?: string): void {
    restoreRange();
    // A SZÍN és az IGAZÍTÁS stílusként (`color`, `text-align`) kerül be — a félkövér és a
    // dőlt viszont szemantikus elemként (`<b>`, `<i>`), ahogy a sanitizer várja.
    document.execCommand('styleWithCSS', false, command === 'foreColor' || command.startsWith('justify') ? 'true' : 'false');
    document.execCommand(command, false, value);
    onInput();
}

function toggle(next: 'link' | 'color' | 'merge'): void {
    saveRange();
    panel.value = panel.value === next ? 'none' : next;
    linkError.value = false;
}

function applyLink(): void {
    if (!isAllowedUrl(linkUrl.value)) {
        linkError.value = true;
        return;
    }

    panel.value = 'none';
    exec('createLink', linkUrl.value.trim());
}

function pickColor(swatch: string): void {
    panel.value = 'none';
    exec('foreColor', swatch);
}

function insertTag(value: string, tag: MergeTagDef): void {
    panel.value = 'none';
    restoreRange();

    if (tag.link) {
        const selected = window.getSelection()?.toString() ?? '';
        document.execCommand('insertHTML', false, mergeLink(tag, selected || t(`editor.mergeTags.${tag.key.replace('.', '_')}`)));
    } else {
        document.execCommand('insertText', false, value);
    }

    onInput();
}
</script>

<template>
    <div class="vm-ed-richwrap">
        <div
            ref="el"
            class="vm-ed-rich"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            :aria-label="label"
            :data-testid="testid"
            @input="onInput"
            @focus="onFocus"
            @blur="onBlur"
            @keyup="saveRange"
            @mouseup="saveRange"
        />

        <div v-if="focused" class="vm-ed-toolbar" role="toolbar" :aria-label="t('editor.text.toolbar')" data-testid="rich-toolbar" @mousedown.prevent>
            <button type="button" class="vm-ed-iconbtn" :title="t('editor.text.bold')" :aria-label="t('editor.text.bold')" data-testid="rich-bold" @click="exec('bold')">
                <VmIcon name="bold" />
            </button>
            <button type="button" class="vm-ed-iconbtn" :title="t('editor.text.italic')" :aria-label="t('editor.text.italic')" data-testid="rich-italic" @click="exec('italic')">
                <VmIcon name="italic" />
            </button>
            <button type="button" class="vm-ed-iconbtn" :title="t('editor.text.link')" :aria-label="t('editor.text.link')" :aria-pressed="panel === 'link'" data-testid="rich-link" @click="toggle('link')">
                <VmIcon name="link" />
            </button>
            <template v-if="profile === 'rich'">
                <button type="button" class="vm-ed-iconbtn" :title="t('editor.text.list')" :aria-label="t('editor.text.list')" data-testid="rich-list" @click="exec('insertUnorderedList')">
                    <VmIcon name="list" />
                </button>
                <span class="vm-ed-toolbar__sep" aria-hidden="true" />
                <button type="button" class="vm-ed-iconbtn" :title="t('editor.align.left')" :aria-label="t('editor.align.left')" @click="exec('justifyLeft')">
                    <VmIcon name="align-left" />
                </button>
                <button type="button" class="vm-ed-iconbtn" :title="t('editor.align.center')" :aria-label="t('editor.align.center')" data-testid="rich-center" @click="exec('justifyCenter')">
                    <VmIcon name="align-center" />
                </button>
                <button type="button" class="vm-ed-iconbtn" :title="t('editor.align.right')" :aria-label="t('editor.align.right')" @click="exec('justifyRight')">
                    <VmIcon name="align-right" />
                </button>
            </template>
            <span class="vm-ed-toolbar__sep" aria-hidden="true" />
            <button type="button" class="vm-ed-iconbtn" :title="t('editor.text.color')" :aria-label="t('editor.text.color')" :aria-pressed="panel === 'color'" data-testid="rich-color" @click="toggle('color')">
                <VmIcon name="palette" />
            </button>
            <VtButton variant="ghost" size="sm" data-testid="rich-merge" @click="toggle('merge')">
                <VmIcon name="braces" /> {{ t('editor.mergeTags.button') }}
            </VtButton>

            <div v-if="panel === 'link'" class="vm-ed-popover" data-testid="rich-link-panel" @mousedown.stop>
                <VtInput v-model="linkUrl" size="sm" :invalid="linkError" data-testid="rich-link-url" @keydown.enter.prevent="applyLink" />
                <small v-if="linkError" class="vm-ed-hint">{{ t('editor.text.linkInvalid') }}</small>
                <VtButton size="sm" data-testid="rich-link-apply" @click="applyLink">{{ t('editor.common.apply') }}</VtButton>
            </div>

            <div v-if="panel === 'color'" class="vm-ed-popover">
                <div class="vm-ed-swatches">
                    <button
                        v-for="swatch in swatches"
                        :key="swatch"
                        type="button"
                        class="vm-ed-swatch"
                        :style="{ background: swatch }"
                        :aria-label="swatch"
                        @click="pickColor(swatch)"
                    />
                </div>
            </div>

            <MergeTagPicker v-if="panel === 'merge'" :tags="tags" @insert="insertTag" @close="panel = 'none'" @mousedown.stop />
        </div>
    </div>
</template>
