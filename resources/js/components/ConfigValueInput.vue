<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import EnumSelect from './ui/EnumSelect.vue';
import type { ConfigKey } from '@/types/shared';

/** Input for one configuration key, by its declared type (Chapter 03 §3.2); the server validates. */

const props = withDefaults(defineProps<{ id: string; definition: ConfigKey; modelValue: unknown; disabled?: boolean }>(), { disabled: false });
const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>();
const d = computed(() => props.definition);

const jsonText = ref('');
const jsonError = ref<string | null>(null);
watch(() => props.modelValue, (v) => {
    if (d.value.type === 'json') {
        jsonText.value = v === null || v === undefined ? '' : JSON.stringify(v, null, 2);
    }
}, { immediate: true });

function commitJson() {
    try {
        emit('update:modelValue', jsonText.value.trim() === '' ? null : JSON.parse(jsonText.value));
        jsonError.value = null;
    } catch {
        jsonError.value = 'Not valid JSON.';
    }
}

const listText = computed({
    get: () => (Array.isArray(props.modelValue) ? props.modelValue.join('\n') : ''),
    set: (t: string) => emit('update:modelValue', t.split('\n').map((s) => s.trim()).filter(Boolean)),
});
const numeric = computed(() => ['integer', 'decimal', 'percentage', 'money', 'duration'].includes(d.value.type));

function onNumber(event: Event) {
    const text = (event.target as HTMLInputElement).value.trim();
    emit('update:modelValue', text === '' ? null : d.value.type === 'integer' || d.value.type === 'duration' ? Number.parseInt(text, 10) : text);
}
</script>

<template>
    <label v-if="d.type === 'boolean'" class="flex min-h-11 items-center gap-3">
        <input :id="id" type="checkbox" class="size-5 accent-brand" :checked="modelValue === true" :disabled="disabled"
               @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)">
        <span class="text-sm">{{ modelValue === true ? 'On' : 'Off' }}</span>
    </label>
    <EnumSelect v-else-if="d.type === 'enum' && d.enum" :id="id" :enum-code="d.enum" :values="d.enum_values ?? undefined" :model-value="modelValue as string | null"
                :disabled="disabled" @update:model-value="emit('update:modelValue', $event)" />
    <EnumSelect v-else-if="d.type === 'list' && d.constraints.items_enum_code" :id="id" :enum-code="d.constraints.items_enum_code"
                :values="d.constraints.items_enum" :model-value="(modelValue as string[] | null) ?? []" multiple :disabled="disabled"
                @update:model-value="emit('update:modelValue', $event)" />
    <textarea v-else-if="d.type === 'list'" :id="id" v-model="listText" class="input min-h-24" :disabled="disabled" placeholder="One per line" />
    <div v-else-if="d.type === 'json'">
        <textarea :id="id" v-model="jsonText" class="input min-h-28 font-mono text-sm" :disabled="disabled" spellcheck="false" @blur="commitJson" />
        <p v-if="jsonError" class="error-text">{{ jsonError }}</p>
    </div>
    <input v-else-if="d.type === 'secret'" :id="id" type="password" class="input" autocomplete="new-password" placeholder="Leave empty to keep the current secret"
           :disabled="disabled" @input="emit('update:modelValue', ($event.target as HTMLInputElement).value || undefined)">
    <input v-else-if="numeric" :id="id" class="input tabular-nums" :inputmode="d.type === 'integer' || d.type === 'duration' ? 'numeric' : 'decimal'"
           :value="(modelValue as string | number | null) ?? ''" :disabled="disabled" @input="onNumber">
    <input v-else :id="id" :type="d.type === 'time' ? 'time' : 'text'" class="input" :value="(modelValue as string | null) ?? ''" :disabled="disabled"
           @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)">
</template>
