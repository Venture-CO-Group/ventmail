<script setup lang="ts">
import { VtButton, VtCheckbox, VtInput } from '@venture-co-group/ui';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '../../composables/useFormat';
import VmFieldGroup from '../Form/VmFieldGroup.vue';
import VmPager from '../Form/VmPager.vue';
import VmIcon from '../Shell/VmIcon.vue';

/**
 * KAMPÁNY CÍMZETT-VÁLASZTÓ (v1.1 · D284) — listák vagy szegmensek, SZERVEROLDALON keresve és
 * lapozva (`GET /campaigns/targets/{kind}`). A kiválasztottak a lapozástól függetlenül, felül
 * mindig látszanak (a nevük a kampánnyal jön: `selectedTargets`, ill. a betöltött lapokból).
 *
 * Szándékosan nem tölt le minden listát: egy több száz listás tenantnál a régi, mindent
 * kirajzoló jelölőnégyzet-fal 1 500 px-es lépést adott.
 */
export interface TargetOption {
    uuid: string;
    name: string;
    contacts: number | null;
}

const props = withDefaults(
    defineProps<{
        kind: 'lists' | 'segments';
        modelValue: string[];
        known: Record<string, TargetOption>;
        legend: string;
        testid: string;
        readonly?: boolean;
        showCounts?: boolean;
    }>(),
    { readonly: false, showCounts: true },
);

const emit = defineEmits<{ toggle: [uuid: string]; resolve: [options: TargetOption[]] }>();

const { t } = useI18n();
const format = useFormat();

const search = ref('');
const items = ref<TargetOption[]>([]);
const page = ref(1);
const last = ref(1);
const total = ref(0);
const loading = ref(false);
const failed = ref(false);

let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

async function load(nextPage = 1): Promise<void> {
    const request = ++sequence;
    loading.value = true;
    failed.value = false;

    try {
        const { data } = await axios.get(`/campaigns/targets/${props.kind}`, {
            params: { targets_search: search.value.trim() || undefined, targets_page: nextPage > 1 ? nextPage : undefined },
        });

        // Egy lassú, régebbi válasz nem írhatja felül a frissebbet.
        if (request !== sequence) return;

        items.value = data.items;
        page.value = data.page;
        last.value = data.last;
        total.value = data.total;
        emit('resolve', data.items);
    } catch {
        if (request === sequence) failed.value = true;
    } finally {
        if (request === sequence) loading.value = false;
    }
}

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => void load(1), 250);
});

onMounted(() => void load(1));
onBeforeUnmount(() => clearTimeout(timer));

const selected = computed(() => props.modelValue.map((uuid) => props.known[uuid] ?? { uuid, name: uuid, contacts: null }));

const label = (option: TargetOption): string =>
    props.showCounts && option.contacts !== null ? `${option.name} · ${t('checklist.contacts', { count: format.number(option.contacts) })}` : option.name;
</script>

<template>
    <VmFieldGroup :legend="legend">
        <div class="vm-picker">
            <!-- A KIVÁLASZTOTTAK mindig látszanak (lapozás és keresés nélkül is). -->
            <ul v-if="selected.length" class="vm-picker__chips" :aria-label="t('picker.selected')" :data-testid="`${testid}-selected`">
                <li v-for="option in selected" :key="option.uuid" class="vm-picker__chip" :data-testid="`${testid}-selected-${option.uuid}`">
                    <span class="vm-picker__chip-text">{{ option.name }}</span>
                    <VtButton
                        v-if="!readonly"
                        variant="ghost"
                        size="sm"
                        :aria-label="t('picker.remove', { name: option.name })"
                        :data-testid="`${testid}-remove-${option.uuid}`"
                        @click="emit('toggle', option.uuid)"
                    >
                        <VmIcon name="close" />
                    </VtButton>
                </li>
            </ul>

            <VtInput v-model="search" :aria-label="t('picker.search')" :placeholder="t('picker.search')" :data-testid="`${testid}-search`" />

            <p v-if="failed" class="vm-picker__muted" role="alert">{{ t('picker.failed') }}</p>
            <p v-else-if="!loading && !items.length" class="vm-picker__muted" :data-testid="`${testid}-empty`">
                <slot name="empty">{{ search.trim() ? t('picker.noMatch') : t('picker.none') }}</slot>
            </p>

            <div class="vm-picker__options" :aria-busy="loading">
                <VtCheckbox
                    v-for="option in items"
                    :key="option.uuid"
                    :model-value="modelValue.includes(option.uuid)"
                    :disabled="readonly"
                    :data-testid="`${testid}-${option.uuid}`"
                    @update:model-value="emit('toggle', option.uuid)"
                >
                    <span class="vm-picker__label">{{ label(option) }}</span>
                </VtCheckbox>
            </div>

            <VmPager v-if="total > 0" :page="page" :last="last" :total="total" :busy="loading" :testid="`${testid}-pager`" @change="load($event)" />
        </div>
    </VmFieldGroup>
</template>

<style scoped>
.vm-picker {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    inline-size: 100%;
    min-width: 0;
}

.vm-picker__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-picker__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-1);
    max-inline-size: 100%;
    padding-inline-start: var(--vt-space-3);
    border: thin solid var(--vt-accent);
    border-radius: var(--vt-radius-full);
    background-color: var(--vt-surface-2);
}

.vm-picker__chip-text,
.vm-picker__label {
    min-width: 0;
    overflow-wrap: anywhere;
}

.vm-picker__options {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
}

.vm-picker__muted {
    color: var(--vt-text-secondary);
}
</style>
