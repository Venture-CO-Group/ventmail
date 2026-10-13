<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import { align, buttonStyle, displayUrl, imageWidth, textStyle, type Resolved } from './render';
import RichText from './RichText.vue';
import { sanitizeHtml } from './sanitize';
import type { Attrs, Block, BlockPath, EditorCatalog } from './types';

/*
 | EGY TARTALMI BLOKK a vásznon. A címsor és a bekezdés HELYBEN szerkeszthető
 | (`RichText`), a többi blokk mezői a tulajdonságpanelen.
 |
 | A blokk eszköztára (fogantyú, fel/le, duplikálás, mentés blokként, törlés) a
 | billentyűzetes alternatíva a húzáshoz (D3): minden gomb fókuszálható és felcímkézett.
 */
const props = defineProps<{
    block: Block;
    path: BlockPath;
    selected: boolean;
    styles: Resolved;
    catalog: EditorCatalog;
    first: boolean;
    last: boolean;
}>();

const emit = defineEmits<{
    select: [];
    update: [patch: Attrs, coalesceKey: string];
    action: [name: 'up' | 'down' | 'duplicate' | 'delete' | 'save'];
    dragstart: [event: DragEvent];
}>();

const { t } = useI18n();

const attrs = computed<Attrs>(() => props.block.attrs ?? {});
const key = computed(() => `${props.path.s}-${props.path.c}-${props.path.b}`);
const typeLabel = computed(() => t(`editor.blocks.${paletteId.value}`));

const paletteId = computed(() => {
    if (props.block.type === 'social') return attrs.value.mode === 'share' ? 'social_share' : 'social_follow';

    return props.block.type;
});

const segmentName = computed(() => {
    const condition = attrs.value.condition as { kind?: string; segment?: string; field?: string; op?: string; value?: string } | undefined;

    if (!condition) return null;

    if (condition.kind === 'segment') {
        const name = props.catalog.segments.find((segment) => segment.uuid === condition.segment)?.name ?? '?';

        return condition.op === 'not_in' ? t('editor.condition.badgeNotSegment', { name }) : t('editor.condition.badgeSegment', { name });
    }

    return t('editor.condition.badgeField', { field: condition.field ?? '', op: t(`editor.condition.ops.${condition.op}`), value: condition.value ?? '' });
});

const networks = computed(() =>
    (Array.isArray(attrs.value.networks) ? attrs.value.networks : []).map((network) =>
        typeof network === 'string' ? network : String((network as { name?: string }).name ?? ''),
    ),
);

const answers = computed(() => (Array.isArray(attrs.value.answers) ? (attrs.value.answers as string[]) : []));

const addressLines = computed(() => String(attrs.value.address ?? '').split(/\r?\n/));

function text(field: string): string {
    return String(attrs.value[field] ?? '');
}
</script>

<template>
    <div
        class="vm-ed-block"
        :class="{ 'vm-ed-block--selected': selected, 'vm-ed-hide-mobile': attrs.hide_on_mobile === true }"
        :data-testid="`block-${key}`"
        :data-type="block.type"
        tabindex="0"
        :aria-label="typeLabel"
        @click.stop="emit('select')"
        @keydown.enter.self="emit('select')"
    >
        <template v-if="selected">
            <span class="vm-ed-block__label">{{ typeLabel }}</span>
            <span class="vm-ed-block__tools" role="toolbar" :aria-label="t('editor.blockTools.label')">
                <span
                    class="vm-ed-iconbtn vm-ed-grip"
                    draggable="true"
                    role="button"
                    :title="t('editor.blockTools.drag')"
                    :aria-label="t('editor.blockTools.drag')"
                    :data-testid="`drag-${key}`"
                    @dragstart="emit('dragstart', $event)"
                >
                    <VmIcon name="grip" />
                </span>
                <button type="button" class="vm-ed-iconbtn" :disabled="first" :title="t('editor.blockTools.up')" :aria-label="t('editor.blockTools.up')" :data-testid="`up-${key}`" @click.stop="emit('action', 'up')">
                    <VmIcon name="arrow-up" />
                </button>
                <button type="button" class="vm-ed-iconbtn" :disabled="last" :title="t('editor.blockTools.down')" :aria-label="t('editor.blockTools.down')" :data-testid="`down-${key}`" @click.stop="emit('action', 'down')">
                    <VmIcon name="arrow-down" />
                </button>
                <button type="button" class="vm-ed-iconbtn" :title="t('editor.blockTools.duplicate')" :aria-label="t('editor.blockTools.duplicate')" :data-testid="`duplicate-${key}`" @click.stop="emit('action', 'duplicate')">
                    <VmIcon name="copy" />
                </button>
                <button type="button" class="vm-ed-iconbtn" :title="t('editor.blockTools.save')" :aria-label="t('editor.blockTools.save')" :data-testid="`save-block-${key}`" @click.stop="emit('action', 'save')">
                    <VmIcon name="plus" />
                </button>
                <button type="button" class="vm-ed-iconbtn" :title="t('editor.blockTools.delete')" :aria-label="t('editor.blockTools.delete')" :data-testid="`delete-${key}`" @click.stop="emit('action', 'delete')">
                    <VmIcon name="trash" />
                </button>
            </span>
        </template>

        <span v-if="segmentName" class="vm-ed-badge" data-testid="condition-badge"><VmIcon name="condition" />{{ segmentName }}</span>

        <!-- CÍMSOR és BEKEZDÉS: helyben szerkeszthető. -->
        <div v-if="block.type === 'heading'" :style="textStyle(attrs, styles, true)">
            <RichText
                :model-value="text('content')"
                profile="inline"
                :tags="catalog.mergeTags"
                :swatches="catalog.swatches"
                :label="typeLabel"
                :testid="`rich-${key}`"
                @update:model-value="emit('update', { content: $event }, `text:${key}`)"
                @focus="emit('select')"
            />
        </div>

        <div v-else-if="block.type === 'text'" :style="textStyle(attrs, styles)">
            <RichText
                :model-value="text('content')"
                profile="rich"
                :tags="catalog.mergeTags"
                :swatches="catalog.swatches"
                :label="typeLabel"
                :testid="`rich-${key}`"
                @update:model-value="emit('update', { content: $event }, `text:${key}`)"
                @focus="emit('select')"
            />
        </div>

        <div v-else-if="block.type === 'button'" :style="{ textAlign: align(attrs, 'center'), padding: 'var(--vt-space-2) 0' }">
            <span :style="buttonStyle(attrs, styles)" v-html="sanitizeHtml(text('content'), 'inline')" />
        </div>

        <div v-else-if="block.type === 'divider'" :style="{ padding: 'var(--vt-space-2) 0' }">
            <hr :style="{ border: 0, borderTop: `thin solid ${text('color') || styles.text_color}`, margin: 0 }" />
        </div>

        <div v-else-if="block.type === 'spacer'" :style="{ height: `${Number(attrs.height ?? 16)}px` }" />

        <div v-else-if="block.type === 'image' || block.type === 'logo'" :style="{ textAlign: align(attrs, block.type === 'logo' ? 'left' : 'center'), padding: 'var(--vt-space-2) 0' }">
            <img class="vm-ed-img" :src="displayUrl(attrs.src, catalog)" :alt="text('alt')" :style="{ width: imageWidth(attrs, block.type === 'logo' ? 120 : undefined), display: 'inline-block' }" />
        </div>

        <div v-else-if="block.type === 'video'" :style="{ textAlign: align(attrs, 'center'), padding: 'var(--vt-space-2) 0' }">
            <img class="vm-ed-img" :src="displayUrl(attrs.thumbnail, catalog)" :alt="text('alt')" :style="{ width: imageWidth(attrs), display: 'inline-block' }" />
            <div :style="{ color: styles.link_color, paddingTop: 'var(--vt-space-2)' }">▶ {{ text('play_label') }}</div>
        </div>

        <div v-else-if="block.type === 'social'" :style="{ textAlign: align(attrs, 'center'), padding: 'var(--vt-space-2) 0' }">
            <span v-for="network in networks" :key="network" class="vm-ed-pill" :style="{ color: styles.text_color }">
                {{ t(`editor.networks.${network}`) }}
            </span>
        </div>

        <div v-else-if="block.type === 'html'" class="vm-ed-code" :style="{ color: styles.text_color }" v-html="sanitizeHtml(text('content'), 'block')" />

        <div v-else-if="block.type === 'product'" :style="{ textAlign: align(attrs, 'center'), padding: 'var(--vt-space-2) 0', color: styles.text_color }">
            <img v-if="attrs.image" class="vm-ed-img" :src="displayUrl(attrs.image, catalog)" :alt="text('alt') || text('name')" :style="{ display: 'inline-block' }" />
            <div :style="{ fontWeight: '700', fontSize: 'larger', paddingTop: 'var(--vt-space-2)' }" v-html="sanitizeHtml(text('name'), 'inline')" />
            <div v-if="attrs.description" v-html="sanitizeHtml(text('description'), 'inline')" />
            <div v-if="attrs.price" :style="{ paddingBlock: 'var(--vt-space-1)' }">
                <s v-if="attrs.old_price">{{ text('old_price') }}</s> <strong>{{ text('price') }}</strong>
            </div>
            <span :style="buttonStyle({ background: attrs.button_background, color: attrs.button_color }, styles)">{{ text('button_label') }}</span>
        </div>

        <div v-else-if="block.type === 'survey'" :style="{ textAlign: align(attrs, 'center'), padding: 'var(--vt-space-2) 0', color: text('color') || styles.text_color }">
            <div :style="{ fontWeight: '700', fontSize: 'larger' }" v-html="sanitizeHtml(text('question'), 'inline')" />
            <span
                v-for="answer in answers"
                :key="answer"
                class="vm-ed-pill"
                :style="{ background: text('button_background') || styles.button_background, color: text('button_color') || styles.button_color }"
            >
                {{ answer }}
            </span>
        </div>

        <div
            v-else-if="block.type === 'footer'"
            :style="{ textAlign: align(attrs, 'center'), fontSize: `${Number(attrs.font_size ?? 12)}px`, color: text('color') || styles.text_color, padding: 'var(--vt-space-2) 0' }"
        >
            <strong v-if="attrs.company">{{ text('company') }}</strong>
            <div v-for="(line, index) in addressLines" :key="index">{{ line }}</div>
            <div v-if="attrs.content" v-html="sanitizeHtml(text('content'), 'inline')" />
            <div :style="{ color: text('link_color') || styles.link_color }">
                <u>{{ text('unsubscribe_label') || t('editor.footer.unsubscribe') }}</u> · <u>{{ text('preferences_label') || t('editor.footer.preferences') }}</u>
            </div>
        </div>

        <div v-else-if="block.type === 'unsubscribe'" :style="{ ...textStyle(attrs, styles), color: styles.link_color }">
            <u>{{ text('content') || t('editor.footer.unsubscribe') }}</u> · <u>{{ t('editor.footer.preferences') }}</u>
        </div>
    </div>
</template>
