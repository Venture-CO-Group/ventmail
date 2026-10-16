<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BrandLogo from './BrandLogo.vue';

/*
 | A vendég-oldalak (belépő, megtagadva) közös héja — a ventSSO belépő-kártyájának mása:
 | glow-keret, üveg-kártya, logó, lábléc (host + verzió) és a jogi linkek.
 | A jogi oldalak gazdája a ventSSO: a ventMail saját jogi szöveget nem tart.
 */
interface SharedProps {
    [key: string]: unknown;
    app: { version: string };
}

// Kötött `src`: a public/ mappából szolgált eszköz (a ventSSO brand-készletéből).
const ventureLogo = '/brand/venture-logo-light.svg';

const page = usePage<SharedProps>();
const { t } = useI18n({ useScope: 'global' });

const host = computed(() => (typeof window === 'undefined' ? '' : window.location.host));
const version = computed(() => page.props.app?.version ?? 'dev');
</script>

<template>
    <main class="auth" data-testid="auth-shell">
        <div class="vm-glow-rim auth__rim">
            <div class="vt-glass auth__card vm-glow-rim__card">
                <!-- A ventSSO-kártya mintájára: a Venture CO Group logó vezet, a termék mellette. -->
                <div class="auth__brand">
                    <a href="https://ventureco.app" rel="noopener" class="auth__venture">
                        <img :src="ventureLogo" alt="Venture CO Group" class="auth__venture-logo" />
                    </a>
                    <BrandLogo class="auth__product" />
                </div>
                <slot />
                <div class="auth__foot">
                    <span data-testid="auth-host">{{ host }}</span>
                    <span data-testid="auth-version">v{{ version }}</span>
                </div>
            </div>
        </div>

        <nav class="auth__legal" :aria-label="t('auth.legalNav')">
            <a href="https://sso.ventureco.app/terms" rel="noopener">{{ t('auth.terms') }}</a>
            <a href="https://sso.ventureco.app/privacy-policy" rel="noopener">{{ t('auth.privacy') }}</a>
        </nav>
    </main>
</template>
