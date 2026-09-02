<script setup lang="ts">
import { VtButton, VtCard, VtGlowLine } from '@venture/ui';

defineProps<{ app: string; stack: string[] }>();
</script>

<template>
    <main>
        <VtGlowLine />

        <VtCard>
            <h1>{{ app }}</h1>
            <p>Skeleton él. A design forrása kizárólag a @venture/design token-készlet.</p>

            <ul>
                <li v-for="item in stack" :key="item">{{ item }}</li>
            </ul>

            <VtButton data-testid="smoke-button">Rendben</VtButton>
        </VtCard>
    </main>
</template>
