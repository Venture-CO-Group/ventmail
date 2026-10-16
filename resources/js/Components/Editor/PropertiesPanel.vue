<script setup lang="ts">
import { VtButton, VtField, VtInput, VtSelect, VtTextarea } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import ConditionEditor from './ConditionEditor.vue';
import EdColor from './EdColor.vue';
import EdSegment from './EdSegment.vue';
import EdSwitch from './EdSwitch.vue';
import ImagePicker from './ImagePicker.vue';
import { mergeTag } from './mergeTags';
import type { AssetDef, Attrs, Block, EditorCatalog, LayoutDef, Selection } from './types';

/*
 | TULAJDONSÁGPANEL: a kijelölt blokk / szakasz mezői, illetve kijelölés nélkül a levél
 | mezői (tárgy, preheader). A mezők a SÉMA kulcsai — a szerver ugyanezeket validálja.
 */
const props = defineProps<{
    selection: Selection;
    target: Block | null;
    catalog: EditorCatalog;
    assets: AssetDef[];
    subject: string;
    preheader: string;
    marketing: boolean;
}>();

const emit = defineEmits<{
    update: [patch: Attrs, coalesceKey: string];
    layout: [layout: LayoutDef];
    subject: [value: string];
    preheader: [value: string];
    uploaded: [asset: AssetDef];
}>();

const { t } = useI18n();

const attrs = computed<Attrs>(() => props.target?.attrs ?? {});
const type = computed(() => props.target?.type ?? '');
const key = computed(() => JSON.stringify(props.selection));

function str(field: string): string {
    return String(attrs.value[field] ?? '');
}

function set(field: string, value: unknown): void {
    emit('update', { [field]: value === '' ? undefined : value }, `${key.value}:${field}`);
}

function num(field: string, value: unknown): void {
    const number = Number(value);

    set(field, value === '' || !Number.isFinite(number) ? undefined : number);
}

const alignOptions = computed(() => [
    { value: 'left', label: t('editor.align.left'), icon: 'align-left' },
    { value: 'center', label: t('editor.align.center'), icon: 'align-center' },
    { value: 'right', label: t('editor.align.right'), icon: 'align-right' },
]);

const levelOptions = computed(() => [1, 2, 3, 4].map((level) => ({ value: level, label: t('editor.props.levelOption', { level }) })));

/* SZAKASZ: belső margó négy mezőben (a levél értéke px-ben). */
const paddingParts = computed<number[]>(() => {
    const raw = String(attrs.value.padding ?? '').match(/\d+/g)?.map(Number) ?? [16, 24];
    const [top, right = top, bottom = top, left = right] = raw;

    return [top, right, bottom, left];
});

function setPadding(index: number, value: unknown): void {
    const parts = [...paddingParts.value];
    parts[index] = Math.max(0, Math.min(200, Number(value) || 0));
    set('padding', parts.map((part) => `${part}px`).join(' '));
}

const currentLayout = computed(() => {
    const widths = (props.target?.children ?? []).map((column) => String(column.attrs?.width ?? '100%'));

    return props.catalog.layouts.find((layout) => layout.columns.length === widths.length && (widths.length === 1 || layout.columns.every((width, i) => width === widths[i])))?.key ?? '';
});

/* KÖZÖSSÉGI: követés — hálózat + profil-URL; megosztás — hálózat-lista + megosztott URL. */
const followNetworks = computed<Array<{ name: string; href: string }>>(() =>
    (Array.isArray(attrs.value.networks) ? attrs.value.networks : [])
        .filter((network): network is { name: string; href?: string } => typeof network === 'object' && network !== null)
        .map((network) => ({ name: String(network.name), href: String(network.href ?? '') })),
);

const shareNetworks = computed<string[]>(() =>
    (Array.isArray(attrs.value.networks) ? attrs.value.networks : []).map((network) => (typeof network === 'string' ? network : String((network as { name: string }).name))),
);

function setFollow(index: number, patch: Partial<{ name: string; href: string }>): void {
    const next = followNetworks.value.map((network, i) => (i === index ? { ...network, ...patch } : network));
    set('networks', next);
}

function addFollow(): void {
    const used = followNetworks.value.map((network) => network.name);
    const name = props.catalog.socialNetworks.find((network) => !used.includes(network)) ?? 'facebook';
    set('networks', [...followNetworks.value, { name, href: 'https://' }]);
}

function removeFollow(index: number): void {
    set('networks', followNetworks.value.filter((_, i) => i !== index));
}

function toggleShare(network: string): void {
    const current = shareNetworks.value;
    set('networks', current.includes(network) ? current.filter((name) => name !== network) : [...current, network]);
}

/* KÉRDŐÍV: válaszok listája. */
const answers = computed<string[]>(() => (Array.isArray(attrs.value.answers) ? (attrs.value.answers as string[]) : []));

function setAnswer(index: number, value: string): void {
    set('answers', answers.value.map((answer, i) => (i === index ? value : answer)));
}

function addAnswer(): void {
    if (answers.value.length < props.catalog.maxSurveyAnswers) {
        set('answers', [...answers.value, t('editor.props.newAnswer')]);
    }
}

function removeAnswer(index: number): void {
    set('answers', answers.value.filter((_, i) => i !== index));
}

const conditionable = computed(() => !['footer', 'unsubscribe'].includes(type.value));

const sectionHasCompliance = computed(() =>
    (props.target?.children ?? []).some((column) => (column.children ?? []).some((block) => ['footer', 'unsubscribe'].includes(block.type))),
);

function appendTag(field: 'subject' | 'preheader', tagKey: string): void {
    const tag = props.catalog.mergeTags.find((item) => item.key === tagKey);
    const value = `${field === 'subject' ? props.subject : props.preheader} ${mergeTag(tagKey, tag?.fallback ?? '')}`.trim();

    if (field === 'subject') emit('subject', value);
    else emit('preheader', value);
}

const title = computed(() => {
    if (props.selection === null) return t('editor.props.message');
    if (props.selection.kind === 'section') return t('editor.props.section');

    return t(`editor.blocks.${type.value === 'social' ? (attrs.value.mode === 'share' ? 'social_share' : 'social_follow') : type.value}`);
});
</script>

<template>
    <div class="vm-ed-fields" data-testid="properties">
        <div class="vm-stack">
            <p class="vm-ed-heading">{{ selection ? t('editor.props.selected') : t('editor.props.settings') }}</p>
            <h2 class="vt-text-h5" data-testid="properties-title">{{ title }}</h2>
        </div>

        <!-- LEVÉL-MEZŐK (nincs kijelölés) -->
        <template v-if="selection === null">
            <VtField :label="t('editor.props.subject')">
                <VtInput :model-value="subject" data-testid="editor-subject" @update:model-value="emit('subject', String($event))" />
            </VtField>
            <VtButton size="sm" variant="ghost" data-testid="subject-first-name" @click="appendTag('subject', 'contact.first_name')">
                <VmIcon name="braces" /> {{ t('editor.props.addFirstName') }}
            </VtButton>
            <VtField :label="t('editor.props.preheader')" :hint="t('editor.props.preheaderHint')">
                <VtInput :model-value="preheader" data-testid="editor-preheader" @update:model-value="emit('preheader', String($event))" />
            </VtField>
            <p v-if="marketing" class="vm-ed-hint">{{ t('editor.props.marketingNote') }}</p>
            <p class="vm-ed-hint">{{ t('editor.props.selectHint') }}</p>
        </template>

        <!-- SZAKASZ -->
        <template v-else-if="selection.kind === 'section'">
            <VtField :label="t('editor.props.layout')">
                <VtSelect
                    :model-value="currentLayout"
                    :options="catalog.layouts.map((layout) => ({ value: layout.key, label: t(`editor.layouts.c${layout.key.replace('-', '_')}`) }))"
                    data-testid="section-layout"
                    @update:model-value="emit('layout', catalog.layouts.find((layout) => layout.key === String($event))!)"
                />
            </VtField>
            <EdColor :model-value="(attrs.background as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.background')" testid="section-background" @update:model-value="set('background', $event ?? undefined)" />
            <p class="vm-ed-heading">{{ t('editor.props.padding') }}</p>
            <div class="vm-ed-grid2">
                <VtField v-for="(side, index) in ['top', 'right', 'bottom', 'left']" :key="side" :label="t(`editor.props.sides.${side}`)">
                    <VtInput type="number" :model-value="paddingParts[index]" @update:model-value="setPadding(index, $event)" />
                </VtField>
            </div>
            <EdSwitch :model-value="attrs.hide_on_mobile === true" :label="t('editor.props.hideMobile')" @update:model-value="set('hide_on_mobile', $event || undefined)" />
            <ConditionEditor v-if="!sectionHasCompliance" :model-value="attrs.condition as never" :catalog="catalog" @update:model-value="set('condition', $event)" />
            <p v-else class="vm-ed-hint">{{ t('editor.condition.complianceLocked') }}</p>
        </template>

        <!-- TARTALMI BLOKK -->
        <template v-else-if="target">
            <template v-if="type === 'heading'">
                <VtField :label="t('editor.props.level')">
                    <VtSelect :model-value="Number(attrs.level ?? 2)" :options="levelOptions" @update:model-value="num('level', $event)" />
                </VtField>
            </template>

            <template v-if="type === 'heading' || type === 'text'">
                <div class="vm-ed-grid2">
                    <VtField :label="t('editor.props.fontSize')">
                        <VtInput type="number" :model-value="str('font_size')" @update:model-value="num('font_size', $event)" />
                    </VtField>
                    <VtField v-if="type === 'text'" :label="t('editor.props.lineHeight')">
                        <VtInput type="number" :model-value="str('line_height')" @update:model-value="num('line_height', $event)" />
                    </VtField>
                </div>
                <EdColor :model-value="(attrs.color as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.textColor')" @update:model-value="set('color', $event ?? undefined)" />
                <p class="vm-ed-hint">{{ t('editor.props.inlineHint') }}</p>
            </template>

            <template v-if="type === 'button'">
                <VtField :label="t('editor.props.label')">
                    <VtInput :model-value="str('content')" data-testid="prop-content" @update:model-value="set('content', $event)" />
                </VtField>
                <VtField :label="t('editor.props.link')">
                    <VtInput :model-value="str('href')" data-testid="prop-href" @update:model-value="set('href', $event)" />
                </VtField>
                <EdColor :model-value="(attrs.background as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.buttonBackground')" @update:model-value="set('background', $event ?? undefined)" />
                <EdColor :model-value="(attrs.color as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.buttonColor')" @update:model-value="set('color', $event ?? undefined)" />
                <VtField :label="t('editor.props.radius')">
                    <VtInput type="number" :model-value="str('border_radius')" @update:model-value="num('border_radius', $event)" />
                </VtField>
            </template>

            <template v-if="type === 'divider'">
                <EdColor :model-value="(attrs.color as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.lineColor')" @update:model-value="set('color', $event ?? undefined)" />
            </template>

            <template v-if="type === 'spacer'">
                <VtField :label="t('editor.props.height')">
                    <VtInput type="number" :model-value="str('height')" data-testid="prop-height" @update:model-value="num('height', $event)" />
                </VtField>
            </template>

            <template v-if="type === 'image' || type === 'logo'">
                <ImagePicker :model-value="str('src')" :label="t('editor.props.image')" :assets="assets" :catalog="catalog" testid="prop-src" @update:model-value="set('src', $event)" @uploaded="emit('uploaded', $event)" />
                <VtField :label="t('editor.props.alt')" :hint="t('editor.props.altHint')">
                    <VtInput :model-value="str('alt')" data-testid="prop-alt" @update:model-value="set('alt', $event)" />
                </VtField>
                <VtField :label="t('editor.props.link')">
                    <VtInput :model-value="str('href')" @update:model-value="set('href', $event)" />
                </VtField>
                <VtField :label="t('editor.props.width')">
                    <VtInput type="number" :model-value="str('width')" @update:model-value="num('width', $event)" />
                </VtField>
            </template>

            <template v-if="type === 'video'">
                <ImagePicker :model-value="str('thumbnail')" :label="t('editor.props.thumbnail')" :assets="assets" :catalog="catalog" @update:model-value="set('thumbnail', $event)" @uploaded="emit('uploaded', $event)" />
                <VtField :label="t('editor.props.videoLink')">
                    <VtInput :model-value="str('href')" @update:model-value="set('href', $event)" />
                </VtField>
                <VtField :label="t('editor.props.playLabel')">
                    <VtInput :model-value="str('play_label')" @update:model-value="set('play_label', $event)" />
                </VtField>
                <VtField :label="t('editor.props.alt')">
                    <VtInput :model-value="str('alt')" @update:model-value="set('alt', $event)" />
                </VtField>
            </template>

            <template v-if="type === 'social' && attrs.mode !== 'share'">
                <p class="vm-ed-heading">{{ t('editor.props.networks') }}</p>
                <ul class="vm-ed-list">
                    <li v-for="(network, index) in followNetworks" :key="index">
                        <VtSelect
                            :model-value="network.name"
                            :options="catalog.socialNetworks.map((name) => ({ value: name, label: t(`editor.networks.${name}`) }))"
                            @update:model-value="setFollow(index, { name: String($event) })"
                        />
                        <VtInput :model-value="network.href" @update:model-value="setFollow(index, { href: String($event) })" />
                        <button type="button" class="vm-ed-iconbtn" :aria-label="t('editor.blockTools.delete')" @click="removeFollow(index)"><VmIcon name="trash" /></button>
                    </li>
                </ul>
                <VtButton size="sm" variant="ghost" @click="addFollow"><VmIcon name="plus" /> {{ t('editor.props.addNetwork') }}</VtButton>
            </template>

            <template v-if="type === 'social' && attrs.mode === 'share'">
                <VtField :label="t('editor.props.shareUrl')" :hint="t('editor.props.shareHint')">
                    <VtInput :model-value="str('share_url')" @update:model-value="set('share_url', $event)" />
                </VtField>
                <div class="vm-cluster">
                    <VtButton v-for="network in catalog.shareNetworks" :key="network" size="sm" :variant="shareNetworks.includes(network) ? 'primary' : 'ghost'" @click="toggleShare(network)">
                        {{ t(`editor.networks.${network}`) }}
                    </VtButton>
                </div>
            </template>

            <template v-if="type === 'html'">
                <VtField :label="t('editor.props.html')" :hint="t('editor.props.htmlHint')">
                    <VtTextarea :model-value="str('content')" :rows="10" data-testid="prop-html" @update:model-value="set('content', $event)" />
                </VtField>
            </template>

            <template v-if="type === 'product'">
                <VtField :label="t('editor.props.productName')">
                    <VtInput :model-value="str('name')" data-testid="prop-name" @update:model-value="set('name', $event)" />
                </VtField>
                <VtField :label="t('editor.props.description')">
                    <VtTextarea :model-value="str('description')" :rows="3" @update:model-value="set('description', $event)" />
                </VtField>
                <div class="vm-ed-grid2">
                    <VtField :label="t('editor.props.price')">
                        <VtInput :model-value="str('price')" @update:model-value="set('price', $event)" />
                    </VtField>
                    <VtField :label="t('editor.props.oldPrice')">
                        <VtInput :model-value="str('old_price')" @update:model-value="set('old_price', $event)" />
                    </VtField>
                </div>
                <ImagePicker :model-value="str('image')" :label="t('editor.props.image')" :assets="assets" :catalog="catalog" @update:model-value="set('image', $event)" @uploaded="emit('uploaded', $event)" />
                <VtField :label="t('editor.props.link')">
                    <VtInput :model-value="str('href')" @update:model-value="set('href', $event)" />
                </VtField>
                <VtField :label="t('editor.props.label')">
                    <VtInput :model-value="str('button_label')" @update:model-value="set('button_label', $event)" />
                </VtField>
                <EdColor :model-value="(attrs.button_background as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.buttonBackground')" @update:model-value="set('button_background', $event ?? undefined)" />
            </template>

            <template v-if="type === 'survey'">
                <VtField :label="t('editor.props.question')">
                    <VtInput :model-value="str('question')" data-testid="prop-question" @update:model-value="set('question', $event)" />
                </VtField>
                <VtField :label="t('editor.props.surveyUrl')" :hint="t('editor.props.surveyHint')">
                    <VtInput :model-value="str('base_url')" @update:model-value="set('base_url', $event)" />
                </VtField>
                <p class="vm-ed-heading">{{ t('editor.props.answers') }}</p>
                <ul class="vm-ed-list">
                    <li v-for="(answer, index) in answers" :key="index">
                        <VtInput :model-value="answer" @update:model-value="setAnswer(index, String($event))" />
                        <button type="button" class="vm-ed-iconbtn" :disabled="answers.length <= 1" :aria-label="t('editor.blockTools.delete')" @click="removeAnswer(index)"><VmIcon name="trash" /></button>
                    </li>
                </ul>
                <VtButton size="sm" variant="ghost" :disabled="answers.length >= catalog.maxSurveyAnswers" @click="addAnswer"><VmIcon name="plus" /> {{ t('editor.props.addAnswer') }}</VtButton>
                <EdColor :model-value="(attrs.button_background as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.buttonBackground')" @update:model-value="set('button_background', $event ?? undefined)" />
            </template>

            <template v-if="type === 'footer'">
                <VtField :label="t('editor.props.company')">
                    <VtInput :model-value="str('company')" @update:model-value="set('company', $event)" />
                </VtField>
                <VtField :label="t('editor.props.address')" :hint="t('editor.props.addressHint')" :error="str('address').trim() === '' ? t('editor.props.addressRequired') : undefined">
                    <VtTextarea :model-value="str('address')" :rows="3" data-testid="prop-address" @update:model-value="set('address', $event)" />
                </VtField>
                <VtField :label="t('editor.props.footerText')">
                    <VtTextarea :model-value="str('content')" :rows="3" @update:model-value="set('content', $event)" />
                </VtField>
                <div class="vm-ed-grid2">
                    <VtField :label="t('editor.props.unsubscribeLabel')">
                        <VtInput :model-value="str('unsubscribe_label')" @update:model-value="set('unsubscribe_label', $event)" />
                    </VtField>
                    <VtField :label="t('editor.props.preferencesLabel')">
                        <VtInput :model-value="str('preferences_label')" @update:model-value="set('preferences_label', $event)" />
                    </VtField>
                </div>
                <p class="vm-ed-hint">{{ t('editor.props.footerLocked') }}</p>
                <EdColor :model-value="(attrs.color as string) ?? null" allow-none :swatches="catalog.swatches" :label="t('editor.props.textColor')" @update:model-value="set('color', $event ?? undefined)" />
            </template>

            <template v-if="type === 'unsubscribe'">
                <VtField :label="t('editor.props.label')">
                    <VtInput :model-value="str('content')" @update:model-value="set('content', $event)" />
                </VtField>
            </template>

            <template v-if="!['divider', 'spacer', 'html'].includes(type)">
                <span>{{ t('editor.props.align') }}</span>
                <EdSegment :model-value="str('align') || 'left'" :options="alignOptions" icon-only :label="t('editor.props.align')" testid="prop-align" @update:model-value="set('align', $event)" />
            </template>

            <p class="vm-ed-heading">{{ t('editor.props.display') }}</p>
            <template v-if="conditionable">
                <EdSwitch :model-value="attrs.hide_on_mobile === true" :label="t('editor.props.hideMobile')" testid="prop-hide-mobile" @update:model-value="set('hide_on_mobile', $event || undefined)" />
                <ConditionEditor :model-value="attrs.condition as never" :catalog="catalog" @update:model-value="set('condition', $event)" />
            </template>
            <p v-else class="vm-ed-hint">{{ t('editor.condition.complianceLocked') }}</p>
        </template>
    </div>
</template>
