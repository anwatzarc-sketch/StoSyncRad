<script setup lang="ts">
import { computed } from 'vue';
import ConfigValueInput from '@/components/ConfigValueInput.vue';
import { humanize } from '@/lib/meta';
import type { ConfigKey } from '@/types/shared';

/** Configuration keys owned by one setup step, grouped, with their help text and defaults. */
const props = defineProps<{ definitions: ConfigKey[]; modelValue: Record<string, unknown>; errors?: Record<string, string> }>();
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, unknown>] }>();

const groups = computed(() => {
    const out: Record<string, ConfigKey[]> = {};
    for (const d of props.definitions) {
        const section = d.key.split('.').slice(0, 2).join('.');
        (out[section] ??= []).push(d);
    }

    return out;
});

function set(key: string, value: unknown) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>

<template>
    <div class="space-y-6">
        <section v-for="(items, section) in groups" :key="section">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ humanize(String(section).replace('.', ' · ')) }}</h3>
            <div class="grid gap-4 md:grid-cols-2">
                <div v-for="d in items" :key="d.key" :class="['json', 'list'].includes(d.type) ? 'md:col-span-2' : ''">
                    <label :for="`cfg-${d.key}`" class="label">{{ d.label }}</label>
                    <ConfigValueInput :id="`cfg-${d.key}`" :definition="d" :model-value="modelValue[d.key]" @update:model-value="set(d.key, $event)" />
                    <p v-if="errors?.[`config.${d.key}`]" class="error-text" role="alert">{{ errors[`config.${d.key}`] }}</p>
                    <p v-else class="help">{{ d.help }}</p>
                </div>
            </div>
        </section>
    </div>
</template>
