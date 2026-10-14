<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtButton, VtField, VtFileUpload, VtInput, VtTextarea } from '@venture/ui';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { isHexColor, verdict, type ContrastVerdict } from '../../Components/Analytics/brandContrast';
import VmCoverPreview from '../../Components/Analytics/VmCoverPreview.vue';
import { darkBackground, tokenResolver } from '../../Components/Charts/chartTheme';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';
import { uploadLabels } from '../../composables/uploadLabels';

/**
 * WORKSPACE-BRANDING (v1.1 · 8. modul, D233, D236) — Settings ▾ Branding.
 *
 * A PDF-riport borítója és lábléce ezt használja; üres mező = Venture-alapértelmezés. A
 * kontraszt-jelzés élő TIPP (tokenek számított értékéből); a döntés a szerveré (`BrandColor`).
 * A logó PNG/JPEG/WebP (SVG nem), a szerver újrakódolja metaadat nélkül.
 */
const props = defineProps<{
    branding: { primaryColor: string | null; companyName: string | null; footerText: string | null; logo: { width: number; height: number; size: number } | null };
    preview: { color: string | null; coverText: 'light' | 'dark' | 'default'; companyName: string; footerText: string; logoUrl: string | null };
    workspaceName: string;
    limits: { maxBytes: number; minPx: number; maxPx: number; maxRatio: number };
}>();

const page = usePage<{ errors: Record<string, string> }>();
const { t } = useI18n();
const format = useFormat();

const form = reactive({
    primary_color: props.branding.primaryColor ?? '',
    company_name: props.branding.companyName ?? '',
    footer_text: props.branding.footerText ?? '',
});

const saving = ref(false);
const uploading = ref(false);
const errors = computed(() => page.props.errors ?? {});

// A swatch-ok a Venture paletta TOKENJEI; az értékük a mount után olvasódik.
const SWATCH_TOKENS = ['--vt-color-accent-600', '--vt-color-violet-600', '--vt-color-cyan-700', '--vt-color-neutral-900', '--vt-color-accent-300'];
const swatches = ref<Array<{ token: string; hex: string }>>([]);
const refs = reactive({ light: '', dark: '', surface: '' });

onMounted(() => {
    const read = tokenResolver();
    swatches.value = SWATCH_TOKENS.map((token) => ({ token, hex: read(token).toUpperCase() })).filter((s) => isHexColor(s.hex));
    refs.light = read('--vt-color-neutral-0');
    refs.dark = read('--vt-color-neutral-900');
    refs.surface = darkBackground();
});

const color = computed(() => (isHexColor(form.primary_color) ? form.primary_color.toUpperCase() : null));
const contrast = computed<ContrastVerdict | null>(() => (color.value && refs.light ? verdict(color.value, refs.light, refs.dark, refs.surface) : null));
const coverText = computed(() => (color.value ? (contrast.value?.text ?? 'dark') : 'default'));

function save(): void {
    saving.value = true;
    router.put('/settings/branding', { ...form }, { preserveScroll: true, onFinish: () => (saving.value = false) });
}

/*
 | LOGÓ-FELTÖLTÉS — @venture/ui `VtFileUpload` (0.3.0, D317): típus (PNG/JPEG/WebP) és méret
 | (a szerver `limits.maxBytes`-a) a kiválasztáskor; a képméretet és a tartalmat a szerver
 | ellenőrzi. A választás rögtön feltölt; utána a mező `null`-ra áll (a logó az előnézetben él).
 */
const logoFile = ref<File | null>(null);
const logoLabels = computed(() => uploadLabels(t, 'PNG, JPEG, WebP', props.limits.maxBytes));

function upload(file: File | null): void {
    logoFile.value = file;

    if (!file) return;

    uploading.value = true;
    router.post('/settings/branding/logo', { logo: file }, { forceFormData: true, preserveScroll: true, onFinish: () => {
        uploading.value = false;
        logoFile.value = null;
    } });
}

function removeLogo(): void {
    router.delete('/settings/branding/logo', { preserveScroll: true });
}

const maxMb = computed(() => Math.round(props.limits.maxBytes / 1024 / 1024));
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('branding.eyebrow')" :title="t('branding.title')" :lead="t('branding.lead')" testid="branding-head">
            <VtButton :loading="saving" :disabled="saving" data-testid="branding-save" @click="save"><VmIcon name="save" /> {{ t('branding.save') }}</VtButton>
        </VmPageHeader>

        <p v-if="Object.keys(errors).length" class="vm-banner vm-banner--danger" role="alert" data-testid="branding-errors">{{ t('branding.saveFailed') }}</p>

        <div class="vm-brand">
            <VmCard glass>
                <VmForm novalidate @submit.prevent="save">
                    <VmFormSection>
                        <VtField :label="t('branding.logo')" :hint="t('branding.logoHint', { max: maxMb, min: limits.minPx, maxPx: limits.maxPx })" :error="errors.logo">
                            <div class="vm-brand__logo">
                                <div class="vm-brand__logobox">
                                    <img v-if="preview.logoUrl" :src="preview.logoUrl" alt="" data-testid="branding-logo" />
                                    <span v-else aria-hidden="true">{{ (form.company_name || workspaceName).trim()[0]?.toUpperCase() ?? 'V' }}</span>
                                </div>
                                <div class="vm-brand__logoactions">
                                    <VtFileUpload
                                        :model-value="logoFile"
                                        accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                                        v-bind="logoLabels"
                                        :button-label="t('branding.upload')"
                                        :disabled="uploading"
                                        data-testid="branding-upload"
                                        @update:model-value="upload"
                                    />
                                    <p v-if="uploading" class="vm-brand__uploading" role="status" data-testid="branding-uploading">
                                        <span class="vm-brand__spinner" aria-hidden="true" />{{ t('branding.uploading') }}
                                    </p>
                                    <VtButton v-if="branding.logo" variant="ghost" :disabled="uploading" data-testid="branding-remove" @click="removeLogo">{{ t('branding.remove') }}</VtButton>
                                </div>
                            </div>
                        </VtField>

                        <VtField :label="t('branding.color')" :error="errors.primary_color">
                            <div class="vm-brand__colors">
                                <span class="vm-brand__colorbox" :style="color ? { background: color } : {}" aria-hidden="true" />
                                <div class="vm-brand__hex">
                                    <VtInput v-model="form.primary_color" placeholder="#RRGGBB" :aria-label="t('branding.colorHex')" maxlength="7" data-testid="branding-color" />
                                </div>
                                <div class="vm-brand__swatches" role="group" :aria-label="t('branding.color')">
                                    <button
                                        v-for="swatch in swatches"
                                        :key="swatch.token"
                                        type="button"
                                        class="vm-brand__swatch"
                                        :class="{ 'is-on': color === swatch.hex }"
                                        :style="{ background: `var(${swatch.token})` }"
                                        :aria-label="swatch.hex"
                                        :aria-pressed="color === swatch.hex"
                                        @click="form.primary_color = swatch.hex"
                                    />
                                    <button type="button" class="vm-linkbutton" data-testid="branding-color-reset" @click="form.primary_color = ''">{{ t('branding.reset') }}</button>
                                </div>
                            </div>
                            <p class="vm-brand__hint" data-testid="branding-contrast" aria-live="polite">
                                <template v-if="!color">{{ t('branding.colorDefaultHint') }}</template>
                                <template v-else-if="contrast && contrast.text">
                                    {{ t('branding.contrastOk', { ratio: format.number(contrast.ratio), text: t(contrast.text === 'light' ? 'branding.textLight' : 'branding.textDark') }) }}
                                    <span v-if="contrast.weakOnDark"> {{ t('branding.contrastWeak') }}</span>
                                </template>
                                <template v-else-if="contrast">{{ t('branding.contrastFail') }}</template>
                            </p>
                        </VtField>

                        <VtField :label="t('branding.footer')" :hint="t('branding.footerHint')" :error="errors.footer_text">
                            <VtTextarea v-model="form.footer_text" :rows="3" maxlength="300" data-testid="branding-footer" />
                        </VtField>

                        <VtField :label="t('branding.company')" :error="errors.company_name">
                            <VtInput v-model="form.company_name" :placeholder="workspaceName" maxlength="120" data-testid="branding-company" />
                        </VtField>
                    </VmFormSection>
                </VmForm>
            </VmCard>

            <VmCard glass>
                <h2 class="vm-brand__title">{{ t('branding.preview') }}</h2>
                <VmCoverPreview
                    :color="color"
                    :cover-text="coverText"
                    :company="form.company_name || workspaceName"
                    :footer="form.footer_text || form.company_name || workspaceName"
                    :logo-url="preview.logoUrl"
                    :title="t('analytics.report.coverCampaign')"
                    :subtitle="t('branding.previewSample')"
                    :date-range="`${format.day('2026-09-12')} – ${format.day('2026-10-12')}`"
                    :kpis="[
                        { label: t('analytics.kpi.recipients'), value: '3 812' },
                        { label: t('analytics.kpi.openRate'), value: '46,2%' },
                        { label: t('analytics.kpi.clickRate'), value: '9,8%' },
                    ]"
                    testid="branding-preview"
                />
            </VmCard>
        </div>
    </AppLayout>
</template>

<style scoped>
.vm-brand {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 3)), 1fr));
    gap: var(--vt-space-6);
    align-items: start;
    min-width: 0;
}

.vm-brand__title {
    margin: 0;
    font-family: var(--vt-font-display);
    font-size: var(--vt-fs-h5);
}

.vm-brand__logo {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-6);
}

.vm-brand__logobox {
    display: grid;
    place-items: center;
    width: var(--vt-space-24);
    height: var(--vt-space-24);
    border: thin dashed var(--vt-border-strong);
    border-radius: var(--vt-radius-lg);
    background: var(--vt-glass-thin-fill);
    font-family: var(--vt-font-display);
    font-size: var(--vt-fs-h3);
    overflow: hidden;
}

.vm-brand__logobox img {
    max-width: 80%;
    max-height: 80%;
    object-fit: contain;
}

.vm-brand__logoactions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-3);
}

.vm-brand__colors {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}

.vm-brand__colorbox {
    width: var(--vt-control-height);
    height: var(--vt-control-height);
    border-radius: var(--vt-radius-md);
    background: var(--vt-accent);
    border: thin solid var(--vt-border);
}

.vm-brand__hex {
    width: calc(var(--vt-space-32) + var(--vt-space-4));
}

.vm-brand__swatches {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-2);
}

.vm-brand__swatch {
    width: var(--vt-space-8);
    height: var(--vt-space-8);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    cursor: pointer;
}

.vm-brand__swatch.is-on,
.vm-brand__swatch:focus-visible {
    outline: none;
    box-shadow: var(--vt-ring);
}

.vm-brand__hint {
    margin: var(--vt-space-2) 0 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
}
.vm-brand__uploading {
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-2);
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

/* Feltöltés közben forgó jelző (D318) — tokenekből; csökkentett mozgásnál áll. */
.vm-brand__spinner {
    inline-size: var(--vt-space-4);
    block-size: var(--vt-space-4);
    border: thin solid var(--vt-border-strong);
    border-block-start-color: var(--vt-accent);
    border-radius: var(--vt-radius-full);
    animation: vm-brand-spin calc(var(--vt-duration-slow) * 2) linear infinite;
}

@keyframes vm-brand-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .vm-brand__spinner {
        animation: none;
    }
}
</style>
