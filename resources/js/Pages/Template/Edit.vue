<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtField, VtInput, VtSelect, VtTextarea } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * BLOKK-SZERKESZTŐ.
 *
 * ## Miért lista, és nem húzd-és-ejtsd vászon
 *
 * Mert a `@venture/ui`-ban nincs sem vászon, sem drag-and-drop primitív, a golden rule
 * pedig a lokális komponens-gyártást tiltja (jelenteni kell, nem megkerülni). Egy
 * SORREND-alapú szerkesztő ugyanazt a block-tree-t állítja elő, mérhetően és
 * billentyűzettel is használhatóan — a vászon a `@venture/ui` bővítése után jöhet.
 *
 * JELENTÉS a @venture/ui felé: hiányzik (7) elrendezés-vászon / drag-and-drop lista,
 * (8) szín- és távolság-választó, (9) képfeltöltő mező.
 *
 * ## Az ELŐNÉZET a VALÓDI fordítón megy át
 *
 * Amit itt látsz, az megy ki: az MJML-fordító ugyanaz, mint a küldő úton. Egy külön
 * „előnézet-renderer" a legelső eltérésnél hazuggá válna.
 */
/**
 * A BLOKK ALAKJA a block-tree kontraktusé: a mezők az `attrs` alatt élnek, nem a blokk
 * gyökerén. Ez nem szeszély — a `BlockTreeValidator` az ismeretlen gyökér-mezőt HIBÁNAK
 * veszi (helyesen: egy elgépelt mező különben csendben hatástalan maradna), és a ventStudio
 * meg a `@venture/sdk` is ezt a sémát olvassa.
 */
interface Block {
    type: string;
    attrs?: Record<string, unknown>;
    children?: Block[];
}

interface BlockDefinition {
    'x-allowed-children'?: string[] | null;
    'x-required-attrs'?: string[];
    'x-optional-attrs'?: string[];
}

const props = defineProps<{
    template: {
        uuid: string;
        name: string;
        subject: string;
        preheader: string | null;
        marketing: boolean;
        blocks: Block[];
    };
    schema: { definitions?: Record<string, BlockDefinition> };
    preview: { html: string | null; error: string | null };
}>();

const { t } = useI18n();

const name = ref(props.template.name);
const subject = ref(props.template.subject);
const preheader = ref(props.template.preheader ?? '');
const blocks = ref<Block[]>(JSON.parse(JSON.stringify(props.template.blocks)) as Block[]);
const saving = ref(false);

/*
 | A SZERKESZTŐ A SÉMÁBÓL tudja, mi hova tehető: a `column` engedélyezett gyerekei. A séma
 | JSON Schema alakú (ezt olvassa a ventStudio és a `@venture/sdk` is), a mi kiterjesztéseink
 | pedig `x-` előtaggal élnek benne — így a lista a szerverrel EGY forrásból jön, nem egy
 | kézzel karbantartott másolatból, ami az első új blokktípusnál elcsúszna.
 */
const contentTypes = computed<string[]>(() => props.schema.definitions?.column?.['x-allowed-children'] ?? []);

function addSection(): void {
    blocks.value.push({ type: 'section', children: [{ type: 'column', children: [] }] });
}

function addContent(sectionIndex: number, type: string): void {
    const section = blocks.value[sectionIndex];
    const column = section?.children?.[0];

    if (!column) return;

    column.children = [...(column.children ?? []), defaultsFor(type)];
}

function defaultsFor(type: string): Block {
    /*
     | A KÖTELEZŐ mezők ALAPÉRTÉKKEL jönnek létre.
     |
     | A validátor az üres kötelező mezőt hibának veszi, tehát alapérték nélkül a
     | szerkesztő minden új blokknál azonnal pirosba fordulna — a felhasználó azt hinné,
     | elrontott valamit, pedig csak még nem írt bele.
     */
    switch (type) {
        case 'heading':
        case 'text':
            return { type, attrs: { content: 'Szöveg' } };
        case 'button':
            return { type, attrs: { content: 'Kattints ide', href: 'https://pelda.hu' } };
        case 'image':
            return { type, attrs: { src: 'https://pelda.hu/kep.png' } };
        case 'social':
            return { type, attrs: { networks: ['facebook'] } };
        case 'html':
            return { type, attrs: { content: '<p></p>' } };
        default:
            return { type };
    }
}

/** A szerkeszthető mezők blokktípusonként — a séma `required` + néhány gyakori `optional`. */
function editableFields(block: Block): string[] {
    switch (block.type) {
        case 'heading':
        case 'text':
        case 'unsubscribe':
            return ['content'];
        case 'button':
            return ['content', 'href'];
        case 'image':
            return ['src', 'alt', 'href'];
        case 'html':
            return ['content'];
        default:
            return [];
    }
}

function attr(block: Block, field: string): string {
    return String(block.attrs?.[field] ?? '');
}

function setAttr(block: Block, field: string, value: string): void {
    block.attrs = { ...(block.attrs ?? {}), [field]: value };
}

function removeContent(sectionIndex: number, blockIndex: number): void {
    const column = blocks.value[sectionIndex]?.children?.[0];

    if (!column?.children) return;

    column.children.splice(blockIndex, 1);
}

function move(sectionIndex: number, blockIndex: number, delta: number): void {
    const column = blocks.value[sectionIndex]?.children?.[0];
    const target = blockIndex + delta;

    if (!column?.children || target < 0 || target >= column.children.length) return;

    const [item] = column.children.splice(blockIndex, 1);
    column.children.splice(target, 0, item);
}

function removeSection(index: number): void {
    blocks.value.splice(index, 1);
}

function save(): void {
    saving.value = true;

    /*
     | A BLOKK-FA JSON-ként megy át.
     |
     | Az Inertia `FormDataConvertible` típusa a mélyen beágyazott, tetszőleges kulcsú
     | objektumokat nem ismeri el (jogosan: azokat `FormData`-ba kellene szerializálnia).
     | A fa viszont pontosan ilyen — a szerver `array` validációval fogadja, tehát a
     | JSON-alak a helyes átvitel.
     */
    router.put(
        `/templates/${props.template.uuid}`,
        {
            name: name.value,
            subject: subject.value,
            preheader: preheader.value || null,
            // A szerver `array`-t vár és `blocks` néven validálja; az Inertia típusa a
            // mélyen beágyazott fát nem tudja leírni, ezért itt EGY helyen lazítunk.
            blocks: JSON.parse(JSON.stringify(blocks.value)) as never,
        },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('templates.editor') }}</h1>

        <VtCard>
            <VtField :label="t('templates.name')">
                <VtInput v-model="name" data-testid="editor-name" />
            </VtField>

            <VtField :label="t('templates.subject')">
                <VtInput v-model="subject" data-testid="editor-subject" />
            </VtField>

            <VtField :label="t('templates.preheader')" :hint="t('templates.preheaderHint')">
                <VtInput v-model="preheader" data-testid="editor-preheader" />
            </VtField>
        </VtCard>

        <VtCard>
            <h2>{{ t('templates.blocks') }}</h2>

            <p v-if="template.marketing" class="vm-banner vm-banner--warning" role="status" data-testid="editor-unsubscribe-note">
                {{ t('templates.unsubscribeRequired') }}
            </p>

            <div v-for="(section, sectionIndex) in blocks" :key="sectionIndex" class="vm-stack" :data-testid="`section-${sectionIndex}`">
                <div class="vm-cluster">
                    <strong>{{ t('templates.section', { index: sectionIndex + 1 }) }}</strong>
                    <VtButton variant="ghost" :data-testid="`remove-section-${sectionIndex}`" @click="removeSection(sectionIndex)">
                        {{ t('templates.removeSection') }}
                    </VtButton>
                </div>

                <div
                    v-for="(block, blockIndex) in section.children?.[0]?.children ?? []"
                    :key="blockIndex"
                    class="vm-stack"
                    :data-testid="`block-${sectionIndex}-${blockIndex}`"
                >
                    <div class="vm-cluster">
                        <span>{{ block.type }}</span>
                        <VtButton variant="ghost" :data-testid="`up-${sectionIndex}-${blockIndex}`" @click="move(sectionIndex, blockIndex, -1)">↑</VtButton>
                        <VtButton variant="ghost" :data-testid="`down-${sectionIndex}-${blockIndex}`" @click="move(sectionIndex, blockIndex, 1)">↓</VtButton>
                        <VtButton variant="ghost" :data-testid="`remove-${sectionIndex}-${blockIndex}`" @click="removeContent(sectionIndex, blockIndex)">
                            {{ t('templates.removeBlock') }}
                        </VtButton>
                    </div>

                    <VtField
                        v-for="field in editableFields(block)"
                        :key="field"
                        :label="t(`templates.field.${field}`)"
                    >
                        <VtTextarea
                            v-if="field === 'content'"
                            :model-value="attr(block, field)"
                            :data-testid="`${field}-${sectionIndex}-${blockIndex}`"
                            @update:model-value="setAttr(block, field, String($event))"
                        />
                        <VtInput
                            v-else
                            :model-value="attr(block, field)"
                            :data-testid="`${field}-${sectionIndex}-${blockIndex}`"
                            @update:model-value="setAttr(block, field, String($event))"
                        />
                    </VtField>
                </div>

                <div class="vm-cluster">
                    <VtSelect
                        :options="contentTypes.map((type) => ({ value: type, label: type }))"
                        :model-value="''"
                        :data-testid="`add-block-${sectionIndex}`"
                        @update:model-value="addContent(sectionIndex, String($event))"
                    />
                </div>
            </div>

            <VtButton data-testid="add-section" @click="addSection">{{ t('templates.addSection') }}</VtButton>
            <VtButton :disabled="saving" data-testid="editor-save" @click="save">{{ t('templates.save') }}</VtButton>
        </VtCard>

        <VtCard>
            <h2>{{ t('templates.preview') }}</h2>

            <p v-if="preview.error" class="vm-banner vm-banner--danger" role="status" data-testid="preview-error">
                {{ preview.error }}
            </p>

            <p v-else-if="!preview.html" data-testid="preview-empty">{{ t('templates.previewEmpty') }}</p>

            <!-- A FORDÍTOTT HTML forrása látszik, nem beágyazva: a levél HTML-jét a saját
                 oldalunkba engedni (idegen stílus, script) rossz ötlet — a `html` blokk
                 nyers tartalma is átjönne. -->
            <pre v-else class="vt-text-mono" data-testid="preview-html">{{ preview.html }}</pre>
        </VtCard>
    </AppLayout>
</template>
