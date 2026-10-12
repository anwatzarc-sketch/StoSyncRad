<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SchemaField from './SchemaField.vue';
import { loadSchema, type SchemaProperty } from '@/lib/meta';

/** Document lines editor generated from the line schema (nested `lines.n.field` errors inline). */
const props = withDefaults(defineProps<{
    label: string;
    schemaName: string;
    modelValue: Record<string, unknown>[];
    operation: 'create' | 'update';
    errors?: Record<string, string>;
    path: string;
    disabled?: boolean;
    required?: boolean;
}>(), { errors: () => ({}), disabled: false, required: false });

const emit = defineEmits<{ 'update:modelValue': [value: Record<string, unknown>[]] }>();
const properties = ref<Record<string, SchemaProperty>>({});
const loadError = ref<string | null>(null);

onMounted(async () => {
    try {
        properties.value = (await loadSchema(props.schemaName)).properties;
    } catch (e) {
        loadError.value = (e as Error).message;
    }
});

const visible = computed(() => Object.entries(properties.value).filter(([name, p]) => name !== 'id' && !p.server_controlled));

function update(index: number, name: string, value: unknown) {
    const rows = props.modelValue.map((r) => ({ ...r }));
    rows[index][name] = value;
    emit('update:modelValue', rows);
}

function add() {
    const row: Record<string, unknown> = {};
    for (const [name, p] of visible.value) {
        if (p.default !== undefined) {
            row[name] = p.default;
        }
    }
    emit('update:modelValue', [...props.modelValue, row]);
}

function remove(index: number) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
}
</script>

<template>
    <fieldset class="rounded-brand border border-line p-3">
        <legend class="px-1 text-sm font-semibold text-ink">
            {{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span>
        </legend>
        <p v-if="loadError" class="error-text">{{ loadError }}</p>
        <p v-if="errors[path]" class="error-text" role="alert">{{ errors[path] }}</p>
        <ol class="space-y-3">
            <li v-for="(row, i) in modelValue" :key="i" class="rounded-brand bg-surface-muted p-3">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-sm font-semibold text-ink-muted">Line {{ i + 1 }}</span>
                    <button v-if="!disabled" type="button" class="btn btn-ghost min-h-9 px-2 text-danger" :aria-label="`Remove line ${i + 1}`" @click="remove(i)">
                        Remove
                    </button>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <SchemaField
                        v-for="[name, p] in visible"
                        :key="name"
                        :name="name"
                        :property="p"
                        :model-value="row[name]"
                        :operation="operation"
                        :error="errors[`${path}.${i}.${name}`]"
                        :errors="errors"
                        :path="`${path}.${i}.${name}`"
                        @update:model-value="update(i, name, $event)"
                    />
                </div>
            </li>
        </ol>
        <button v-if="!disabled" type="button" class="btn btn-secondary mt-3" @click="add">+ Add line</button>
    </fieldset>
</template>
