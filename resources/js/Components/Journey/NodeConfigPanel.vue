<script setup lang="ts">
import { VtButton, VtField, VtInput, VtSelect } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import ConditionFields from './ConditionFields.vue';
import { branches, ICONS, isTerminal, isTrigger, outputs, splitMinutes, UNIT_MINUTES, variants, type CatalogDef, type Config, type Issue, type JNode, type NodeStat, type Option, type Options } from './catalog';

/*
 | A JOBB OLDALI KONFIGURÁCIÓS PANEL (v1.1 · 6. modul): a kijelölt csomópont statisztikája,
 | neve, típus-specifikus beállításai, a kimenetek bekötése (billentyűzettel is — a húzás nem
 | lehet az egyetlen út), a csomópont hibái, duplikálás és törlés.
 */
const props = defineProps<{
    node: JNode;
    targets: Option[];
    connections: Record<string, string>;
    options: Options;
    catalog: CatalogDef;
    stats: NodeStat | null;
    issues: Issue[];
    canChooseEndpoint: boolean;
}>();

const emit = defineEmits<{
    config: [value: Config];
    label: [value: string];
    connect: [handle: string, target: string | null];
    duplicate: [];
    remove: [];
}>();

const { t } = useI18n();

const config = computed(() => props.node.config);
const trigger = computed(() => isTrigger(props.node.type));

function set(key: string, value: unknown): void {
    emit('config', { ...config.value, [key]: value });
}

function choose(list: Option[]): Option[] {
    return list;
}

const unitOptions = computed(() => (['minutes', 'hours', 'days'] as const).map((value) => ({ value, label: t(`journeyCanvas.units.${value}`) })));

function duration(key: string): { amount: number; unit: 'minutes' | 'hours' | 'days' } {
    return splitMinutes(Number(config.value[key] ?? 0));
}

function setDuration(key: string, amount: number, unit: 'minutes' | 'hours' | 'days'): void {
    set(key, Math.max(0, Math.round(amount * UNIT_MINUTES[unit])));
}

function setBranch(index: number, patch: Record<string, unknown>): void {
    const next = branches(config.value).map((branch, i) => (i === index ? { ...branch, ...patch } : branch));

    set('branches', next);
}

function addBranch(): void {
    const taken = new Set(branches(config.value).map((branch) => branch.key));
    let n = branches(config.value).length + 1;

    while (taken.has(`b${n}`)) n++;

    set('branches', [...branches(config.value), { key: `b${n}`, label: '', condition: { type: 'opened_email', within_hours: 168 } }]);
}

function removeBranch(index: number): void {
    set(
        'branches',
        branches(config.value).filter((_, i) => i !== index),
    );
}

function setVariant(index: number, weight: number): void {
    set(
        'variants',
        variants(config.value).map((variant, i) => (i === index ? { ...variant, weight } : variant)),
    );
}

function addVariant(): void {
    const keys = 'abcde';
    const taken = new Set(variants(config.value).map((variant) => variant.key));
    const key = [...keys].find((k) => !taken.has(k));

    if (key) set('variants', [...variants(config.value), { key, weight: 0 }]);
}

function removeVariant(index: number): void {
    set(
        'variants',
        variants(config.value).filter((_, i) => i !== index),
    );
}

const weightTotal = computed(() => variants(config.value).reduce((sum, variant) => sum + Number(variant.weight ?? 0), 0));

const outputList = computed(() => outputs(props.node.type, config.value));

function outputLabel(handle: string): string {
    if (handle === 'out') return t('journeyCanvas.handles.next');
    if (['yes', 'no', 'timeout', 'else'].includes(handle)) return t(`journeyCanvas.handles.${handle}`);

    const branch = branches(config.value).find((item) => item.key === handle);

    if (branch) return branch.label ? String(branch.label) : handle;

    return t('journeyCanvas.handles.variant', { key: handle.toUpperCase() });
}

const targetOptions = computed(() => [{ value: '', label: t('journeyCanvas.config.notConnected') }, ...props.targets]);

function issueText(issue: Issue): string {
    return t(`journeyCanvas.issues.${issue.code}`, { field: issue.field ?? '' });
}
</script>

<template>
    <div class="vm-jcfg" data-testid="node-config">
        <header class="vm-jcfg__head">
            <span class="vm-jcfg__icon"><VmIcon :name="ICONS[node.type] ?? 'plus'" /></span>
            <span class="vm-jcfg__titles">
                <span class="vm-jcfg__kind">{{ t(`journeyCanvas.nodes.${node.type}`) }}</span>
                <strong class="vm-jcfg__name">{{ node.label || t(`journeyCanvas.nodes.${node.type}`) }}</strong>
            </span>
        </header>

        <dl v-if="stats" class="vm-jcfg__stats" data-testid="node-stats">
            <div>
                <dt>{{ t('journeyCanvas.stats.entered') }}</dt>
                <dd data-testid="stat-entered">{{ stats.entered }}</dd>
            </div>
            <div class="vm-jcfg__stat--waiting">
                <dt>{{ t('journeyCanvas.stats.waiting') }}</dt>
                <dd data-testid="stat-waiting">{{ stats.waiting }}</dd>
            </div>
            <div class="vm-jcfg__stat--moved">
                <dt>{{ t('journeyCanvas.stats.movedOn') }}</dt>
                <dd data-testid="stat-moved">{{ stats.movedOn }}</dd>
            </div>
        </dl>

        <ul v-if="issues.length" class="vm-jcfg__issues" data-testid="node-issues">
            <li v-for="(issue, index) in issues" :key="index" :class="`vm-jcfg__issue--${issue.severity}`">
                <VmIcon name="warning" /><span>{{ issueText(issue) }}</span>
            </li>
        </ul>

        <VtField :label="t('journeyCanvas.config.label')">
            <VtInput :model-value="node.label" :placeholder="t(`journeyCanvas.nodes.${node.type}`)" data-testid="cfg-label" @update:model-value="emit('label', $event)" />
        </VtField>

        <!-- TRIGGEREK -->
        <VtField v-if="node.type === 'list_subscribe'" :label="t('journeyCanvas.config.list')">
            <VtSelect :model-value="String(config.list ?? '')" :options="choose(options.lists)" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-list" @update:model-value="set('list', String($event))" />
        </VtField>

        <VtField v-if="node.type === 'tag_added' || node.type === 'tag_removed'" :label="t('journeyCanvas.config.tag')">
            <VtSelect :model-value="String(config.tag ?? '')" :options="options.tags" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-tag" @update:model-value="set('tag', String($event))" />
        </VtField>

        <template v-if="node.type === 'field_changed' || node.type === 'date_anniversary'">
            <VtField :label="t('journeyCanvas.config.field')">
                <VtSelect :model-value="String(config.field ?? '')" :options="options.fields" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-field" @update:model-value="set('field', String($event))" />
            </VtField>
            <VtField v-if="node.type === 'date_anniversary'" :label="t('journeyCanvas.config.dateMode')">
                <VtSelect
                    :model-value="String(config.mode ?? 'anniversary')"
                    :options="catalog.dateModes.map((value) => ({ value, label: t(`journeyCanvas.dateModes.${value}`) }))"
                    data-testid="cfg-date-mode"
                    @update:model-value="set('mode', String($event))"
                />
            </VtField>
            <VtField v-if="node.type === 'date_anniversary'" :label="t('journeyCanvas.config.offsetDays')" :hint="t('journeyCanvas.config.offsetHint')">
                <VtInput type="number" :model-value="Number(config.offset_days ?? 0)" data-testid="cfg-offset" @update:model-value="set('offset_days', Number($event))" />
            </VtField>
        </template>

        <VtField v-if="node.type === 'form_signup'" :label="t('journeyCanvas.config.form')" :hint="t('journeyCanvas.config.anyHint')">
            <VtSelect :model-value="String(config.form ?? '')" :options="[{ value: '', label: t('journeyCanvas.config.any') }, ...options.forms]" data-testid="cfg-form" @update:model-value="set('form', String($event))" />
        </VtField>

        <VtField v-if="node.type === 'api_event'" :label="t('journeyCanvas.config.event')" :hint="t('journeyCanvas.config.eventHint')">
            <VtInput :model-value="String(config.event ?? '')" placeholder="signup" data-testid="cfg-event" @update:model-value="set('event', $event)" />
        </VtField>

        <template v-if="node.type === 'campaign_interaction'">
            <VtField :label="t('journeyCanvas.config.interaction')">
                <VtSelect
                    :model-value="String(config.interaction ?? 'open')"
                    :options="[
                        { value: 'open', label: t('journeyCanvas.config.interactionOpen') },
                        { value: 'click', label: t('journeyCanvas.config.interactionClick') },
                    ]"
                    data-testid="cfg-interaction"
                    @update:model-value="set('interaction', String($event))"
                />
            </VtField>
            <VtField :label="t('journeyCanvas.config.campaign')" :hint="t('journeyCanvas.config.anyHint')">
                <VtSelect :model-value="String(config.campaign ?? '')" :options="[{ value: '', label: t('journeyCanvas.config.any') }, ...options.campaigns]" data-testid="cfg-campaign" @update:model-value="set('campaign', String($event))" />
            </VtField>
        </template>

        <VtField v-if="node.type === 'segment_entered'" :label="t('journeyCanvas.config.segment')">
            <VtSelect :model-value="String(config.segment ?? '')" :options="options.segments" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-segment" @update:model-value="set('segment', String($event))" />
        </VtField>

        <VtField v-if="node.type === 'inbound_email'" :label="t('journeyCanvas.config.route')" :hint="t('journeyCanvas.config.anyHint')">
            <VtSelect :model-value="String(config.route ?? '')" :options="[{ value: '', label: t('journeyCanvas.config.any') }, ...options.routes]" data-testid="cfg-route" @update:model-value="set('route', String($event))" />
        </VtField>

        <!-- AKCIÓK -->
        <template v-if="node.type === 'send_email'">
            <VtField :label="t('journeyCanvas.config.template')">
                <VtSelect :model-value="String(config.template ?? '')" :options="options.templates" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-template" @update:model-value="set('template', String($event))" />
            </VtField>
            <VtField :label="t('journeyCanvas.config.fromEmail')" :hint="t('journeyCanvas.config.fromHint')">
                <VtInput type="email" :model-value="String(config.from_email ?? '')" data-testid="cfg-from" @update:model-value="set('from_email', $event)" />
            </VtField>
            <VtField :label="t('journeyCanvas.config.subject')" :hint="t('journeyCanvas.config.subjectHint')">
                <VtInput :model-value="String(config.subject ?? '')" data-testid="cfg-subject" @update:model-value="set('subject', $event)" />
            </VtField>
        </template>

        <template v-if="node.type === 'wait'">
            <VtField :label="t('journeyCanvas.config.waitMode')">
                <VtSelect
                    :model-value="String(config.mode ?? 'time')"
                    :options="[
                        { value: 'time', label: t('journeyCanvas.config.waitTime') },
                        { value: 'event', label: t('journeyCanvas.config.waitEvent') },
                    ]"
                    data-testid="cfg-wait-mode"
                    @update:model-value="emit('config', String($event) === 'event' ? { mode: 'event', event: 'opened', timeout_minutes: 4320 } : { minutes: 1440 })"
                />
            </VtField>

            <VtField v-if="config.mode === 'event'" :label="t('journeyCanvas.config.waitFor')">
                <VtSelect
                    :model-value="String(config.event ?? 'opened')"
                    :options="catalog.waitEvents.map((value) => ({ value, label: t(`journeyCanvas.events.${value}`) }))"
                    data-testid="cfg-wait-event"
                    @update:model-value="set('event', String($event))"
                />
            </VtField>

            <div class="vm-jcfg__row">
                <VtField :label="config.mode === 'event' ? t('journeyCanvas.config.timeout') : t('journeyCanvas.config.duration')">
                    <VtInput
                        type="number"
                        :model-value="duration(config.mode === 'event' ? 'timeout_minutes' : 'minutes').amount"
                        data-testid="cfg-wait-amount"
                        @update:model-value="setDuration(config.mode === 'event' ? 'timeout_minutes' : 'minutes', Number($event), duration(config.mode === 'event' ? 'timeout_minutes' : 'minutes').unit)"
                    />
                </VtField>
                <VtField :label="t('journeyCanvas.config.unit')">
                    <VtSelect
                        :model-value="duration(config.mode === 'event' ? 'timeout_minutes' : 'minutes').unit"
                        :options="unitOptions"
                        data-testid="cfg-wait-unit"
                        @update:model-value="setDuration(config.mode === 'event' ? 'timeout_minutes' : 'minutes', duration(config.mode === 'event' ? 'timeout_minutes' : 'minutes').amount, String($event) as 'minutes' | 'hours' | 'days')"
                    />
                </VtField>
            </div>
        </template>

        <ConditionFields v-if="node.type === 'condition'" :model-value="config" :types="catalog.conditionTypes" :options="options" testid="cfg-cond" @update:model-value="emit('config', $event)" />

        <template v-if="node.type === 'split'">
            <section v-for="(branch, index) in branches(config)" :key="index" class="vm-jcfg__box" :data-testid="`cfg-branch-${index}`">
                <div class="vm-jcfg__row">
                    <VtField :label="t('journeyCanvas.config.branchName')">
                        <VtInput :model-value="String(branch.label ?? '')" :placeholder="String(branch.key ?? '')" @update:model-value="setBranch(index, { label: $event })" />
                    </VtField>
                    <VtButton variant="ghost" size="sm" :aria-label="t('journeyCanvas.config.removeBranch')" @click="removeBranch(index)"><VmIcon name="trash" /></VtButton>
                </div>
                <ConditionFields :model-value="branch.condition ?? {}" :types="catalog.conditionTypes" :options="options" :testid="`cfg-branch-${index}`" @update:model-value="setBranch(index, { condition: $event })" />
            </section>
            <VtButton variant="ghost" size="sm" data-testid="cfg-add-branch" @click="addBranch"><VmIcon name="plus" /> {{ t('journeyCanvas.config.addBranch') }}</VtButton>
        </template>

        <template v-if="node.type === 'ab_split'">
            <div v-for="(variant, index) in variants(config)" :key="index" class="vm-jcfg__row">
                <VtField :label="t('journeyCanvas.handles.variant', { key: String(variant.key ?? '').toUpperCase() })">
                    <VtInput type="number" :model-value="Number(variant.weight ?? 0)" :data-testid="`cfg-weight-${index}`" @update:model-value="setVariant(index, Number($event))" />
                </VtField>
                <VtButton v-if="variants(config).length > 2" variant="ghost" size="sm" :aria-label="t('journeyCanvas.config.removeVariant')" @click="removeVariant(index)"><VmIcon name="trash" /></VtButton>
            </div>
            <p class="vm-jcfg__note" :class="{ 'vm-jcfg__note--bad': weightTotal !== 100 }" data-testid="cfg-weight-total">{{ t('journeyCanvas.config.weightTotal', { total: weightTotal }) }}</p>
            <VtButton v-if="variants(config).length < 5" variant="ghost" size="sm" @click="addVariant"><VmIcon name="plus" /> {{ t('journeyCanvas.config.addVariant') }}</VtButton>
        </template>

        <template v-if="node.type === 'update_contact'">
            <VtField :label="t('journeyCanvas.config.operation')">
                <VtSelect
                    :model-value="String(config.operation ?? 'add_tag')"
                    :options="['add_tag', 'remove_tag', 'set_field'].map((value) => ({ value, label: t(`journeyCanvas.operations.${value}`) }))"
                    data-testid="cfg-operation"
                    @update:model-value="emit('config', { operation: String($event) })"
                />
            </VtField>
            <VtField v-if="config.operation === 'set_field'" :label="t('journeyCanvas.config.field')">
                <VtSelect :model-value="String(config.field ?? '')" :options="options.fields" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-field" @update:model-value="set('field', String($event))" />
            </VtField>
            <VtField v-if="config.operation === 'set_field'" :label="t('journeyCanvas.config.value')">
                <VtInput :model-value="String(config.value ?? '')" data-testid="cfg-value" @update:model-value="set('value', $event)" />
            </VtField>
            <VtField v-else :label="t('journeyCanvas.config.tag')">
                <VtSelect :model-value="String(config.tag ?? '')" :options="options.tags" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-tag" @update:model-value="set('tag', String($event))" />
            </VtField>
        </template>

        <template v-if="node.type === 'list_membership'">
            <VtField :label="t('journeyCanvas.config.operation')">
                <VtSelect
                    :model-value="String(config.operation ?? 'add')"
                    :options="['add', 'remove'].map((value) => ({ value, label: t(`journeyCanvas.operations.list_${value}`) }))"
                    data-testid="cfg-operation"
                    @update:model-value="set('operation', String($event))"
                />
            </VtField>
            <VtField :label="t('journeyCanvas.config.list')">
                <VtSelect :model-value="String(config.list ?? '')" :options="options.lists" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-list" @update:model-value="set('list', String($event))" />
            </VtField>
        </template>

        <VtField
            v-if="node.type === 'webhook'"
            :label="t('journeyCanvas.config.endpoint')"
            :hint="canChooseEndpoint ? t('journeyCanvas.config.endpointHint') : t('journeyCanvas.config.endpointLocked')"
        >
            <VtSelect :model-value="String(config.endpoint ?? '')" :disabled="!canChooseEndpoint" :options="options.endpoints" :placeholder="t('journeyCanvas.config.choose')" data-testid="cfg-endpoint" @update:model-value="set('endpoint', String($event))" />
        </VtField>

        <template v-if="node.type === 'goal'">
            <p class="vm-jcfg__note">{{ t('journeyCanvas.config.goalHint') }}</p>
            <ConditionFields
                :model-value="(config.goal as Config | undefined) ?? {}"
                :types="catalog.goalTypes"
                :options="options"
                allow-none
                testid="cfg-goal"
                @update:model-value="emit('config', Object.keys($event).length ? { goal: $event } : {})"
            />
        </template>

        <p v-if="node.type === 'exit'" class="vm-jcfg__note">{{ t('journeyCanvas.config.exitHint') }}</p>

        <!-- KIMENETEK -->
        <section v-if="!isTerminal(node.type) && outputList.length" class="vm-jcfg__outputs" data-testid="cfg-outputs">
            <h3 class="vm-jcfg__section">{{ trigger ? t('journeyCanvas.config.firstStep') : t('journeyCanvas.config.outputs') }}</h3>
            <VtField v-for="handle in outputList" :key="handle" :label="outputLabel(handle)">
                <VtSelect
                    :model-value="connections[handle] ?? ''"
                    :options="targetOptions"
                    :data-testid="`cfg-connect-${handle}`"
                    @update:model-value="emit('connect', handle, String($event) || null)"
                />
            </VtField>
        </section>

        <div class="vm-jcfg__actions">
            <VtButton v-if="!trigger" variant="ghost" size="sm" data-testid="node-duplicate" @click="emit('duplicate')"><VmIcon name="copy" /> {{ t('journeyCanvas.config.duplicate') }}</VtButton>
            <VtButton variant="ghost" size="sm" data-testid="node-delete" @click="emit('remove')"><VmIcon name="trash" /> {{ t('journeyCanvas.config.delete') }}</VtButton>
        </div>
    </div>
</template>
