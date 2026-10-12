<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SchemaField from './SchemaField.vue';
import { loadEnums, loadSchema, type SchemaProperty } from '@/lib/meta';

/**
 * Whole form generated from a table schema (Chapter 12 §12.3). Server-controlled properties are
 * not editable; `only` / `exclude` narrow the fields shown.
 */
const props = withDefaults(defineProps<{
    schemaName: string;
    modelValue: Record<string, unknown>;
    operation: 'create' | 'update';
    errors?: Record<string, string>;
    state?: string | null;
    only?: string[];
    exclude?: string[];
}>(), { errors: () => ({}), state: null, only: undefined, exclude: () => [] });

const emit = defineEmits<{ 'update:modelValue': [value: Record<string, unknown>]; loaded: [properties: Record<string, SchemaProperty>] }>();
const properties = ref<Record<string, SchemaProperty>>({});
const loading = ref(true);
const loadError = ref<string | null>(null);

onMounted(async () => {
    try {
        await loadEnums();
        properties.value = (await loadSchema(props.schemaName)).properties;
        emit('loaded', properties.value);
    } catch (e) {
        loadError.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

const fields = computed(() => Object.entries(properties.value).filter(([name, p]) => !p.server_controlled && name !== 'id'
    && !(props.exclude ?? []).includes(name) && (!props.only || props.only.includes(name))));

function update(name: string, value: unknown) {
    emit('update:modelValue', { ...props.modelValue, [name]: value });
}
</script>

<template>
    <div>
        <div v-if="loading" class="space-y-3" aria-busy="true">
            <div v-for="n in 4" :key="n" class="h-14 animate-pulse rounded-brand bg-surface-muted" />
        </div>
        <p v-else-if="loadError" class="error-text">{{ loadError }}</p>
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <SchemaField
                v-for="[name, p] in fields"
                :key="name"
                :name="name"
                :property="p"
                :model-value="modelValue[name]"
                :operation="operation"
                :error="errors[name]"
                :errors="errors"
                :path="name"
                :state="state"
                @update:model-value="update(name, $event)"
            />
        </div>
    </div>
</template>
