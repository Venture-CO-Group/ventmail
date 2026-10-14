<script setup lang="ts">
/*
 | WORKSPACE-VÁLTÓ: legördülő keresővel, a szerepköröddel az adott workspace-ben, és „Új
 | workspace" menüponttal (inline létrehozó űrlap).
 |
 | A lista kizárólag a SAJÁT tagságokat tartalmazza (a szerver szűri) — a váltó maga is
 | jogosultsági felület. A váltás POST (`/workspace/switch`), a létrehozás POST
 | (`/workspaces`): a szerver ellenőriz, a kliens csak kér.
 */
import { router, usePage } from '@inertiajs/vue3';
import { VtAvatar, VtBadge, VtButton, VtDropdown, VtInput } from '@venture/ui';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { asTrigger, useShellMenu } from '../../composables/useMenuState';
import VmIcon from './VmIcon.vue';

interface WorkspaceRow {
    uuid: string;
    name: string;
    roles?: string[];
}

defineProps<{ compact?: boolean }>();

const page = usePage<{
    workspace: { uuid: string; name: string } | null;
    workspaces: WorkspaceRow[];
    errors: Record<string, string>;
}>();
const { t } = useI18n({ useScope: 'global' });

const menu = useShellMenu('workspace');
const search = ref('');
// A `VtInput` gyökéreleme maga az `<input>` — a komponens-ref `$el`-je a mező.
const searchField = ref<{ $el: HTMLElement } | null>(null);
const creating = ref(false);
const newName = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

const ROLE_ORDER = ['owner', 'admin', 'developer', 'editor', 'author', 'analyst'];

// A legerősebb szerep — a lista egy sorban csak egyet mutat, a teljes készlet a tagok oldalon van.
function primaryRole(row: WorkspaceRow): string | null {
    const roles = row.roles ?? [];

    return ROLE_ORDER.find((role) => roles.includes(role)) ?? roles[0] ?? null;
}

function roleLabel(role: string | null): string {
    if (role === null) return '';

    return ROLE_ORDER.includes(role) ? t(`shell.role.${role}`) : role;
}

const workspaces = computed(() => page.props.workspaces ?? []);
const current = computed(() => page.props.workspace);

const filtered = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();

    return needle === ''
        ? workspaces.value
        : workspaces.value.filter((row) => row.name.toLocaleLowerCase().includes(needle));
});

function onOpen(): void {
    search.value = '';
    creating.value = false;
    error.value = null;
    void nextTick(() => searchField.value?.$el.focus());
}

function choose(row: WorkspaceRow, close: (returnFocus?: boolean) => void): void {
    close(true);

    if (row.uuid === current.value?.uuid) return;

    router.post('/workspace/switch', { workspace: row.uuid }, { preserveScroll: true });
}

function startCreate(): void {
    creating.value = true;
    error.value = null;
}

function create(): void {
    const name = newName.value.trim();

    if (name === '' || submitting.value) return;

    submitting.value = true;
    error.value = null;

    router.post(
        '/workspaces',
        { name },
        {
            onSuccess: () => {
                newName.value = '';
                creating.value = false;
                menu.setOpen(false);
            },
            onError: (errors) => {
                error.value = errors.name ?? Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}
</script>

<template>
    <VtDropdown class="vm-switcher-dd" :label="t('shell.workspaceSwitcher')" :open="menu.open.value" @update:open="menu.setOpen" @open="onOpen">
        <template #trigger="{ open, ...trigger }">
            <button type="button" class="vm-chip" v-bind="asTrigger(trigger)" data-testid="workspace-switch" :data-open="open ? 'true' : undefined">
                <VtAvatar :name="current?.name ?? ''" size="xs" class="vm-chip__avatar" />
                <span v-if="!compact" class="vm-chip__label" data-testid="workspace-current">{{ current?.name }}</span>
                <VmIcon v-if="!compact" name="chevron" class="vm-chip__chevron" />
            </button>
        </template>

        <template #default="{ close }">
          <div class="vm-switcher">
            <div class="vm-switcher__search">
                <VmIcon name="search" />
                <VtInput
                    ref="searchField"
                    v-model="search"
                    data-testid="workspace-search"
                    :aria-label="t('shell.workspaceSearch')"
                    :placeholder="t('shell.workspaceSearch')"
                    autocomplete="off"
                />
            </div>

            <p class="vm-switcher__heading vt-eyebrow">{{ t('shell.workspaceList', { count: workspaces.length }) }}</p>

            <ul class="vm-switcher__list" role="list" data-testid="workspace-list">
                <li v-for="row in filtered" :key="row.uuid">
                    <button
                        type="button"
                        class="vm-switcher__row"
                        :data-testid="`workspace-option-${row.uuid}`"
                        :aria-current="row.uuid === current?.uuid ? 'true' : undefined"
                        @click="choose(row, close)"
                    >
                        <VtAvatar :name="row.name" size="sm" />
                        <span class="vm-switcher__name">{{ row.name }}</span>
                        <VmIcon v-if="row.uuid === current?.uuid" name="check" class="vm-switcher__check" data-testid="workspace-current-check" />
                        <VtBadge :tone="primaryRole(row) === 'owner' ? 'accent' : 'neutral'" size="sm">
                            {{ roleLabel(primaryRole(row)) }}
                        </VtBadge>
                    </button>
                </li>
                <li v-if="filtered.length === 0" class="vm-switcher__empty">{{ t('shell.workspaceNoMatch') }}</li>
            </ul>

            <div class="vm-switcher__footer">
                <button v-if="!creating" type="button" class="vm-switcher__new" data-testid="workspace-new" @click="startCreate">
                    <VmIcon name="plus" />
                    {{ t('shell.workspaceNew') }}
                </button>

                <form v-else class="vm-switcher__create" data-testid="workspace-new-form" @submit.prevent="create">
                    <label class="vm-switcher__create-label" for="workspace-new-name">{{ t('shell.workspaceNameLabel') }}</label>
                    <VtInput
                        id="workspace-new-name"
                        v-model="newName"
                        data-testid="workspace-new-name"
                        maxlength="120"
                        autocomplete="off"
                    />
                    <p v-if="error" class="vm-switcher__error" role="alert" data-testid="workspace-new-error">{{ error }}</p>
                    <div class="vm-cluster">
                        <VtButton type="submit" :disabled="submitting || newName.trim() === ''" data-testid="workspace-new-submit">
                            {{ t('shell.workspaceCreate') }}
                        </VtButton>
                        <VtButton type="button" variant="ghost" @click="creating = false">{{ t('shell.cancel') }}</VtButton>
                    </div>
                </form>
            </div>
          </div>
        </template>
    </VtDropdown>
</template>
