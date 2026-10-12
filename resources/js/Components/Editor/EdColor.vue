<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/*
 | SZÍNVÁLASZTÓ színmintákkal. A minták a SZERVER katalógusából jönnek (a levél színei,
 | nem a felületé) — a felület nem tartalmaz beégetett színt.
 |
 | JELENTÉS: hiányzó @venture/ui komponens: színválasztó (`VtColorPicker`).
 */
const props = withDefaults(defineProps<{ modelValue?: string | null; swatches: string[]; label: string; allowNone?: boolean; testid?: string }>(), {
    modelValue: null,
    allowNone: false,
    testid: undefined,
});

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const { t } = useI18n();

function pick(value: string | null): void {
    emit('update:modelValue', value);
}
</script>

<template>
    <div class="vm-stack" :data-testid="testid">
        <span>{{ label }}</span>
        <div class="vm-ed-swatches" role="group" :aria-label="label">
            <button
                v-if="allowNone"
                type="button"
                class="vm-ed-swatch vm-ed-swatch--none"
                :aria-pressed="props.modelValue === null"
                :aria-label="t('editor.common.none')"
                :title="t('editor.common.none')"
                @click="pick(null)"
            />
            <button
                v-for="swatch in swatches"
                :key="swatch"
                type="button"
                class="vm-ed-swatch"
                :style="{ background: swatch }"
                :aria-pressed="props.modelValue?.toLowerCase() === swatch.toLowerCase()"
                :aria-label="swatch"
                :title="swatch"
                @click="pick(swatch)"
            />
        </div>
    </div>
</template>
