<script setup lang="ts">
import { VtButton, VtCheckbox, VtField, VtInput, VtSelect } from '@venture/ui';
import axios, { AxiosError } from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/*
 | TESZT-KÜLDÉS a seed-címekre / a workspace tagjainak.
 |
 | A levél a szerveren a `SendMessage` actionön és a PreSendGate-en megy át (driver-hívás
 | nincs). A címzett CSAK a felkínált listából választható — szabad cím nincs. Minden
 | kattintás új `Idempotency-Key`-t kap (golden rule 5): a dupla kattintás egy levél.
 | Küldés előtt a szerkesztő ELMENTI az aktuális állapotot (`beforeSend`).
 */
const props = defineProps<{
    uuid: string;
    recipients: Array<{ email: string; kind: string }>;
    domains: string[];
    beforeSend: () => Promise<boolean>;
}>();

const { t, te } = useI18n();

const selected = ref<string[]>(props.recipients.slice(0, 1).map((recipient) => recipient.email));
const localPart = ref('hello');
const domain = ref(props.domains[0] ?? '');
const sending = ref(false);
const result = ref<{ ok: boolean; text: string } | null>(null);

const from = computed(() => `${localPart.value.trim()}@${domain.value}`);

function toggle(email: string, on: boolean): void {
    selected.value = on ? [...new Set([...selected.value, email])] : selected.value.filter((item) => item !== email);
}

/** Kattintásonként ÚJ kulcs; a `randomUUID` csak biztonságos kontextusban él. */
function idempotencyKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function reasonText(reason: string | null | undefined): string {
    const key = `editor.testSend.reasons.${reason ?? ''}`;

    return reason && te(key) ? t(key) : (reason ?? t('editor.testSend.failed'));
}

async function send(): Promise<void> {
    if (!selected.value.length || !domain.value) return;

    sending.value = true;
    result.value = null;

    try {
        if (!(await props.beforeSend())) {
            result.value = { ok: false, text: t('editor.testSend.saveFirst') };
            return;
        }

        const response = await axios.post(
            `/templates/${props.uuid}/test-send`,
            { recipients: selected.value, from_email: from.value },
            { headers: { 'Idempotency-Key': idempotencyKey() } },
        );

        result.value = { ok: true, text: t('editor.testSend.sent', { count: response.data.recipients.length }) };
    } catch (caught) {
        const failure = caught as AxiosError<{ reason?: string; message?: string; errors?: Record<string, string[]> }>;
        const data = failure.response?.data;

        result.value = {
            ok: false,
            text: data?.reason ? reasonText(data.reason) : (Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? failure.message),
        };
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div class="vm-ed-fields" data-testid="test-send">
        <p class="vm-ed-hint">{{ t('editor.testSend.hint') }}</p>

        <fieldset class="vm-stack">
            <legend class="vm-ed-heading">{{ t('editor.testSend.recipients') }}</legend>
            <p v-if="!recipients.length" class="vm-ed-hint">{{ t('editor.testSend.noRecipients') }}</p>
            <VtCheckbox
                v-for="recipient in recipients"
                :key="recipient.email"
                :model-value="selected.includes(recipient.email)"
                :data-testid="`test-recipient-${recipient.email}`"
                @update:model-value="toggle(recipient.email, Boolean($event))"
            >
                <code>{{ recipient.email }}</code> · {{ t(`editor.testSend.kinds.${recipient.kind}`) }}
            </VtCheckbox>
        </fieldset>

        <VtField :label="t('editor.testSend.from')" :hint="domains.length ? undefined : t('editor.testSend.noDomain')">
            <div class="vm-ed-grid2">
                <VtInput v-model="localPart" data-testid="test-from-local" />
                <VtSelect v-model="domain" :options="domains.map((name) => ({ value: name, label: `@${name}` }))" data-testid="test-from-domain" />
            </div>
        </VtField>

        <VtButton :loading="sending" :disabled="!selected.length || !domain" data-testid="test-send-submit" @click="send">
            {{ t('editor.testSend.submit') }}
        </VtButton>

        <p v-if="result" class="vm-banner" :class="result.ok ? '' : 'vm-banner--warning'" role="status" data-testid="test-send-result" :data-ok="result.ok ? 'true' : 'false'">
            {{ result.text }}
        </p>
    </div>
</template>
