<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, VtRadio, VtSelect } from '@venture/ui';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MergeTagPicker from '../../Components/Editor/MergeTagPicker.vue';
import type { MergeTagDef } from '../../Components/Editor/types';
import VmCard from '../../Components/Form/VmCard.vue';
import VmFieldGroup from '../../Components/Form/VmFieldGroup.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import VmStatusBadge from '../../Components/Studio/VmStatusBadge.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import '../../editor.css';
import { useFormat } from '../../composables/useFormat';
import VmTargetPicker, { type TargetOption } from '../../Components/Campaign/VmTargetPicker.vue';
import { useCodes } from '../../composables/useCodes';

/**
 * KAMPÁNY-LÉTREHOZÓ CHECKLIST (v1.1 · 5. modul).
 *
 * Hat szakasz: címzettek, feladó, tárgy + preheader, tartalom, ütemezés, A/B. Mindegyik
 * kész / hiányos állapota a SZERVER checklistjéből jön (`CampaignChecklist`); a jobb oldali
 * előellenőrzés a szigorú lábléc-szabályt, a hitelesített domaint és a megerősített
 * hozzájárulást mutatja. A küldés gombja csak mindezek teljesülésekor él — és a szerver az
 * indításnál UGYANEZT újra ellenőrzi. A küldés pillanatában a PreSendGate dönt.
 */
interface Section {
    key: 'recipients' | 'sender' | 'subject' | 'content' | 'schedule' | 'ab';
    done: boolean;
    [key: string]: unknown;
}

interface Check {
    key: 'domain' | 'compliance' | 'consent';
    ok: boolean;
    [key: string]: unknown;
}

interface Variant {
    uuid: string;
    label: string;
    subject: string | null;
    fromEmail: string | null;
    fromName: string | null;
    template: string | null;
    weight: number;
    winner: boolean;
    sent: number;
    opened: number;
    clicked: number;
}

const props = defineProps<{
    campaign: {
        uuid: string;
        name: string;
        subject: string;
        preheader: string | null;
        fromEmail: string | null;
        fromName: string | null;
        status: string;
        displayStatus: string;
        editable: boolean;
        templateUuid: string | null;
        /** UI-polírozás · 6: „new" = a kampány saját, helyben készült tartalma; „existing" = könyvtári sablon. */
        contentMode: 'new' | 'existing';
        ownTemplateUuid: string | null;
        targetLists: string[];
        targetSegments: string[];
        excludeLists: string[];
        excludeSegments: string[];
        scheduledAt: string | null;
        scheduledLocal: string | null;
        sendMode: string | null;
        timezone: string | null;
        winnerCriterion: string | null;
        winnerAfterMinutes: number;
        recipientCount: number;
        sentCount: number;
        failedCount: number;
        variantWeightTotal: number;
        variants: Variant[];
    };
    checklist: {
        sections: Section[];
        checks: Check[];
        ready: boolean;
        blockers: string[];
        sendingMode: { live: boolean; marketingEnabled: boolean; allowlistEnforced: boolean };
    };
    outcomes: Array<{ status: string; reason: string; count: number }>;
    templates: Array<{ uuid: string; name: string }>;
    // v1.1 · D284: a választó szerverről lapoz; itt csak a kiválasztottak neve + a darabszám.
    selectedTargets: { lists: TargetOption[]; segments: TargetOption[] };
    targetCounts: { lists: number; segments: number };
    domains: string[];
    mergeTags: MergeTagDef[];
    defaultTimezone: string;
    /** `null`: a keretezett becslés túllépte az időkorlátot (becslés nem elérhető). */
    estimate: number | null;
}>();

const { t } = useI18n();
const code = useCodes();
const format = useFormat();

/* ---------- az űrlap-állapot (a teljes kampány egy PUT-tal mentődik) ---------- */

const splitFrom = (email: string | null): { local: string; domain: string } => {
    const at = (email ?? '').lastIndexOf('@');

    return at > 0
        ? { local: (email ?? '').slice(0, at), domain: (email ?? '').slice(at + 1) }
        : { local: email ?? '', domain: props.domains[0] ?? '' };
};

const [date0, time0] = (props.campaign.scheduledLocal ?? '').split(' ');

const initial = () => ({
    name: props.campaign.name,
    subject: props.campaign.subject ?? '',
    preheader: props.campaign.preheader ?? '',
    fromLocal: splitFrom(props.campaign.fromEmail).local,
    fromDomain: splitFrom(props.campaign.fromEmail).domain,
    fromName: props.campaign.fromName ?? '',
    templateUuid: props.campaign.templateUuid ?? '',
    targetLists: [...props.campaign.targetLists],
    targetSegments: [...props.campaign.targetSegments],
    excludeLists: [...props.campaign.excludeLists],
    excludeSegments: [...props.campaign.excludeSegments],
    sendMode: props.campaign.sendMode ?? '',
    date: date0 ?? '',
    time: time0 ?? '09:00',
    timezone: props.campaign.timezone && props.campaign.timezone !== 'UTC' ? props.campaign.timezone : props.defaultTimezone,
    winnerCriterion: props.campaign.winnerCriterion ?? '',
    winnerAfterMinutes: String(props.campaign.winnerAfterMinutes || 240),
});

const form = ref(initial());
const saving = ref(false);
const saved = ref(JSON.stringify(form.value));
const dirty = computed(() => JSON.stringify(form.value) !== saved.value);

watch(
    () => props.campaign,
    () => {
        form.value = initial();
        saved.value = JSON.stringify(form.value);
        // A friss prop = a mentés válasza megérkezett (az `onFinish` mellett ez a biztos jel).
        saving.value = false;
    },
);

/* ---------- tartalom: új (helyben) vagy meglévő (könyvtár) ---------- */

const contentMode = ref<'new' | 'existing'>(props.campaign.contentMode);
/** D308: a tartalom FORRÁSA (új saját / meglévő) csak vázlatnál váltható — a szerver is így dönt. */
const contentSourceLocked = computed(() => readonly.value || props.campaign.status !== 'draft');

watch(
    () => props.campaign,
    () => {
        contentMode.value = props.campaign.contentMode;
    },
);

function chooseContent(mode: 'new' | 'existing'): void {
    contentMode.value = mode;
    // Az „új" ág a saját tartalomra áll vissza (ha már van); a „meglévő" ág választásra vár.
    form.value.templateUuid = mode === 'new' ? (props.campaign.ownTemplateUuid ?? '') : props.campaign.contentMode === 'existing' ? (props.campaign.templateUuid ?? '') : '';
}

/** A saját tartalom szerkesztője: előbb a nem mentett kampány-adat, aztán a szerkesztő. */
function openOwnContent(): void {
    const go = (): void => router.post(`/campaigns/${props.campaign.uuid}/content`);

    if (!dirty.value) {
        go();

        return;
    }

    saving.value = true;
    router.put(`/campaigns/${props.campaign.uuid}`, payload() as never, { preserveScroll: true, onSuccess: go, onFinish: () => (saving.value = false) });
}

const showExclude = ref(props.campaign.excludeLists.length + props.campaign.excludeSegments.length > 0);
const readonly = computed(() => !props.campaign.editable);

function payload(): Record<string, unknown> {
    const f = form.value;

    return {
        name: f.name.trim(),
        subject: f.subject,
        preheader: f.preheader || null,
        fromEmail: f.fromLocal.trim() && f.fromDomain ? `${f.fromLocal.trim()}@${f.fromDomain}` : null,
        fromName: f.fromName.trim() || null,
        templateUuid: f.templateUuid || null,
        targetLists: f.targetLists,
        targetSegments: f.targetSegments,
        excludeLists: f.excludeLists,
        excludeSegments: f.excludeSegments,
        sendMode: f.sendMode || null,
        scheduledLocal: f.sendMode === 'later' && f.date ? `${f.date} ${f.time || '09:00'}` : null,
        timezone: f.timezone || null,
        winnerCriterion: f.winnerCriterion || null,
        winnerAfterMinutes: Number(f.winnerAfterMinutes) || 240,
    };
}

function save(): void {
    saving.value = true;
    router.put(`/campaigns/${props.campaign.uuid}`, payload() as never, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            saved.value = JSON.stringify(form.value);
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

/* ---------- szakaszok ---------- */

const section = (key: Section['key']): Section => props.checklist.sections.find((item) => item.key === key) as Section;
const doneCount = computed(() => props.checklist.sections.filter((item) => item.done).length);
const open = ref<Section['key'] | null>(props.checklist.sections.find((item) => !item.done)?.key ?? null);

function toggle(key: Section['key']): void {
    open.value = open.value === key ? null : key;
}

/*
 | A NÉVTÁR: a kiválasztottak neve a kampánnyal jön, a többi a választó betöltött lapjaiból — így
 | az összegzés („Hírlevél · VIP — 1 234 címzett") lapozás után is nevet mutat, nem uuid-ot.
 */
const knownLists = ref<Record<string, TargetOption>>(Object.fromEntries(props.selectedTargets.lists.map((item) => [item.uuid, item])));
const knownSegments = ref<Record<string, TargetOption>>(Object.fromEntries(props.selectedTargets.segments.map((item) => [item.uuid, item])));

function remember(kind: 'lists' | 'segments', options: TargetOption[]): void {
    const store = kind === 'lists' ? knownLists : knownSegments;

    store.value = { ...store.value, ...Object.fromEntries(options.map((item) => [item.uuid, item])) };
}

const listName = (uuid: string): string => knownLists.value[uuid]?.name ?? uuid;
const segmentName = (uuid: string): string => knownSegments.value[uuid]?.name ?? uuid;

const summary = computed<Record<Section['key'], string>>(() => {
    const recipients = section('recipients');
    const sender = section('sender');
    const content = section('content');
    const schedule = section('schedule');
    const ab = section('ab');
    const template = content.template as { name: string; owned?: boolean } | null;

    return {
        recipients: [
            ...(form.value.targetLists.map(listName)),
            ...(form.value.targetSegments.map(segmentName)),
        ].join(' · ')
            ? `${[...form.value.targetLists.map(listName), ...form.value.targetSegments.map(segmentName)].join(' · ')} — ${recipients.estimate === null ? t('checklist.estimateUnavailable') : t('checklist.estimate', { count: format.number(Number(recipients.estimate)) })}`
            : t('checklist.recipientsMissing'),
        sender: sender.fromEmail
            ? `${props.campaign.fromName ? `${props.campaign.fromName} ` : ''}<${sender.fromEmail}> · ${sender.verified ? t('checklist.domainVerified') : t('checklist.domainUnverified')}`
            : t('checklist.senderMissing'),
        subject: props.campaign.subject || t('checklist.subjectMissing'),
        content: template ? `${template.owned ? t('checklist.ownContent') : template.name}${(content.missing as string[]).length ? ` · ${t('checklist.complianceMissing')}` : ''}` : t('checklist.contentMissing'),
        schedule:
            schedule.mode === 'now'
                ? t('checklist.sendNowSummary')
                : schedule.mode === 'later' && schedule.scheduledLocal
                  ? `${format.stamp(String(schedule.scheduledLocal))} (${schedule.timezone})${schedule.past ? ` · ${t('checklist.inPast')}` : ''}`
                  : t('checklist.scheduleMissing'),
        ab: Number(ab.variants) === 0 ? t('checklist.abNone') : t('checklist.abSummary', { count: Number(ab.variants), total: Number(ab.weightTotal) }),
    };
});

function toggleIn(kind: 'targetLists' | 'targetSegments' | 'excludeLists' | 'excludeSegments', uuid: string): void {
    const current = form.value[kind];

    form.value[kind] = current.includes(uuid) ? current.filter((item) => item !== uuid) : [...current, uuid];
}

/* ---------- tárgy + merge-tag ---------- */

const pickerFor = ref<'subject' | 'preheader' | null>(null);
const subjectRange = computed(() => (section('subject').recommended as [number, number]) ?? [30, 60]);
const subjectHint = computed(() => {
    const length = form.value.subject.length;
    const [min, max] = subjectRange.value;

    if (length === 0) return t('checklist.subjectHintEmpty', { min, max });
    if (length < min) return t('checklist.subjectHintShort', { min, max });
    if (length > max) return t('checklist.subjectHintLong', { min, max });

    return t('checklist.subjectHintOk');
});

function insertTag(value: string): void {
    if (pickerFor.value === 'subject') form.value.subject = `${form.value.subject}${form.value.subject.endsWith(' ') || !form.value.subject ? '' : ' '}${value}`;
    if (pickerFor.value === 'preheader') form.value.preheader = `${form.value.preheader}${form.value.preheader.endsWith(' ') || !form.value.preheader ? '' : ' '}${value}`;
    pickerFor.value = null;
}

/** A postaláda-előnézet: a merge-tag az alapértékével (ahogy a hiányzó adatú címzett látja). */
const previewText = (value: string): string => value.replace(/\{\{\s*[\w.]+\s*(?:\|\|\s*([^}]*))?\}\}/g, (_m, fallback: string | undefined) => (fallback ?? '').trim());

/* ---------- ütemezés ---------- */

const timezones = computed(() => {
    const supported = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
    const all = Array.from(new Set([form.value.timezone, props.defaultTimezone, 'UTC', ...supported])).filter(Boolean);

    return all.map((zone) => ({ value: zone, label: zone }));
});

/* ---------- A/B ---------- */

const variantForm = ref({ label: '', kind: 'subject', subject: '', templateUuid: '', fromLocal: '', fromDomain: props.domains[0] ?? '', fromName: '', weight: '50' });

const variantKinds = computed(() => [
    { value: 'subject', label: t('checklist.variantSubject') },
    { value: 'content', label: t('checklist.variantContent') },
    { value: 'sender', label: t('checklist.variantSender') },
]);

function addVariant(): void {
    const v = variantForm.value;

    router.post(
        `/campaigns/${props.campaign.uuid}/variants`,
        {
            label: v.label.trim(),
            subject: v.kind === 'subject' ? v.subject : null,
            templateUuid: v.kind === 'content' ? v.templateUuid || null : null,
            fromEmail: v.kind === 'sender' && v.fromLocal.trim() ? `${v.fromLocal.trim()}@${v.fromDomain}` : null,
            fromName: v.kind === 'sender' ? v.fromName || null : null,
            weight: Number(v.weight),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                variantForm.value = { ...variantForm.value, label: '', subject: '', templateUuid: '', fromLocal: '', fromName: '' };
            },
        },
    );
}

function removeVariant(uuid: string): void {
    router.delete(`/campaigns/${props.campaign.uuid}/variants/${uuid}`, { preserveScroll: true });
}

/* ---------- küldés ---------- */

const check = (key: Check['key']): Check => props.checklist.checks.find((item) => item.key === key) as Check;
const canSend = computed(() => props.checklist.ready && !dirty.value && props.campaign.editable);
const sendLabel = computed(() => (form.value.sendMode === 'later' ? t('checklist.scheduleAction') : t('checklist.sendNow')));

function transition(action: string): void {
    router.post(`/campaigns/${props.campaign.uuid}/transition`, { action }, { preserveScroll: true });
}

const sectionTitle = (key: string): string => t(`checklist.section.${key}`);
</script>

<template>
    <Head :title="campaign.name" />

    <AppLayout>
        <header class="vm-cl-head">
            <div class="vm-cl-head__text">
                <p class="vm-cl-eyebrow">{{ t('campaigns.eyebrow') }}</p>
                <h1 class="vt-text-display vm-wrap">{{ campaign.name }}</h1>
                <!-- A GÉPI állapot a `data-status`-ban (nyelvfüggetlen teszt-illesztés). -->
                <p class="vm-cl-status" data-testid="campaign-status" :data-status="campaign.status" :data-dirty="dirty ? 1 : 0" :data-saving="saving ? 1 : 0">
                    <VmStatusBadge :status="campaign.displayStatus" />
                    <span v-if="dirty" class="vm-cl-dirty">{{ t('checklist.unsaved') }}</span>
                </p>
            </div>
            <VtButton variant="ghost" @click="router.visit('/campaigns')">{{ t('checklist.backToList') }}</VtButton>
        </header>

        <p v-if="readonly" class="vm-banner vm-banner--warning" role="status" data-testid="campaign-locked">{{ t('campaigns.lockedReason') }}</p>

        <div class="vm-cl-layout">
            <VmCard class="vm-cl-main">
                <div class="vm-cl-progress-head">
                    <VtField :label="t('campaigns.name')" class="vm-cl-name">
                        <VtInput v-model="form.name" :disabled="readonly" data-testid="edit-name" />
                    </VtField>
                    <span class="vm-cl-count" data-testid="checklist-progress">{{ t('checklist.progress', { done: doneCount, total: checklist.sections.length }) }}</span>
                </div>
                <div class="vm-cl-bar" role="progressbar" :aria-valuenow="doneCount" aria-valuemin="0" :aria-valuemax="checklist.sections.length" :aria-label="t('checklist.progressLabel')">
                    <span :style="{ inlineSize: `${(doneCount / checklist.sections.length) * 100}%` }" />
                </div>

                <section
                    v-for="(item, index) in checklist.sections"
                    :key="item.key"
                    class="vm-cl-section"
                    :class="{ 'is-open': open === item.key }"
                    :data-testid="`section-${item.key}`"
                    :data-done="item.done ? 'true' : 'false'"
                >
                    <button
                        type="button"
                        class="vm-cl-section__head"
                        :aria-expanded="open === item.key"
                        :aria-controls="`section-body-${item.key}`"
                        :data-testid="`section-toggle-${item.key}`"
                        @click="toggle(item.key)"
                    >
                        <span class="vm-cl-mark" :class="item.done ? 'is-done' : 'is-todo'" aria-hidden="true">
                            <VmIcon v-if="item.done" name="check" />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <span class="vm-cl-section__text">
                            <strong>
                                {{ sectionTitle(item.key) }}
                                <small v-if="item.optional" class="vm-cl-optional">{{ t('checklist.optional') }}</small>
                            </strong>
                            <span class="vm-truncate" :class="{ 'vm-cl-todo': !item.done }" :title="summary[item.key]">{{ summary[item.key] }}</span>
                        </span>
                        <span class="vm-visually-hidden">{{ item.done ? t('checklist.done') : t('checklist.incomplete') }}</span>
                        <VmIcon name="chevron" class="vm-cl-chevron" />
                    </button>

                    <div v-if="open === item.key" :id="`section-body-${item.key}`" class="vm-cl-section__body">
                        <!-- 1. CÍMZETTEK -->
                        <template v-if="item.key === 'recipients'">
                            <p data-testid="campaign-estimate">{{ estimate === null ? t('checklist.estimateUnavailable') : t('campaigns.estimate', { count: format.number(estimate) }) }}</p>
                            <VmTargetPicker
                                kind="lists"
                                testid="target-list"
                                :legend="t('campaigns.lists')"
                                :model-value="form.targetLists"
                                :known="knownLists"
                                :readonly="readonly"
                                @toggle="toggleIn('targetLists', $event)"
                                @resolve="remember('lists', $event)"
                            >
                                <template v-if="!targetCounts.lists" #empty>{{ t('checklist.noLists') }}</template>
                            </VmTargetPicker>
                            <VmTargetPicker
                                kind="segments"
                                testid="target-segment"
                                :legend="t('campaigns.segments')"
                                :model-value="form.targetSegments"
                                :known="knownSegments"
                                :readonly="readonly"
                                @toggle="toggleIn('targetSegments', $event)"
                                @resolve="remember('segments', $event)"
                            >
                                <template v-if="!targetCounts.segments" #empty>
                                    <Link href="/audience/segments/new">{{ t('checklist.buildSegment') }}</Link>
                                </template>
                            </VmTargetPicker>
                            <!-- Nem <details>: a zárt tartalom méretlen dobozai a mérőnek (és a
                                 képernyőolvasónak) zavaróak; egy kapcsoló gomb + v-if tisztább. -->
                            <VtButton variant="ghost" size="sm" :aria-expanded="showExclude" data-testid="toggle-exclude" @click="showExclude = !showExclude">
                                {{ t('checklist.exclude') }}
                            </VtButton>
                            <div v-if="showExclude" class="vm-cl-exclude">
                                <VmTargetPicker
                                    kind="lists"
                                    testid="exclude-list"
                                    :legend="t('checklist.excludeLists')"
                                    :model-value="form.excludeLists"
                                    :known="knownLists"
                                    :readonly="readonly"
                                    :show-counts="false"
                                    @toggle="toggleIn('excludeLists', $event)"
                                    @resolve="remember('lists', $event)"
                                />
                                <VmTargetPicker
                                    kind="segments"
                                    testid="exclude-segment"
                                    :legend="t('checklist.excludeSegments')"
                                    :model-value="form.excludeSegments"
                                    :known="knownSegments"
                                    :readonly="readonly"
                                    :show-counts="false"
                                    @toggle="toggleIn('excludeSegments', $event)"
                                    @resolve="remember('segments', $event)"
                                />
                            </div>
                        </template>

                        <!-- 2. FELADÓ — csak hitelesített domain -->
                        <template v-else-if="item.key === 'sender'">
                            <p v-if="!domains.length" class="vm-banner vm-banner--warning" role="status">
                                {{ t('checklist.noVerifiedDomain') }} <Link href="/domains">{{ t('checklist.toDomains') }}</Link>
                            </p>
                            <VmFormSection>
                                <VmFormRow>
                                    <VtField :label="t('checklist.fromName')">
                                        <VtInput v-model="form.fromName" :disabled="readonly" data-testid="edit-from-name" />
                                    </VtField>
                                    <VtField :label="t('checklist.fromLocal')" :hint="t('campaigns.fromHint')">
                                        <VtInput v-model="form.fromLocal" :disabled="readonly" data-testid="edit-from" />
                                    </VtField>
                                    <VtField :label="t('checklist.fromDomain')">
                                        <VtSelect
                                            v-model="form.fromDomain"
                                            :options="domains.map((domain) => ({ value: domain, label: `@${domain}` }))"
                                            :disabled="readonly || !domains.length"
                                            data-testid="edit-from-domain"
                                        />
                                    </VtField>
                                </VmFormRow>
                            </VmFormSection>
                        </template>

                        <!-- 3. TÁRGY + PREHEADER -->
                        <template v-else-if="item.key === 'subject'">
                            <VmFormSection>
                                <VtField :label="t('campaigns.subject')" :hint="subjectHint">
                                    <div class="vm-cl-tagged">
                                        <VtInput v-model="form.subject" :disabled="readonly" data-testid="edit-subject" />
                                        <VtButton variant="ghost" :disabled="readonly" :aria-label="t('checklist.insertMergeTag')" data-testid="subject-merge-tag" @click="pickerFor = pickerFor === 'subject' ? null : 'subject'">
                                            <VmIcon name="braces" />
                                        </VtButton>
                                    </div>
                                </VtField>
                                <p class="vm-cl-counter" data-testid="subject-length">{{ form.subject.length }} / 150</p>
                                <VtField :label="t('checklist.preheader')" :hint="t('checklist.preheaderHint')">
                                    <div class="vm-cl-tagged">
                                        <VtInput v-model="form.preheader" :disabled="readonly" data-testid="edit-preheader" />
                                        <VtButton variant="ghost" :disabled="readonly" :aria-label="t('checklist.insertMergeTag')" @click="pickerFor = pickerFor === 'preheader' ? null : 'preheader'">
                                            <VmIcon name="braces" />
                                        </VtButton>
                                    </div>
                                </VtField>
                                <p class="vm-cl-counter">{{ form.preheader.length }} / 100</p>
                                <MergeTagPicker v-if="pickerFor" :tags="mergeTags" @insert="insertTag" @close="pickerFor = null" />

                                <div class="vm-cl-inbox" :aria-label="t('checklist.inboxPreview')" role="group">
                                    <span class="vm-cl-inbox__avatar" aria-hidden="true">{{ (form.fromName || form.fromLocal || '?').slice(0, 2).toUpperCase() }}</span>
                                    <span class="vm-cl-inbox__text">
                                        <strong class="vm-truncate">{{ form.fromName || form.fromLocal || '—' }}</strong>
                                        <span class="vm-truncate">{{ previewText(form.subject) || '—' }}</span>
                                        <small class="vm-truncate">{{ previewText(form.preheader) }}</small>
                                    </span>
                                </div>
                            </VmFormSection>
                        </template>

                        <!-- 4. TARTALOM — UI-polírozás · 6: két ág, az ÚJ (helyben készülő) az alapértelmezett. -->
                        <template v-else-if="item.key === 'content'">
                            <VmFieldGroup :legend="t('checklist.contentMode.legend')">
                                <VtRadio
                                    :model-value="contentMode"
                                    value="new"
                                    name="content-mode"
                                    :disabled="contentSourceLocked"
                                    data-testid="content-mode-new"
                                    @update:model-value="chooseContent('new')"
                                >
                                    {{ t('checklist.contentMode.new') }}
                                </VtRadio>
                                <VtRadio
                                    :model-value="contentMode"
                                    value="existing"
                                    name="content-mode"
                                    :disabled="contentSourceLocked"
                                    data-testid="content-mode-existing"
                                    @update:model-value="chooseContent('existing')"
                                >
                                    {{ t('checklist.contentMode.existing') }}
                                </VtRadio>
                            </VmFieldGroup>
                            <p v-if="!readonly && campaign.status !== 'draft'" class="vm-cl-hint" role="note" data-testid="content-draft-only">{{ t('checklist.contentMode.draftOnly') }}</p>

                            <template v-if="contentMode === 'new'">
                                <p class="vm-cl-hint">{{ t('checklist.contentMode.newHint') }}</p>
                                <div class="vm-cluster">
                                    <VtButton v-if="!contentSourceLocked" :disabled="saving" data-testid="content-create" @click="openOwnContent">
                                        <VmIcon :name="campaign.ownTemplateUuid ? 'layout' : 'plus'" />
                                        {{ campaign.ownTemplateUuid ? t('checklist.contentMode.edit') : t('checklist.contentMode.create') }}
                                    </VtButton>
                                    <Link v-else-if="campaign.ownTemplateUuid" :href="`/campaigns/${campaign.uuid}/content`" data-testid="content-open-own">{{ t('checklist.openEditor') }}</Link>
                                </div>
                            </template>

                            <template v-else>
                                <p v-if="!templates.length" class="vm-cl-hint" data-testid="content-library-empty">{{ t('checklist.contentMode.libraryEmpty') }}</p>
                                <VmFormSection v-else>
                                    <VtField :label="t('campaigns.template')" :hint="t('checklist.templateHint')">
                                        <VtSelect
                                            v-model="form.templateUuid"
                                            :options="[{ value: '', label: t('campaigns.noTemplate') }, ...templates.map((tpl) => ({ value: tpl.uuid, label: tpl.name }))]"
                                            :disabled="readonly"
                                            data-testid="edit-template"
                                        />
                                    </VtField>
                                </VmFormSection>
                                <div class="vm-cluster">
                                    <Link v-if="campaign.templateUuid && campaign.contentMode === 'existing'" :href="`/templates/${campaign.templateUuid}`" data-testid="content-open-editor">
                                        {{ t('checklist.openEditor') }}
                                    </Link>
                                    <Link href="/templates" data-testid="content-new-template">{{ t('checklist.newTemplate') }}</Link>
                                </div>
                            </template>

                            <ul v-if="(item.missing as string[]).length && item.template" class="vm-cl-missing" data-testid="content-missing">
                                <li v-for="missing in item.missing as string[]" :key="missing">{{ t(`checklist.missing.${missing}`) }}</li>
                            </ul>
                        </template>

                        <!-- 5. ÜTEMEZÉS -->
                        <template v-else-if="item.key === 'schedule'">
                            <VmFieldGroup :legend="t('checklist.whenLegend')">
                                <VtRadio v-model="form.sendMode" value="now" name="send-mode" :disabled="readonly" data-testid="schedule-now">
                                    {{ t('checklist.sendNowOption') }}
                                </VtRadio>
                                <VtRadio v-model="form.sendMode" value="later" name="send-mode" :disabled="readonly" data-testid="schedule-later">
                                    {{ t('checklist.sendLaterOption') }}
                                </VtRadio>
                            </VmFieldGroup>
                            <VmFormSection v-if="form.sendMode === 'later'">
                                <VmFormRow>
                                    <VtField :label="t('checklist.date')">
                                        <VtInput v-model="form.date" type="date" :disabled="readonly" data-testid="schedule-date" />
                                    </VtField>
                                    <VtField :label="t('checklist.time')">
                                        <VtInput v-model="form.time" type="time" :disabled="readonly" data-testid="schedule-time" />
                                    </VtField>
                                    <VtField :label="t('campaigns.timezone')" :hint="t('checklist.timezoneHint')">
                                        <VtSelect v-model="form.timezone" :options="timezones" :disabled="readonly" data-testid="schedule-timezone" />
                                    </VtField>
                                </VmFormRow>
                            </VmFormSection>
                        </template>

                        <!-- 6. A/B TESZT (opcionális) -->
                        <template v-else-if="item.key === 'ab'">
                            <p>{{ t('checklist.abHint') }}</p>
                            <p
                                v-if="campaign.variants.length"
                                class="vm-banner"
                                :class="{ 'vm-banner--warning': campaign.variantWeightTotal !== 100 || campaign.variants.length < 2 }"
                                data-testid="variant-total"
                            >
                                {{ t('campaigns.weightTotal', { total: campaign.variantWeightTotal }) }}
                                <template v-if="campaign.variants.length === 1">{{ t('campaigns.variantSingle') }}</template>
                            </p>

                            <ul v-if="campaign.variants.length" class="vm-cl-variants">
                                <li v-for="variant in campaign.variants" :key="variant.uuid" :data-testid="`variant-${variant.label}`">
                                    <strong>{{ variant.label }}</strong>
                                    <span class="vm-truncate">
                                        {{ variant.subject ?? variant.template ?? variant.fromEmail ?? '—' }}
                                    </span>
                                    <span>{{ variant.weight }}%</span>
                                    <span :data-testid="`variant-stats-${variant.label}`">{{ variant.sent }} / {{ variant.opened }} / {{ variant.clicked }}</span>
                                    <span v-if="variant.winner" :data-testid="`winner-${variant.uuid}`">{{ t('campaigns.winner') }}</span>
                                    <VtButton v-if="!readonly" variant="ghost" size="sm" :data-testid="`variant-remove-${variant.label}`" @click="removeVariant(variant.uuid)">
                                        {{ t('campaigns.removeVariant') }}
                                    </VtButton>
                                </li>
                            </ul>

                            <VmFormSection v-if="!readonly">
                                <VmFormRow>
                                    <VtField :label="t('campaigns.variantLabel')">
                                        <VtInput v-model="variantForm.label" data-testid="variant-label" placeholder="B" />
                                    </VtField>
                                    <VtField :label="t('checklist.variantKind')">
                                        <VtSelect v-model="variantForm.kind" :options="variantKinds" data-testid="variant-kind" />
                                    </VtField>
                                    <VtField :label="t('campaigns.weight')" :hint="t('campaigns.weightHint')">
                                        <VtInput v-model="variantForm.weight" type="number" data-testid="variant-weight" />
                                    </VtField>
                                </VmFormRow>
                                <VtField v-if="variantForm.kind === 'subject'" :label="t('campaigns.subject')">
                                    <VtInput v-model="variantForm.subject" data-testid="variant-subject" />
                                </VtField>
                                <VtField v-else-if="variantForm.kind === 'content'" :label="t('campaigns.template')">
                                    <VtSelect
                                        v-model="variantForm.templateUuid"
                                        :options="[{ value: '', label: t('campaigns.noTemplate') }, ...templates.map((tpl) => ({ value: tpl.uuid, label: tpl.name }))]"
                                        data-testid="variant-template"
                                    />
                                </VtField>
                                <VmFormRow v-else>
                                    <VtField :label="t('checklist.fromName')">
                                        <VtInput v-model="variantForm.fromName" data-testid="variant-from-name" />
                                    </VtField>
                                    <VtField :label="t('checklist.fromLocal')">
                                        <VtInput v-model="variantForm.fromLocal" data-testid="variant-from" />
                                    </VtField>
                                    <VtField :label="t('checklist.fromDomain')">
                                        <VtSelect v-model="variantForm.fromDomain" :options="domains.map((domain) => ({ value: domain, label: `@${domain}` }))" />
                                    </VtField>
                                </VmFormRow>
                                <VmFormActions>
                                    <VtButton
                                        variant="subtle"
                                        :disabled="!variantForm.label.trim() || (variantForm.kind === 'subject' && !variantForm.subject.trim()) || (variantForm.kind === 'content' && !variantForm.templateUuid) || (variantForm.kind === 'sender' && !variantForm.fromLocal.trim())"
                                        data-testid="variant-submit"
                                        @click="addVariant"
                                    >
                                        {{ t('campaigns.addVariant') }}
                                    </VtButton>
                                </VmFormActions>
                            </VmFormSection>

                            <VmFormRow>
                                <VtField :label="t('checklist.winnerCriterion')" :hint="t('checklist.winnerHint')">
                                    <VtSelect
                                        v-model="form.winnerCriterion"
                                        :options="[
                                            { value: '', label: t('checklist.winnerManual') },
                                            { value: 'click_rate', label: t('checklist.winnerClick') },
                                            { value: 'open_rate', label: t('checklist.winnerOpen') },
                                        ]"
                                        :disabled="readonly"
                                        data-testid="winner-criterion"
                                    />
                                </VtField>
                                <VtField :label="t('checklist.winnerAfter')">
                                    <VtInput v-model="form.winnerAfterMinutes" type="number" :disabled="readonly" data-testid="winner-after" />
                                </VtField>
                            </VmFormRow>
                        </template>

                        <VmFormActions v-if="!readonly && item.key !== 'ab'">
                            <VtButton :disabled="saving || !dirty" :data-testid="`section-save-${item.key}`" @click="save">{{ t('checklist.save') }}</VtButton>
                        </VmFormActions>
                        <VmFormActions v-else-if="!readonly">
                            <VtButton :disabled="saving || !dirty" data-testid="section-save-ab" @click="save">{{ t('checklist.saveWinner') }}</VtButton>
                        </VmFormActions>
                    </div>
                </section>
            </VmCard>

            <aside class="vm-cl-side">
                <VmCard glass glow>
                    <h2>{{ t('checklist.preflight') }}</h2>
                    <ul class="vm-cl-checks" data-testid="preflight">
                        <li v-for="item in checklist.checks" :key="item.key" :data-testid="`check-${item.key}`" :data-ok="item.ok ? 'true' : 'false'">
                            <span class="vm-cl-mark vm-cl-mark--sm" :class="item.ok ? 'is-done' : 'is-fail'" aria-hidden="true">
                                <VmIcon :name="item.ok ? 'check' : 'warning'" />
                            </span>
                            <span class="vm-cl-checks__text">
                                <strong>{{ t(`checklist.check.${item.key}`) }}</strong>
                                <small v-if="item.key === 'consent' && item.unavailable">{{ t('checklist.estimateUnavailable') }}</small>
                                <small v-else-if="item.key === 'consent'">{{ t('checklist.consentCount', { confirmed: format.number(Number(item.confirmed)), total: format.number(Number(item.total)) }) }}</small>
                                <small v-else-if="item.key === 'domain' && !item.ok">{{ (item.unverified as string[]).length ? t('checklist.unverifiedList', { domains: (item.unverified as string[]).join(', ') }) : t('checklist.senderMissing') }}</small>
                                <small v-else-if="item.key === 'compliance' && !item.ok">
                                    {{ (item.missing as string[]).length ? (item.missing as string[]).map((key) => t(`checklist.missing.${key}`)).join(' · ') : t('checklist.contentMissing') }}
                                </small>
                                <small v-else>{{ t(`checklist.checkOk.${item.key}`) }}</small>
                            </span>
                        </li>
                    </ul>

                    <p class="vm-cl-mode" data-testid="sending-mode">
                        {{ checklist.sendingMode.live && !checklist.sendingMode.allowlistEnforced && checklist.sendingMode.marketingEnabled ? t('checklist.modeLive') : t('checklist.modeRestricted') }}
                    </p>

                    <div class="vm-cl-actions">
                        <template v-if="campaign.editable">
                            <VtButton glow :disabled="!canSend" data-testid="campaign-send" @click="transition('send')">
                                <VmIcon name="send" /> {{ sendLabel }}
                            </VtButton>
                            <VtButton v-if="campaign.status === 'scheduled'" variant="ghost" data-testid="action-unschedule" @click="transition('unschedule')">{{ t('campaigns.unschedule') }}</VtButton>
                        </template>
                        <VtButton v-if="campaign.status === 'sending'" variant="ghost" data-testid="action-pause" @click="transition('pause')">{{ t('campaigns.pause') }}</VtButton>
                        <VtButton v-if="campaign.status === 'paused'" data-testid="action-resume" @click="transition('resume')">{{ t('campaigns.resume') }}</VtButton>
                        <VtButton v-if="['draft', 'scheduled', 'paused'].includes(campaign.status)" variant="ghost" data-testid="action-cancel" @click="transition('cancel')">{{ t('checklist.cancel') }}</VtButton>
                    </div>
                    <p v-if="campaign.editable && !canSend" class="vm-cl-why" data-testid="send-why">
                        {{ dirty ? t('checklist.whyUnsaved') : t('checklist.whyIncomplete') }}
                    </p>
                </VmCard>

                <VmCard v-if="outcomes.length" data-testid="campaign-outcomes">
                    <h2>{{ t('checklist.outcomes') }}</h2>
                    <p>{{ t('checklist.outcomeCounts', { sent: campaign.sentCount, failed: campaign.failedCount, total: campaign.recipientCount }) }}</p>
                    <ul class="vm-cl-outcomes">
                        <li v-for="row in outcomes" :key="`${row.status}-${row.reason}`" :data-testid="`outcome-${row.status}`" :data-reason="row.reason">
                            <span>{{ t(`checklist.recipientStatus.${row.status}`) }}</span>
                            <code v-if="row.reason" :title="row.reason">{{ code('reason', row.reason) }}</code>
                            <strong>{{ format.number(row.count) }}</strong>
                        </li>
                    </ul>
                    <Link :href="`/analytics/campaigns/${campaign.uuid}`">{{ t('checklist.openReport') }}</Link>
                </VmCard>
            </aside>
        </div>
    </AppLayout>
</template>

<style scoped>
.vm-cl-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--vt-space-4);
}

.vm-cl-head__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-cl-head h1,
.vm-cl-status {
    margin: 0;
}

.vm-cl-eyebrow {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
    font-weight: var(--vt-weight-semibold);
    letter-spacing: var(--vt-tracking-wider);
    text-transform: uppercase;
}

.vm-cl-status {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}

.vm-cl-dirty {
    color: var(--vt-color-warning);
    font-size: var(--vt-fs-body-sm);
}

.vm-cl-layout {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--vt-space-6);
}

.vm-cl-main {
    flex: 3 1 calc(var(--vt-space-32) * 4);
}

.vm-cl-side {
    display: flex;
    flex: 1 1 calc(var(--vt-space-32) * 2);
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-cl-progress-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--vt-space-4);
}

.vm-cl-name {
    flex: 1 1 calc(var(--vt-space-32) * 2);
}

.vm-cl-count {
    color: var(--vt-color-warning);
    font-size: var(--vt-fs-body-sm);
    font-weight: var(--vt-weight-semibold);
}

.vm-cl-bar {
    block-size: var(--vt-space-1);
    border-radius: var(--vt-radius-full);
    background-color: var(--vt-glass-thin-fill);
    overflow: hidden;
}

.vm-cl-bar span {
    display: block;
    block-size: 100%;
    background-image: var(--vm-glow-linear);
    transition: inline-size var(--vt-duration-base) var(--vt-ease-standard);
}

.vm-cl-section {
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-lg);
    background-color: var(--vt-glass-thin-fill);
    min-width: 0;
}

.vm-cl-section.is-open {
    border-color: var(--vt-accent);
}

.vm-cl-section__head {
    display: flex;
    align-items: center;
    gap: var(--vt-space-4);
    inline-size: 100%;
    padding: var(--vt-space-4);
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: start;
    cursor: pointer;
}

.vm-cl-section__head:focus-visible {
    outline: none;
    box-shadow: var(--vt-ring);
    border-radius: var(--vt-radius-lg);
}

.vm-cl-section__text {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-cl-section__text > span {
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-cl-section__text > span.vm-cl-todo {
    color: var(--vt-color-warning);
}

.vm-cl-optional {
    margin-inline-start: var(--vt-space-2);
    padding: 0 var(--vt-space-2);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-full);
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
    font-weight: var(--vt-weight-regular);
}

.vm-cl-chevron {
    flex: 0 0 auto;
    transition: transform var(--vt-duration-fast) var(--vt-ease-standard);
}

.is-open .vm-cl-chevron {
    transform: rotate(180deg);
}

.vm-cl-section__body {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    padding: 0 var(--vt-space-4) var(--vt-space-4);
    min-width: 0;
}

.vm-cl-mark {
    display: inline-grid;
    flex: 0 0 auto;
    place-items: center;
    inline-size: var(--vt-space-8);
    block-size: var(--vt-space-8);
    border-radius: var(--vt-radius-full);
    font-weight: var(--vt-weight-semibold);
}

.vm-cl-mark--sm {
    inline-size: var(--vt-space-6);
    block-size: var(--vt-space-6);
}

.vm-cl-mark.is-done {
    background-color: var(--vt-color-success);
    color: var(--vt-on-accent);
}

.vm-cl-mark.is-todo {
    border: thin solid var(--vt-color-warning);
    color: var(--vt-color-warning);
}

.vm-cl-mark.is-fail {
    border: thin solid var(--vt-color-danger);
    color: var(--vt-color-danger);
}

.vm-cl-tagged {
    display: flex;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-cl-tagged > :first-child {
    flex: 1 1 auto;
    min-width: 0;
}

.vm-cl-counter {
    margin: calc(var(--vt-space-3) * -1) 0 0;
    font-family: var(--vt-font-mono);
    font-size: var(--vt-fs-caption);
    color: var(--vt-text-subtle);
    text-align: end;
}

.vm-cl-inbox {
    display: flex;
    gap: var(--vt-space-3);
    padding: var(--vt-space-4);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background-color: var(--vt-bg);
    min-width: 0;
}

.vm-cl-inbox__avatar {
    display: inline-grid;
    flex: 0 0 auto;
    place-items: center;
    inline-size: var(--vt-space-8);
    block-size: var(--vt-space-8);
    border-radius: var(--vt-radius-md);
    background-color: var(--vt-surface-2);
    font-size: var(--vt-fs-caption);
    font-weight: var(--vt-weight-semibold);
}

.vm-cl-inbox__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-cl-inbox__text small {
    color: var(--vt-text-muted);
}

.vm-cl-hint {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-cl-exclude {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
}

.vm-cl-missing,
.vm-cl-checks,
.vm-cl-outcomes,
.vm-cl-variants {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-cl-missing li {
    color: var(--vt-color-warning);
}

.vm-cl-checks li {
    display: flex;
    align-items: flex-start;
    gap: var(--vt-space-3);
}

.vm-cl-checks__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-cl-checks__text small,
.vm-cl-mode,
.vm-cl-why {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
    overflow-wrap: anywhere;
}

.vm-cl-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-3);
}

.vm-cl-variants li,
.vm-cl-outcomes li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-cl-variants li > .vm-truncate {
    flex: 1 1 calc(var(--vt-space-32) * 1.5);
}

.vm-cl-outcomes li > span {
    flex: 1 1 auto;
}
</style>
