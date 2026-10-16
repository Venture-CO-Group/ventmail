<script setup lang="ts">
import { VtInput } from '@venture-co-group/ui';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { mergeTag } from './mergeTags';
import type { MergeTagDef } from './types';

/*
 | MERGE-TAG VÁLASZTÓ — a saját szintaxisunk (`{{ contact.first_name || alapérték }}`).
 |
 | A választóban az ALAPÉRTÉK is megadható: a hiányzó név helyén különben „Szia, !" állna a
 | levélben, és ezt semmilyen kapu nem fogja meg. A link-tagek (leiratkozás, böngészős
 | nézet) alapérték nélkül kerülnek be — linkként, ha a hívó linket kér.
 */
const props = defineProps<{ tags: MergeTagDef[] }>();

const emit = defineEmits<{ insert: [value: string, tag: MergeTagDef]; close: [] }>();

const { t } = useI18n();
const fallback = ref<Record<string, string>>(Object.fromEntries(props.tags.map((tag) => [tag.key, tag.fallback])));
const active = ref<string>(props.tags[0]?.key ?? '');

function label(tag: MergeTagDef): string {
    return t(`editor.mergeTags.${tag.key.replace('.', '_')}`);
}

function insert(tag: MergeTagDef): void {
    emit('insert', mergeTag(tag.key, tag.link ? '' : (fallback.value[tag.key] ?? '')), tag);
}
</script>

<template>
    <div class="vm-ed-popover" role="dialog" :aria-label="t('editor.mergeTags.title')" data-testid="merge-tag-picker" @keydown.esc.stop="emit('close')">
        <p class="vm-ed-heading">{{ t('editor.mergeTags.title') }}</p>
        <div class="vm-ed-taglist">
            <button
                v-for="tag in tags"
                :key="tag.key"
                type="button"
                :data-testid="`merge-tag-${tag.key}`"
                @focus="active = tag.key"
                @mouseenter="active = tag.key"
                @click="insert(tag)"
            >
                <span>{{ label(tag) }}</span>
                <code>{{ mergeTag(tag.key, tag.link ? '' : (fallback[tag.key] ?? '')) }}</code>
            </button>
        </div>
        <label v-if="tags.find((tag) => tag.key === active && !tag.link)" class="vm-stack">
            <span class="vm-ed-hint">{{ t('editor.mergeTags.fallback') }}</span>
            <VtInput v-model="fallback[active]" size="sm" data-testid="merge-tag-fallback" />
        </label>
    </div>
</template>
