<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtField, VtSelect } from '@venture/ui';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * TÁROLT SABLONOK + VERZIÓ-ÖSSZEHASONLÍTÁS.
 *
 * A verziók MEGVÁLTOZTATHATATLANOK, tehát a képernyőn nincs „szerkesztés": a kérdés az,
 * hogy MI VÁLTOZOTT két verzió között, és MELYIK az élő. Az élő verzió az, amit a
 * `template=` paraméterrel küldött levelek használnak — egy elrontott verzió
 * visszakapcsolása itt egy kattintás, nem egy fejlesztői API-hívás éles incidens közben.
 */
interface VersionRow {
    version: number;
    active: boolean;
    createdAt: string | null;
    bytes: number;
}

interface DiffLine {
    type: string;
    left: number | null;
    right: number | null;
    text: string;
    // A KIHAGYOTT szakasz nem tűnik el csendben: a sorok száma ÉS a tartománya is látszik.
    // Saját kulcsokon, mert a `left`/`right` a többi soron a két oldal sorszáma.
    skipped?: number;
    from?: number | null;
    to?: number | null;
}

const props = defineProps<{
    templates: Array<{ uuid: string; name: string; description: string | null; versions: number }>;
    selected: { name: string; description: string | null; versions: VersionRow[] } | null;
    comparison: {
        left: number;
        right: number;
        truncated: boolean;
        identical: boolean;
        limit: number;
        lines: DiffLine[];
    } | null;
    maxLines: number;
}>();

const { t } = useI18n();

const template = ref(props.selected?.name ?? '');
const left = ref(String(props.comparison?.left ?? ''));
const right = ref(String(props.comparison?.right ?? ''));

const templateOptions = computed(() =>
    props.templates.map((item) => ({ value: item.name, label: `${item.name} (${item.versions})` })),
);

const versionOptions = computed(() =>
    (props.selected?.versions ?? []).map((version) => ({
        value: String(version.version),
        label: version.active ? `v${version.version} · ${t('storedTemplates.activeShort')}` : `v${version.version}`,
    })),
);

const uuidOf = computed(() => props.templates.find((item) => item.name === props.selected?.name)?.uuid ?? '');

// A DIFF a szerveren számolódik, tehát minden választás egy lekérdezés. `preserveState`
// nélkül a legördülők visszaugranának az alapértelmezettre minden váltás után.
function reload(): void {
    router.get(
        '/stored-templates',
        { template: template.value, left: left.value, right: right.value },
        { preserveState: true, preserveScroll: true },
    );
}

watch(template, () => {
    left.value = '';
    right.value = '';
    reload();
});

function activate(version: number): void {
    router.post(`/stored-templates/${uuidOf.value}/versions/${version}/activate`, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('storedTemplates.title') }}</h1>

        <p class="vm-banner" role="status">{{ t('storedTemplates.note') }}</p>

        <VtCard>
            <p v-if="!templates.length" data-testid="stored-templates-empty">{{ t('storedTemplates.empty') }}</p>

            <VtField v-else :label="t('storedTemplates.template')">
                <VtSelect v-model="template" :options="templateOptions" data-testid="stored-template-select" />
            </VtField>
        </VtCard>

        <VtCard v-if="selected" data-testid="stored-template-versions">
            <h2>{{ selected.name }}</h2>

            <div v-for="version in selected.versions" :key="version.version" class="vm-cluster">
                <span class="vt-text-mono">v{{ version.version }}</span>
                <span>{{ version.createdAt ? new Date(version.createdAt).toLocaleString() : '—' }}</span>
                <span>{{ version.bytes }} B</span>

                <span v-if="version.active" :data-testid="`active-${version.version}`">
                    {{ t('storedTemplates.active') }}
                </span>

                <VtButton
                    v-else
                    variant="ghost"
                    :data-testid="`activate-${version.version}`"
                    @click="activate(version.version)"
                >
                    {{ t('storedTemplates.activate') }}
                </VtButton>
            </div>
        </VtCard>

        <VtCard v-if="selected && selected.versions.length > 1" data-testid="stored-template-diff">
            <h2>{{ t('storedTemplates.compare') }}</h2>

            <div class="vm-cluster">
                <VtField :label="t('storedTemplates.left')">
                    <VtSelect
                        v-model="left"
                        :options="versionOptions"
                        data-testid="diff-left"
                        @update:model-value="reload"
                    />
                </VtField>

                <VtField :label="t('storedTemplates.right')">
                    <VtSelect
                        v-model="right"
                        :options="versionOptions"
                        data-testid="diff-right"
                        @update:model-value="reload"
                    />
                </VtField>
            </div>

            <!-- A TÚL NAGY diff nem „üres", hanem MEGMONDJUK, miért nincs: egy csendben
                 üres összehasonlítás azt jelentené, hogy nem változott semmi. -->
            <p v-if="comparison?.truncated" data-testid="diff-truncated">
                <!-- A KORLÁT AZ, AMIVEL A DIFF DOLGOZOTT (`comparison.limit`) — nem egy
                     újraszámolt szám, ami a diff futása után már kisebb. -->
                {{ t('storedTemplates.tooLarge', { lines: comparison.limit }) }}
            </p>

            <!-- AZ „NINCS ELTÉRÉS" külön állítás: egyetlen „kihagyva" sorból a
                 felhasználónak kellett volna kikövetkeztetnie, hogy nem változott semmi. -->
            <p v-else-if="comparison?.identical" data-testid="diff-identical">
                {{ t('storedTemplates.identical') }}
            </p>

            <div v-else-if="comparison" class="vm-diff" data-testid="diff-body">
                <div
                    v-for="(line, index) in comparison.lines"
                    :key="index"
                    class="vm-diff__line"
                    :class="`vm-diff__line--${line.type}`"
                    :data-diff="line.type"
                >
                    <template v-if="line.type === 'skipped'">
                        <span class="vm-diff__text">
                            {{ t('storedTemplates.skipped', { count: line.skipped, from: line.from, to: line.to }) }}
                        </span>
                    </template>
                    <template v-else>
                        <span class="vm-diff__gutter">{{ line.left ?? '' }}</span>
                        <span class="vm-diff__gutter">{{ line.right ?? '' }}</span>
                        <span class="vm-diff__text">{{ line.text }}</span>
                    </template>
                </div>
            </div>
        </VtCard>
    </AppLayout>
</template>
