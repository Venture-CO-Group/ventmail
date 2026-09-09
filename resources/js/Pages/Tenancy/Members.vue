<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtCheckbox, VtField, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * TAGOK, MEGHÍVÓK, SUB-ACCOUNTOK.
 *
 * A SZEREPEK JELÖLŐNÉGYZETEK, nem egy legördülő: egy tag TÖBB szerepet is kaphat (a
 * képességek uniója számít), és egy egyválasztós mező ezt elrejtené — a felhasználó azt
 * hinné, hogy a szerep-váltás elvesz, nem hozzáad.
 *
 * A KIOSZTHATÓ szerepek listáját a SZERVER adja: aki nem oszthat `owner`-t, annak fel sem
 * kínáljuk. A döntést nem ez a lista hozza — az `AssignWorkspaceRoles` minden kérésnél
 * újra ellenőrzi —, de a felhasználó ne egy hibaüzenetből tudja meg, mit nem tehet.
 */
interface MemberRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    lastLoginAt: string | null;
    self: boolean;
}

interface InvitationRow {
    uuid: string;
    email: string;
    roles: string[];
    expiresAt: string | null;
    expired: boolean;
}

const props = defineProps<{
    members: MemberRow[];
    invitations: InvitationRow[];
    assignableRoles: string[];
    subAccounts: {
        canManage: boolean;
        isChild: boolean;
        parent: { name: string } | null;
        children: Array<{ uuid: string; name: string; plan: string; members: number }>;
    };
}>();

const { t } = useI18n();

const inviteEmail = ref('');
const inviteRoles = ref<string[]>([]);
const subAccountName = ref('');

// A SZERKESZTÉS soronként nyílik: egy globális „mentés" gomb mellett nem lenne látható,
// melyik sor változott — és egy félrekattintás csendben átírná valaki más szerepeit.
const editing = ref<number | null>(null);
const editRoles = ref<string[]>([]);

type Row = { name: string; email: string; roles: string; lastLogin: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('members.name') },
    { key: 'email', label: t('members.email'), mono: true },
    { key: 'roles', label: t('members.roles') },
    { key: 'lastLogin', label: t('members.lastLogin') },
];

const rows = computed<Row[]>(() =>
    props.members.map((member) => ({
        name: member.name,
        email: member.email,
        roles: member.roles.join(', '),
        lastLogin: member.lastLoginAt ? new Date(member.lastLoginAt).toLocaleString() : '—',
    })),
);

function toggle(list: string[], role: string): string[] {
    return list.includes(role) ? list.filter((item) => item !== role) : [...list, role];
}

function startEdit(member: MemberRow): void {
    editing.value = member.id;
    editRoles.value = [...member.roles];
}

function saveRoles(member: MemberRow): void {
    router.post(
        `/members/${member.id}/roles`,
        { roles: editRoles.value },
        { preserveScroll: true, onSuccess: () => (editing.value = null) },
    );
}

function remove(member: MemberRow): void {
    router.delete(`/members/${member.id}`, { preserveScroll: true });
}

function invite(): void {
    router.post(
        '/members/invitations',
        { email: inviteEmail.value.trim(), roles: inviteRoles.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                inviteEmail.value = '';
                inviteRoles.value = [];
            },
        },
    );
}

function revoke(invitation: InvitationRow): void {
    router.delete(`/members/invitations/${invitation.uuid}`, { preserveScroll: true });
}

function createSubAccount(): void {
    router.post(
        '/sub-accounts',
        { name: subAccountName.value.trim() },
        { preserveScroll: true, onSuccess: () => (subAccountName.value = '') },
    );
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('members.title') }}</h1>

        <p class="vm-banner" role="status">{{ t('members.note') }}</p>

        <VtCard data-testid="members-card">
            <h2>{{ t('members.heading') }}</h2>

            <VtTable :columns="columns" :rows="rows" :caption="t('members.heading')" data-testid="members-table" />

            <div v-for="member in members" :key="member.id" class="vm-stack">
                <div class="vm-cluster">
                    <span class="vt-text-mono">{{ member.email }}</span>

                    <VtButton variant="ghost" :data-testid="`edit-${member.id}`" @click="startEdit(member)">
                        {{ t('members.editRoles') }}
                    </VtButton>

                    <!-- A SAJÁT SOR törlő gombja szándékosan hiányzik: aki kizárja magát,
                         annak nincs visszaútja a saját felületén. -->
                    <VtButton
                        v-if="!member.self"
                        variant="ghost"
                        :data-testid="`remove-${member.id}`"
                        @click="remove(member)"
                    >
                        {{ t('members.remove') }}
                    </VtButton>
                </div>

                <div v-if="editing === member.id" class="vm-cluster" :data-testid="`roles-${member.id}`">
                    <VtCheckbox
                        v-for="role in assignableRoles"
                        :key="role"
                        :model-value="editRoles.includes(role)"
                        :data-testid="`role-${member.id}-${role}`"
                        @update:model-value="editRoles = toggle(editRoles, role)"
                    >
                        {{ role }}
                    </VtCheckbox>

                    <VtButton
                        :disabled="!editRoles.length"
                        :data-testid="`save-${member.id}`"
                        @click="saveRoles(member)"
                    >
                        {{ t('members.save') }}
                    </VtButton>
                </div>
            </div>
        </VtCard>

        <VtCard data-testid="invite-card">
            <h2>{{ t('members.invite') }}</h2>

            <p>{{ t('members.inviteHint') }}</p>

            <VtField :label="t('members.email')">
                <VtInput v-model="inviteEmail" data-testid="invite-email" placeholder="kollega@pelda.hu" />
            </VtField>

            <div class="vm-cluster">
                <VtCheckbox
                    v-for="role in assignableRoles"
                    :key="role"
                    :model-value="inviteRoles.includes(role)"
                    :data-testid="`invite-role-${role}`"
                    @update:model-value="inviteRoles = toggle(inviteRoles, role)"
                >
                    {{ role }}
                </VtCheckbox>
            </div>

            <VtButton
                :disabled="!inviteEmail.trim() || !inviteRoles.length"
                data-testid="invite-submit"
                @click="invite"
            >
                {{ t('members.inviteAction') }}
            </VtButton>

            <p v-if="!invitations.length" data-testid="invitations-empty">{{ t('members.noInvitations') }}</p>

            <div v-for="invitation in invitations" :key="invitation.uuid" class="vm-cluster">
                <span class="vt-text-mono">{{ invitation.email }}</span>
                <span>{{ invitation.roles.join(', ') }}</span>
                <span v-if="invitation.expired" :data-testid="`expired-${invitation.uuid}`">
                    {{ t('members.expired') }}
                </span>
                <span v-else>{{ invitation.expiresAt ? new Date(invitation.expiresAt).toLocaleDateString() : '—' }}</span>

                <VtButton variant="ghost" :data-testid="`revoke-${invitation.uuid}`" @click="revoke(invitation)">
                    {{ t('members.revoke') }}
                </VtButton>
            </div>
        </VtCard>

        <VtCard data-testid="sub-accounts-card">
            <h2>{{ t('members.subAccounts') }}</h2>

            <p>{{ t('members.subAccountsHint') }}</p>

            <p v-if="subAccounts.parent" class="vm-banner" data-testid="sub-account-parent">
                {{ t('members.parentIs', { name: subAccounts.parent.name }) }}
            </p>

            <p v-if="!subAccounts.children.length" data-testid="sub-accounts-empty">
                {{ t('members.noSubAccounts') }}
            </p>

            <div v-for="child in subAccounts.children" :key="child.uuid" class="vm-cluster">
                <span>{{ child.name }}</span>
                <span class="vt-text-mono">{{ child.plan }}</span>
                <span>{{ t('members.memberCount', { count: child.members }) }}</span>
            </div>

            <template v-if="subAccounts.canManage && !subAccounts.isChild">
                <VtField :label="t('members.subAccountName')">
                    <VtInput v-model="subAccountName" data-testid="sub-account-name" />
                </VtField>

                <VtButton
                    :disabled="!subAccountName.trim()"
                    data-testid="sub-account-submit"
                    @click="createSubAccount"
                >
                    {{ t('members.createSubAccount') }}
                </VtButton>
            </template>

            <!-- Az EGYSZINTŰ hierarchia nem hiba, hanem szabály — a felület mondja meg,
                 miért nincs gomb. -->
            <p v-else-if="subAccounts.isChild" data-testid="sub-account-locked">
                {{ t('members.subAccountLocked') }}
            </p>
        </VtCard>
    </AppLayout>
</template>
